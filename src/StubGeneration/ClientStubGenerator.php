<?php

namespace Nogrod\XMLClient\StubGeneration;

use Doctrine\Common\Inflector\Inflector;
use GoetasWebservices\XML\WSDLReader\Wsdl\Message\Part;
use GoetasWebservices\XML\WSDLReader\Wsdl\PortType;
use GoetasWebservices\Xsd\XsdToPhp\Naming\NamingStrategy;
use GoetasWebservices\Xsd\XsdToPhp\Php\PhpConverter;
use Nogrod\XMLClient\Client;
use Nogrod\XMLClient\StubGeneration\Tag\ParamTag;
use Laminas\Code\Generator\ClassGenerator;
use Laminas\Code\Generator\DocBlock\Tag\ReturnTag;
use Laminas\Code\Generator\DocBlockGenerator;
use Laminas\Code\Generator\MethodGenerator;
use Laminas\Code\Generator\ParameterGenerator;

class ClientStubGenerator
{
    /**
     * @var NamingStrategy
     */
    private $namingStrategy;

    /**
     * @var PhpConverter
     */
    private $phpConverter;

    public function __construct(PhpConverter $phpConverter, NamingStrategy $namingStrategy)
    {
        $this->namingStrategy = $namingStrategy;
        $this->phpConverter = $phpConverter;
    }

    public static function addJmsMethod(ClassGenerator $classGen, array $jmsPaths)
    {
        $body = 'return [' . PHP_EOL;
        $body .= implode(', ', array_map(
            function ($v, $k) {
                return sprintf("    '%s' => __DIR__.'/../../../%s'," . PHP_EOL, $k, $v);
            },
            $jmsPaths,
            array_keys($jmsPaths)
        ));
        $body .= '];';
        $method = new MethodGenerator('getJmsMetaPath');
        $method->setFlags(MethodGenerator::FLAG_PROTECTED);
        $method->setBody($body);
        $classGen->addMethodFromGenerator($method);
    }

    public static function addSabreMethod(ClassGenerator $classGen, string $classname)
    {
        $name = substr($classname, 0, -mb_strlen("BaseClient"));
        $body = [];
        $body[] = '$service = new \Sabre\Xml\Service();';
        $body[] = '$service->classMap = '.$name.'ClassMap::Get();';
        $body[] = '$service->elementMap = '.$name.'ClassMap::GetElements();';
        $body[] = '$service->namespaceMap = '.$name.'ClassMap::GetNamespaces();';
        $body[] = 'return $service;';
        $method = new MethodGenerator('getSabre');
        $method->setFlags(MethodGenerator::FLAG_PROTECTED);
        $method->setBody(implode(PHP_EOL, $body));
        $classGen->addMethodFromGenerator($method);
    }

    /**
     * @param PortType[] $ports
     * @param array $jmsPaths
     * @param string $classname
     * @return ClassGenerator[]
     */
    public function generate(array $ports, array $jmsPaths, string $classname)
    {
        $classes = [];
        foreach ($ports as $port) {
            $classGen = new ClassGenerator();
            if ($this->visitPortType($classGen, $port) !== false) {
                $classGen->setName(Inflector::classify(preg_replace('/interface$|serviceport(type)?$/i', '', $port->getName())) . 'BaseClient');
                $namespaces = $this->phpConverter->getNamespaces();
                $classGen->setNamespaceName($namespaces[$port->getDefinition()->getTargetNamespace()] . "\\Client");
                $classGen->setExtendedClass(Client::class);
                self::addJmsMethod($classGen, $jmsPaths);
                self::addSabreMethod($classGen, $classname);
                $classes[] = $classGen;
            }
        }

        return $classes;
    }

    private function visitPortType(ClassGenerator $classGen, PortType $portType)
    {
        if (!count($portType->getOperations())) {
            return false;
        }
        $docBlock = new DocBlockGenerator("Class representing " . $portType->getName());
        $docBlock->setWordWrap(false);
        if ($portType->getDocumentation()) {
            $docBlock->setLongDescription($portType->getDocumentation());
        }
        $classGen->setDocblock($docBlock);

        foreach ($portType->getOperations() as $operation) {
            $this->visitOperation($operation, $classGen);
        }
    }

    private function visitOperation(PortType\Operation $operation, ClassGenerator $classGen)
    {
        $methodName = Inflector::camelize($operation->getName());
        $method = new MethodGenerator($methodName);
        $returnType = $this->getOperationReturnType($operation);
        $method->setReturnType($returnType);
        $params = $this->getOperationParams($operation, $method);

        $docblock = new DocBlockGenerator();
        $docblock->setWordWrap(false);
        $docblock->setShortDescription("Call " . $operation->getName());
        if (!empty($operation->getDocumentation())) {
            $docblock->setLongDescription(preg_replace("/[\n\r]+/", " ", $operation->getDocumentation()));
        }
        $docblock->setTags($params);
        $return = new ReturnTag();
        $return->setTypes($returnType);
        $docblock->setTag($return);

        $method->setDocBlock($docblock);
        $paramNames = array_map(function ($value) {
            return '$' . $value->getVariableName();
        }, $params);
        $method->setBody("return \$this->call('" . $operation->getName() . "', '" . mb_substr($returnType, 1) . "', " . implode(', ', $paramNames) . ");");
        $classGen->addMethodFromGenerator($method);
    }

    private function getOperationParams(PortType\Operation $operation, MethodGenerator $method)
    {
        if (!$operation->getInput()) {
            return [];
        }

        $parts = $operation->getInput()->getMessage()->getParts();
        if (!$parts) {
            return [];
        }

        return $this->extractSinglePartParameters(reset($parts), $method);
    }

    private function getOperationReturnType(PortType\Operation $operation)
    {
        if (!$operation->getOutput() || !$operation->getOutput()->getMessage()->getParts()) {
            return 'void';
        }
        $parts = $operation->getOutput()->getMessage()->getParts();
        if (count($parts) > 1) {
            return 'array';
        }
        /**
         * @var $part \GoetasWebservices\XML\WSDLReader\Wsdl\Message\Part
         */
        $part = reset($parts);

        return $this->getClassFromPart($part);
    }

    private function getClassFromPart(Part $part)
    {
        if ($part->getType()) {
            $class = $this->phpConverter->visitType($part->getType());
        } else {
            $class = $this->phpConverter->visitElementDef($part->getElement());
        }
        if ($t = $class->isSimpleType()) {
            return $t->getType()->getPhpType();
        }
        return $class->getPhpType();
    }

    /**
     * @param Part $part
     * @param MethodGenerator $method
     * @return array
     */
    private function extractSinglePartParameters(Part $part, MethodGenerator $method)
    {
        $paramName = $this->namingStrategy->getPropertyName($part);
        $paramType = $this->getClassFromPart($part);
        $param = new ParameterGenerator($paramName);
        $param->setType($paramType);
        $method->setParameter($param);
        return [
            new ParamTag($paramName, [$paramType])
        ];
    }
}

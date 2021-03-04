<?php

namespace Nogrod\XMLClient\Command;

use GoetasWebservices\XML\XSDReader\Schema\Element\Element;
use GoetasWebservices\XML\XSDReader\Schema\Schema;
use GoetasWebservices\XML\XSDReader\Schema\Type\ComplexType;
use Laminas\Code\Generator\ClassGenerator;
use Nogrod\XMLClient\Builder\XMLContainerBuilder;
use Nogrod\XMLClient\StubGeneration\ClientStubGenerator;
use Nogrod\XMLClientRuntime\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;

class Generate extends Command
{
    protected function configure()
    {
        parent::configure();
        $this->setName('generate');
        $this->setDescription("Convert create all the necessary PHP classes for a XML client");
        $this->setDefinition([
            new InputArgument('config', InputArgument::OPTIONAL, 'Config file location', 'config.yaml'),
            new InputOption('no-sabre', null, InputOption::VALUE_OPTIONAL, 'Use Sabre?',false)
        ]);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $logger = new ConsoleLogger($output);
        $configArg = $input->getArgument('config');
        $noSabre = ($input->getOption('no-sabre') !== false);
        if (is_dir($configArg)) {
            $configs = glob($configArg.DIRECTORY_SEPARATOR.'*.yaml');
        } else {
            $configs = [$configArg];
        }

        foreach ($configs as $configFile) {
            $containerBuilder = new XMLContainerBuilder($configFile, $logger);
            $debugContainer = $containerBuilder->getDebugContainer();

            $config = $debugContainer->getParameter('nogrod.xml_client.config');

            /** @var $schemas \GoetasWebservices\XML\XSDReader\Schema\Schema[] */
            $schemas = [];
            $portTypes = [];
            $wsdlReader = $debugContainer->get('goetas_webservices.wsdl2php.wsdl_reader');
            $xsdReader = $debugContainer->get('goetas_webservices.xsd2php.schema_reader');

            foreach (array_keys($config['metadata']) as $src) {
                $ext = pathinfo($src, PATHINFO_EXTENSION);
                switch ($ext) {
                    case 'wsdl':
                        $definitions = $wsdlReader->readFile($src);
                        $schemas[] = $definitions->getSchema();
                        $portTypes = array_merge($portTypes, $definitions->getAllPortTypes());
                        break;
                    case 'xsd':
                        $schemas[] = $xsdReader->readFile($src);
                        break;
                    default:
                        break;
                }
            }

            foreach ($config['patch_fields'] as $type => $data) {
                $patchType = $this->findType($schemas, $type);
                if ($patchType === null) {
                    $logger->info('PatchType ' . $type . ' not found!');
                    continue;
                }
                foreach ($data as $field => $fieldType) {
                    $patchFieldType = $this->findType($schemas, $fieldType);
                    if (!($patchFieldType instanceof ComplexType)) {
                        $logger->info('PatchFieldType ' . $fieldType . ' for ' . $type . ' not found!');
                        continue;
                    }
                    $element = new Element($patchType->getSchema(), $field);
                    $element->setType($patchFieldType);
                    $patchType->addElement($element);
                }
            }

            $classWriter = $debugContainer->get('goetas_webservices.xsd2php.class_writer.php');
            $classWriter->setLogger($logger);

            $converter = $debugContainer->get('goetas_webservices.xsd2php.converter.jms');
            $converter->setUseCdata($config['configs_jms']['xml_cdata']);
            $converter->setLogger($logger);
            $jmsItems = $converter->convert($schemas);

            $writer = $debugContainer->get('goetas_webservices.xsd2php.writer.jms');
            $writer->setLogger($logger);
            $writer->write($jmsItems, $noSabre);

            if (!$noSabre) {
                $writer = $debugContainer->get('goetas_webservices.xsd2php.writer.sabre');
                $writer->setLogger($logger);
                $writer->setConfig($config);
                $writer->write($jmsItems);
            }

            $converter = $debugContainer->get('goetas_webservices.xsd2php.converter.php');
            $converter->setLogger($logger);
            $items = $converter->convert($schemas);

            foreach ($items as $key => $item) {
                if (!isset($jmsItems[$key])) {
                    continue;
                }
                $item->setMeta($jmsItems[$key]);
            }

            $writer = $debugContainer->get('goetas_webservices.xsd2php.writer.php');
            $writer->setLogger($logger);
            $writer->write($items, $noSabre);

            $destinations_php = $config['destinations_php'];
            $jmsPaths = $config['destinations_jms'];
            $classname = basename($destinations_php[array_key_first($destinations_php)]) . 'BaseClient';
            if (count($portTypes) > 0) {
                /**
                 * @var $clientStubGenerator ClientStubGenerator
                 */
                $clientStubGenerator = $debugContainer->get('nogrod.xml_client.stub.client_generator');
                $classDefinitions = $clientStubGenerator->generate($portTypes, $jmsPaths, $classname, $noSabre);
                $classWriter->write($classDefinitions);
            } else {
                $classGen = new ClassGenerator();
                $classGen->setName($classname);
                $classGen->setNamespaceName(array_key_first($jmsPaths) . "\\Client");
                $classGen->setExtendedClass(Client::class);
                ClientStubGenerator::addJmsMethod($classGen, $jmsPaths);
                if (!$noSabre) ClientStubGenerator::addSabreMethod($classGen, $classname);
                $classWriter->write([$classGen]);
            }
        }

        return 0;
    }

    /**
     * @param $schemas
     * @param $type
     *
     * @return \GoetasWebservices\XML\XSDReader\Schema\Type\Type|null
     */
    private function findType($schemas, $type)
    {
        $visited = [];
        foreach ($schemas as $schema) {
            $schemaType = $this->findTypeInSchema($schema, $type, $visited);
            if ($schemaType !== null) {
                return $schemaType;
            }
        }

        return null;
    }

    /**
     * @param Schema $schema
     * @param $type
     * @param array $visited
     * @return \GoetasWebservices\XML\XSDReader\Schema\Type\Type|null
     */
    private function findTypeInSchema(Schema $schema, $type, array &$visited)
    {
        if (isset($visited[spl_object_hash($schema)])) {
            return null;
        }
        $visited[spl_object_hash($schema)] = true;
        $schemaType = $schema->getType($type);
        if ($schemaType !== null) {
            return $schemaType;
        }
        foreach ($schema->getSchemas() as $innerSchema) {
            $schemaType = $this->findTypeInSchema($innerSchema, $type, $visited);
            if ($schemaType !== null) {
                return $schemaType;
            }
        }

        return null;
    }
}

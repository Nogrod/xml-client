<?php
namespace Nogrod\XMLClient\Builder;

use Nogrod\XMLClient\DependencyInjection\XMLClientExtension;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class XMLContainerBuilder
{
    protected $configFile = 'config.yaml';

    protected $extensions = [];

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * XMLContainerBuilder constructor.
     * @param null $configFile
     * @param LoggerInterface|null $logger
     */
    public function __construct($configFile = null, LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        $this->setConfigFile($configFile);
        $this->addExtension(new XMLClientExtension());
    }

    public function setConfigFile($configFile)
    {
        $this->configFile = $configFile;
    }

    protected function addExtension(ExtensionInterface $extension)
    {
        $this->extensions[] = $extension;
    }

    /**
     * @return ContainerBuilder
     *
     * @throws \Exception
     */
    protected function getContainerBuilder()
    {
        $container = new ContainerBuilder();

        foreach ($this->extensions as $extension) {
            $container->registerExtension($extension);
        }

        $locator = new FileLocator('.');
        $loaders = array(
            new YamlFileLoader($container, $locator),
            new XmlFileLoader($container, $locator)
        );
        $delegatingLoader = new DelegatingLoader(new LoaderResolver($loaders));
        $delegatingLoader->load($this->configFile);

        $container->compile();

        return $container;
    }

    public function getDebugContainer()
    {
        return $this->getContainerBuilder();
    }
}

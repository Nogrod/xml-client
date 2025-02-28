<?php

namespace Nogrod\XMLClient\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('xml_client');
        $treeBuilder->getRootNode()
            ->children()
            ->scalarNode('naming_strategy')
            ->defaultValue('short')
            ->cannotBeEmpty()
            ->end()
            ->scalarNode('path_generator')
            ->defaultValue('psr4')
            ->cannotBeEmpty()
            ->end()
            ->arrayNode('namespaces')->fixXmlConfig('namespace')
            ->cannotBeEmpty()->isRequired()
            ->requiresAtLeastOneElement()
            ->prototype('scalar')
            ->end()
            ->end()
            ->arrayNode('known_locations')->fixXmlConfig('known_location')
            ->prototype('scalar')
            ->end()
            ->end()
            ->arrayNode('destinations_php')->fixXmlConfig('destination_php')
            ->cannotBeEmpty()->isRequired()
            ->requiresAtLeastOneElement()
            ->prototype('scalar')
            ->end()
            ->end()
            ->arrayNode('destinations_jms')->fixXmlConfig('destination_jms')
            ->cannotBeEmpty()->isRequired()
            ->requiresAtLeastOneElement()
            ->prototype('scalar')
            ->end()
            ->end()
            ->arrayNode('configs_jms')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('xml_cdata')
            ->defaultTrue()
            ->end()
            ->end()
            ->end()
            ->arrayNode('aliases')->fixXmlConfig('alias')
            ->prototype('array')
            ->prototype('scalar')
            ->end()
            ->end()
            ->end()
            ->arrayNode('patch_fields')->fixXmlConfig('patch_field')
            ->prototype('array')
            ->prototype('scalar')
            ->end()
            ->end()
            ->end()
            ->arrayNode('metadata')->fixXmlConfig('metadata')
            ->prototype('scalar')
            ->end()
            ->end()
            ->end();
        return $treeBuilder;
    }
}

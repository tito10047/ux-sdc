<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('ux_sdc');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('ux_components_dir')
                    ->defaultValue('%kernel.project_dir%/src_component')
                    ->info('Adresár, v ktorom sa nachádzajú komponenty.')
                ->end()
                ->scalarNode('component_namespace')
                    ->defaultNull()
                    ->info('Namespace pre komponenty.')
                ->end()
                ->arrayNode('stimulus')
                    ->canBeDisabled()
                    ->children()
                    ->end()
                ->end()
                ->booleanNode('auto_discovery')
                    ->defaultTrue()
                    ->info('Či sa majú automaticky hľadať asety v adresári komponentu.')
                ->end()
                ->booleanNode('exclude_templates_from_asset_map')
                    ->defaultTrue()
                    ->info('Keep the Twig templates (*.twig) of ux_components_dir out of the AssetMapper, so that asset-map:compile does not publish them to public/assets/. The directory itself stays mapped for the component CSS and JS.')
                ->end()
                ->scalarNode('placeholder')
                    ->defaultValue('<!-- __UX_TWIG_COMPONENT_ASSETS__ -->')
                    ->info('Placeholder v HTML, ktorý bude nahradený asetikami.')
                ->end()
            ->end();

        return $treeBuilder;
    }
}

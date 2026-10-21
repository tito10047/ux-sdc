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

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
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
                    ->info('Root directory of the components: each component class sits in it next to its template, CSS and JS. Registered as a Twig path and as an AssetMapper path.')
                ->end()
                ->scalarNode('component_namespace')
                    ->defaultNull()
                    ->info('PHP namespace mapped to ux_components_dir (PSR-4), e.g. "App\\Component". When set, the classes in that directory are registered as autowired and autoconfigured services, and Stimulus controller names are derived relative to it. Null registers nothing: the application registers its components itself.')
                ->end()
                ->append($this->stimulusNode())
                ->append($this->routingNode())
                ->booleanNode('auto_discovery')
                    ->defaultTrue()
                    ->info('Load the CSS, JS and Twig template named after a component class and sitting next to it (Alert.php: Alert.css, Alert.js, Alert.html.twig) without declaring them. Assets declared with #[AsSdcComponent] or #[Asset] are loaded either way.')
                ->end()
                // An enum rather than a boolean node, so that "auto" can sit next to
                // true and false; the comparison is strict. Environment variables
                // get past an enum node and are rejected by SdcExtension.
                ->enumNode('metadata_cache')
                    ->values(['auto', true, false])
                    ->defaultValue('auto')
                    ->info('How component metadata (assets and template) is found. true: once, when the container is built, into %kernel.cache_dir%/sdc_metadata.php; asset files added or removed later need a cache:clear. false: on render, from the files next to the component class, so new files are picked up without clearing the cache. auto: false when kernel.debug is on, true otherwise.')
                ->end()
                ->booleanNode('exclude_templates_from_asset_map')
                    ->defaultTrue()
                    ->info('Keep the Twig templates (*.twig) of ux_components_dir out of the AssetMapper, so that asset-map:compile does not publish them to public/assets/. The directory itself stays mapped for the component CSS and JS.')
                ->end()
                ->scalarNode('placeholder')
                    ->defaultValue('<!-- __UX_TWIG_COMPONENT_ASSETS__ -->')
                    ->cannotBeEmpty()
                    ->info('The marker that render_component_assets() prints and that is replaced, in an HTML response, with the <link> and <script> tags of the components rendered on the page. Choose a string that cannot occur in the page otherwise.')
                ->end()
                ->scalarNode('csp_nonce_provider')
                    ->defaultNull()
                    ->info('Service ID of a Tito10047\\UX\\Sdc\\Csp\\NonceProviderInterface supplying the Content Security Policy nonces of the current request, put on the injected <script> and <link> tags. "Tito10047\\UX\\Sdc\\Csp\\NelmioNonceProvider" uses those of NelmioSecurityBundle (its "csp" feature has to be on). Null injects the tags without a nonce.')
                ->end()
            ->end();

        return $treeBuilder;
    }

    private function stimulusNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('stimulus'))->getRootNode();
        $node
            ->info('Integration with StimulusBundle. Accepts false as a shorthand for "enabled: false".')
            ->canBeDisabled();

        // canBeDisabled() only takes a description of its "enabled" node from Symfony 7.3 on.
        $node->find('enabled')
            ->info('Add ux_components_dir to the Stimulus controller paths, next to those of the application (assets/controllers by default), so that a *_controller.js next to a component is registered as a controller.');

        return $node;
    }

    private function routingNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('routing'))->getRootNode();
        $node
            ->info('Routes of the #[Route] controllers in ux_components_dir, such as those make:sdc-component --action generates. Accepts false as a shorthand for "enabled: false". Takes effect only with component_namespace set.')
            ->canBeDisabled();

        // canBeDisabled() only takes a description of its "enabled" node from Symfony 7.3 on.
        $node->find('enabled')
            ->info('Load those routes through the "ux_sdc" route import (resource: ., type: ux_sdc) and, on Symfony 7.4+, through "resource: routing.controllers". Off: the import loads nothing and the classes are left out of routing.controllers; an import of the directory the application writes itself still works.');

        return $node;
    }
}

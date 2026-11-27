<?php

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;

/**
 * @link https://symfony.com/doc/current/bundles/best_practices.html#configuration
 */
return static function (DefinitionConfigurator $definition): void {
    $definition
        ->rootNode()
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
                ->arrayNode('icons')
                    ->info('Ikony uložené priamo v priečinku komponentu (vyžaduje symfony/ux-icons).')
                    ->canBeDisabled()
                    ->children()
                        ->scalarNode('subdir')
                            ->defaultValue('')
                            ->info('Podpriečinok komponentu pre ikony, napr. "icons" → Alert/icons/arrow.svg. Prázdne = priamo v priečinku komponentu.')
                        ->end()
                        ->booleanNode('fallback_to_global')
                            ->defaultTrue()
                            ->info('Ak komponent ikonu nemá, použije sa globálna ikona s rovnakým názvom (ux_icons.icon_dir).')
                        ->end()
                    ->end()
                ->end()
                ->booleanNode('auto_discovery')
                    ->defaultTrue()
                    ->info('Či sa majú automaticky hľadať asety v adresári komponentu.')
                ->end()
                ->scalarNode('placeholder')
                    ->defaultValue('<!-- __UX_TWIG_COMPONENT_ASSETS__ -->')
                    ->info('Placeholder v HTML, ktorý bude nahradený asetikami.')
                ->end()
            ->end()
        ->end()
    ;
};

<?php

namespace Tito10047\UxTwigComponentAsset;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use Tito10047\UxTwigComponentAsset\CompilerPass\AssetComponentCompilerPass;
use Tito10047\UxTwigComponentAsset\DependencyInjection\Configuration;

/**
 * @link https://symfony.com/doc/current/bundles/best_practices.html
 */
class UxTwigComponentAsset extends AbstractBundle implements PrependExtensionInterface
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->import('../config/definition.php');
    }
    
    public function prepend(ContainerBuilder $builder): void
    {
        $configs = $builder->getExtensionConfig($this->getName());
        
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs, $builder);

        $builder->prependExtensionConfig('twig', [
            'paths' => [
                $config['ux_components_dir'] => null,
            ],
        ]);

        $builder->prependExtensionConfig('framework', [
            'asset_mapper' => [
                'paths' => [
                    $config['ux_components_dir'],
                ],
            ],
        ]);

        if (null !== $config['component_namespace']) {
            $builder->prependExtensionConfig('twig_component', [
                'defaults' => [
                    $config['component_namespace'] => $config['ux_components_dir'],
                ],
            ]);
        }

        if ($config['stimulus']['enabled'] && $builder->hasExtension('stimulus')) {
            $builder->prependExtensionConfig('stimulus', [
                'controller_paths' => [
                    $config['ux_components_dir'],
                ],
            ]);
        }
    }

    private function processConfiguration(Configuration $configuration, array $configs, ContainerBuilder $container): array
    {
        $processor = new \Symfony\Component\Config\Definition\Processor();
        return $processor->processConfiguration($configuration, $configs);
    }
    
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');

        $builder->getDefinition('Tito10047\UxTwigComponentAsset\EventListener\AssetResponseListener')
            ->setArgument('$placeholder', $config['placeholder']);

        $builder->getDefinition('Tito10047\UxTwigComponentAsset\Twig\AssetExtension')
            ->setArgument('$placeholder', $config['placeholder']);
            
        $builder->setParameter('ux_twig_component_asset.auto_discovery', $config['auto_discovery']);
        $builder->setParameter('ux_twig_component_asset.ux_components_dir', $config['ux_components_dir']);
        $builder->setParameter('ux_twig_component_asset.component_namespace', $config['component_namespace']);

        if (null !== $config['component_namespace']) {
            $builder->register(rtrim($config['component_namespace'], '\\'), rtrim($config['component_namespace'], '\\'))
                ->setAutoconfigured(true)
                ->setAutowired(true);
        }

        $builder->setAlias('app.ui_components.dir', 'ux_twig_component_asset.ux_components_dir');
        $builder->setParameter('app.ui_components.dir', $config['ux_components_dir']);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new AssetComponentCompilerPass());
    }
}
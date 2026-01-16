<?php

namespace Tito10047\UxTwigComponentAsset\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\Config\FileLocator;

class UxTwigComponentAssetExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.php');

        $container->getDefinition('Tito10047\UxTwigComponentAsset\EventListener\AssetResponseListener')
            ->setArgument('$placeholder', $config['placeholder']);
            
        $container->setParameter('ux_twig_component_asset.auto_discovery', $config['auto_discovery']);
        $container->setParameter('ux_twig_component_asset.ux_components_dir', $config['ux_components_dir']);
        $container->setParameter('ux_twig_component_asset.component_namespace', $config['component_namespace']);

        if (null !== $config['component_namespace']) {
            $container->register(rtrim($config['component_namespace'], '\\'), rtrim($config['component_namespace'], '\\'))
                ->setAutoconfigured(true)
                ->setAutowired(true);
        }

        $container->setAlias('app.ui_components.dir', 'ux_twig_component_asset.ux_components_dir');
        $container->setParameter('app.ui_components.dir', $config['ux_components_dir']);
    }

    public function prepend(ContainerBuilder $container): void
    {
        $configs = $container->getExtensionConfig($this->getAlias());
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->prependExtensionConfig('twig', [
            'paths' => [
                $config['ux_components_dir'] => null,
            ],
        ]);

        $container->prependExtensionConfig('framework', [
            'asset_mapper' => [
                'paths' => [
                    $config['ux_components_dir'],
                ],
            ],
        ]);

        if (null !== $config['component_namespace']) {
            $container->prependExtensionConfig('twig_component', [
                'defaults' => [
                    $config['component_namespace'] => $config['ux_components_dir'],
                ],
            ]);
        }

        if ($config['stimulus']['enabled'] && $container->hasExtension('stimulus')) {
            $container->prependExtensionConfig('stimulus', [
                'controller_paths' => [
                    $config['ux_components_dir'],
                ],
            ]);
        }
    }

    public function getAlias(): string
    {
        return 'ux_twig_component_asset';
    }
}

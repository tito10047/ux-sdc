<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\RouteCollection;
use Symfony\UX\TwigComponent\TwigComponentBundle;
use Symfony\UX\LiveComponent\LiveComponentBundle;
use Symfony\UX\StimulusBundle\StimulusBundle;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Tito10047\UX\Sdc\UxSdcBundle;

class TestKernel extends Kernel
{
    use MicroKernelTrait;

    private array $configs = [];

    public function __construct(
        string|array $configs = [],
        ?string $environment = 'test',
        bool $debug = true
    ) {
        if (is_string($configs)) {
            $environment = $configs;
            $configs = [];
        }
        parent::__construct($environment ?? 'test', $debug);
        $this->configs = $configs;
    }

    public function registerBundles(): iterable
    {
        $bundles = [
            new FrameworkBundle(),
            new TwigBundle(),
            new TwigComponentBundle(),
            new UxSdcBundle(),
        ];

        if (class_exists(\Symfony\Bundle\MakerBundle\MakerBundle::class)) {
            $bundles[] = new \Symfony\Bundle\MakerBundle\MakerBundle();
        }

        if (class_exists(LiveComponentBundle::class)) {
            $bundles[] = new LiveComponentBundle();
        }

        if (class_exists(StimulusBundle::class)) {
            $bundles[] = new StimulusBundle();
        }

        return $bundles;
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        if (class_exists(LiveComponentBundle::class)) {
            $routes->import('@LiveComponentBundle/config/routes.php');
        }
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $container->setParameter('kernel.environment', $this->getEnvironment());
        $container->loadFromExtension('framework', [
            'secret'               => 'test_secret',
            'http_method_override' => false,
            'php_errors'           => ['log' => true],
            'router'               => ['utf8' => true],
            'session'              => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'test'                 => true,
        ]);

        $container->loadFromExtension('twig', [
            'default_path' => '%kernel.project_dir%/tests/Integration/Fixtures/templates',
        ]);

        $container->loadFromExtension('twig_component', [
            'anonymous_template_directory' => 'components/',
        ]);

        if (class_exists(LiveComponentBundle::class)) {
            $container->loadFromExtension('live_component', [
                'secret' => 'test_secret',
            ]);
        }

        $configs = array_merge([
            'component_namespace' => 'Tito10047\\UX\\Sdc\\Tests\\Integration\\Fixtures\\Component',
            'ux_components_dir' => '%kernel.project_dir%/tests/Integration/Fixtures/Component'
        ], $this->configs);

        $container->loadFromExtension('ux_sdc', $configs);

        // Make services public for testing
        $container->addCompilerPass(new class () implements \Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach ($container->getDefinitions() as $id => $definition) {
                    if (str_starts_with($id, 'Tito10047\UX\Sdc') || str_contains($id, 'twig_component')) {
                        $definition->setPublic(true);
                    }
                }
                foreach ($container->getAliases() as $id => $alias) {
                    if (str_contains($id, 'twig_component')) {
                        $alias->setPublic(true);
                    }
                }
            }
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/UX/Sdc/cache/' . spl_object_hash($this);
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/UX/Sdc/logs/' . spl_object_hash($this);
    }
}

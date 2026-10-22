<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Visual;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\UX\TwigComponent\TwigComponentBundle;
use Tito10047\UX\Sdc\SdcBundle;

class BenchmarkKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(
        private string $type, // 'classic' or 'ux_sdc'
        string         $environment = 'test',
        bool           $debug = false
    ) {
        parent::__construct($environment, $debug);
    }

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new TwigBundle(),
            new TwigComponentBundle(),
            new SdcBundle(),
        ];
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $container->setParameter('kernel.project_dir', $this->getProjectDir());

        $container->loadFromExtension('framework', [
            'secret'       => 'test_secret',
            'test'         => false,
            'asset_mapper' => ['enabled' => true],
        ]);

        $Type = ucfirst($this->type);
        $container->loadFromExtension('twig', [
            'default_path'         => __DIR__ . '/Generated/' . $Type,
            'strict_variables'     => false,
            'exception_controller' => null,
            'debug'                => false,
        ]);

        $ns  = 'Tito10047\\UX\\Sdc\\Tests\\Visual\\Generated\\' . $Type;
        $dir = __DIR__ . '/Generated/' . $Type;

        $container->loadFromExtension('twig_component', [
            'anonymous_template_directory' => 'components/',
        ]);

        $container->loadFromExtension('ux_sdc', [
            'component_namespace' => $ns,
            'ux_components_dir'   => $dir,
        ]);

        $container->addCompilerPass(new class () implements \Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach ($container->getDefinitions() as $id => $definition) {
                    if (str_contains($id, 'doctrine') || str_contains($id, 'database_connection') || str_contains($id, 'profiler') || str_contains($id, 'twig.runtime.serializer')) {
                        $container->removeDefinition($id);
                        continue;
                    }
                    $definition->setPublic(true);
                }
                foreach ($container->getAliases() as $id => $alias) {
                    if (str_contains($id, 'doctrine') || str_contains($id, 'database_connection') || str_contains($id, 'profiler') || str_contains($id, 'twig.runtime.serializer')) {
                        $container->removeAlias($id);
                        continue;
                    }
                    $alias->setPublic(true);
                }

                if ($container->getParameter('kernel.debug')) {
                    $container->register('twig.extension.profiler', \Twig\Extension\ProfilerExtension::class)
                        ->addArgument(new Definition(\Twig\Profiler\Profile::class))
                        ->setPublic(false);
                }

                if (!$container->hasDefinition('asset_mapper')) {
                    $container->register('asset_mapper', \Symfony\Component\AssetMapper\AssetMapperInterface::class)
                        ->setPublic(true)
                        ->setSynthetic(true);
                }
            }
        }, \Symfony\Component\DependencyInjection\Compiler\PassConfig::TYPE_BEFORE_OPTIMIZATION, 1000);
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/UX/Sdc/benchmark/' . $this->type;
    }
}

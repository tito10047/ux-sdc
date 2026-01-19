<?php

namespace Tito10047\UX\TwigComponentSdc\Tests\Visual;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\UX\TwigComponent\TwigComponentBundle;
use Tito10047\UX\TwigComponentSdc\TwigComponentSdcBundle;

class BenchmarkKernel extends Kernel {

	use MicroKernelTrait;

	public function __construct(
		private string $type, // 'classic' or 'sdc'
		string         $environment = 'test',
		bool           $debug = false
	) {
		parent::__construct($environment, $debug);
	}

	public function registerBundles(): iterable {
		return [
			new FrameworkBundle(),
			new TwigBundle(),
			new TwigComponentBundle(),
			new TwigComponentSdcBundle(),
		];
	}

	protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void {
		$container->setParameter('kernel.project_dir', $this->getProjectDir());

		$container->loadFromExtension('framework', [
			'secret'       => 'test_secret',
			'test'         => false,
			'asset_mapper' => ['enabled' => true],
		]);

		$container->loadFromExtension('twig', [
			'default_path'         => __DIR__ . '/Generated/' . ucfirst($this->type),
			'strict_variables'     => false,
			'exception_controller' => null,
			'debug'                => false,
		]);

		$ns  = 'Tito10047\\UX\\TwigComponentSdc\\Tests\\Visual\\Generated\\' . ucfirst($this->type);
		$dir = __DIR__ . '/Generated/' . ucfirst($this->type);

		$container->loadFromExtension('twig_component', [
			'anonymous_template_directory' => 'components/',
		]);

		$container->loadFromExtension('twig_component_sdc', [
			'component_namespace' => $ns,
			'ux_components_dir'   => $dir,
		]);

		$container->addCompilerPass(new class() implements \Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface {

			public function process(ContainerBuilder $container): void {
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

					// Možno budeš potrebovať aj toto pre AssetMapper, ak ho používaš
					if (!$container->has('assets._asset_mapper_debug_command')) {
						$container->register('assets._asset_mapper_debug_command', \stdClass::class)->setPublic(false);
					}
				}

				if (!$container->hasDefinition('asset_mapper')) {
					$container->register('asset_mapper', \Symfony\Component\AssetMapper\AssetMapperInterface::class)
						->setPublic(true)
						->setSynthetic(true);
				}
			}
		}, \Symfony\Component\DependencyInjection\Compiler\PassConfig::TYPE_BEFORE_OPTIMIZATION, 1000);
	}

	public function getProjectDir(): string {
		return __DIR__;
	}

	public function getCacheDir(): string {
		return sys_get_temp_dir() . '/UX/TwigComponentSdc/benchmark/' . $this->type;
	}
}

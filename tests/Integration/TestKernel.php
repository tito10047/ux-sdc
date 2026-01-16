<?php

namespace Tito10047\UxTwigComponentAsset\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\RouteCollection;
use Symfony\UX\TwigComponent\TwigComponentBundle;
use Tito10047\UxTwigComponentAsset\UxTwigComponentAsset;

class TestKernel extends Kernel {

	use MicroKernelTrait;

	public function __construct(
		private array $configs = [],
		string $environment = 'test',
		bool $debug = true
	) {
		parent::__construct($environment, $debug);
	}

	public function registerBundles(): iterable {
		return [
			new FrameworkBundle(),
			new TwigBundle(),
			new TwigComponentBundle(),
			new UxTwigComponentAsset(),
		];
	}

	protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void {
		$container->loadFromExtension('framework', [
			'secret'               => 'test_secret',
			'test'                 => true,
			'http_method_override' => false,
			'php_errors'           => ['log' => true],
			'router'               => ['utf8' => true],
		]);

		$container->loadFromExtension('twig', [
			'default_path' => '%kernel.project_dir%/tests/Integration/Fixtures/templates',
			"paths"        => [
				'%kernel.project_dir%/tests/Integration/Fixtures/Component'
			]
		]);

		$container->loadFromExtension('twig_component', [
			'anonymous_template_directory' => 'components/',
			'defaults'                     => [
				'Tito10047\UxTwigComponentAsset\Tests\Integration\Fixtures\Component\\' =>
					'%kernel.project_dir%/tests/Integration/Fixtures/Component',
			],
		]);

		$container->loadFromExtension('ux_twig_component_asset', $this->configs);

		$container->register(Fixtures\Component\TestComponent::class)
			->setAutoconfigured(true)
			->setAutowired(true)
			->addTag('twig.component', ['key' => 'TestComponent']);

		$container->register(Fixtures\Component\AutoDiscovery\AutoDiscoveryComponent::class)
			->setAutoconfigured(true)
			->setAutowired(true)
			->addTag('twig.component', ['key' => 'AutoDiscoveryComponent']);

		// Make services public for testing
		$container->addCompilerPass(new class() implements \Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface {

			public function process(ContainerBuilder $container): void {
				foreach ($container->getDefinitions() as $id => $definition) {
					if (str_starts_with($id, 'Tito10047\UxTwigComponentAsset') || str_contains($id, 'twig_component')) {
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

	public function getCacheDir(): string {
		return sys_get_temp_dir() . '/UxTwigComponentAsset/cache/' . spl_object_hash($this);
	}

	public function getLogDir(): string {
		return sys_get_temp_dir() . '/UxTwigComponentAsset/logs/' . spl_object_hash($this);
	}
}

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

use Symfony\Bundle\FrameworkBundle\Controller\TemplateController;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\UX\TwigComponent\TwigComponentBundle;
use Symfony\UX\LiveComponent\LiveComponentBundle;
use Symfony\UX\StimulusBundle\StimulusBundle;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Tito10047\UX\Sdc\SdcBundle;

class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * Runs older than this are left over from a process that never reached its
     * shutdown functions (killed, Ctrl+C) and are removed by the next run.
     */
    private const STALE_RUN_TTL = 86400;

    /** Holds the cache and log directories of every kernel of this process. */
    private static ?string $runDir = null;

    private array $configs = [];

    /** Own directory of this kernel, so two kernels never share a container. */
    private readonly string $varDir;

    /**
     * @param array      $frameworkConfig loaded as a separate framework config, the way an application's own config/packages/framework.yaml would be
     * @param array|null $stimulusConfig  the application's own config/packages/stimulus.yaml; null when it has none
     * @param list<CompilerPassInterface> $compilerPasses added to the container on top of those of the bundles, e.g. to rearrange listeners
     * @param array      $twigConfig      loaded as a separate twig config, the way an application's own config/packages/twig.yaml would be
     * @param list<class-string> $services registered autowired and autoconfigured, the way an application's services.yaml registers its classes
     * @param array      $twigComponentConfig loaded as a separate twig_component config, the way an application's own config/packages/twig_component.yaml would be
     * @param array<string, string> $attributeRoutes PSR-4 directories whose #[Route] attributes are loaded, namespace => directory, the way a `resource: {path, namespace}, type: attribute` entry of config/routes.yaml loads them
     * @param list<array{0: string, 1: string|null}> $routeImports further route imports, [resource, type], the way the other entries of config/routes.yaml or config/routes/*.yaml would, e.g. ['.', 'ux_sdc'] or ['routing.controllers', null]
     */
    public function __construct(
        string|array $configs = [],
        ?string $environment = 'test',
        bool $debug = true,
        private readonly array $frameworkConfig = [],
        private readonly ?array $stimulusConfig = null,
        private readonly array $compilerPasses = [],
        private readonly array $twigConfig = [],
        private readonly array $services = [],
        private readonly array $twigComponentConfig = [],
        private readonly array $attributeRoutes = [],
        private readonly array $routeImports = [],
    ) {
        if (is_string($configs)) {
            $environment = $configs;
            $configs = [];
        }
        parent::__construct($environment ?? 'test', $debug);
        $this->configs = $configs;
        $this->varDir = self::runDir().'/'.bin2hex(random_bytes(8));
    }

    public function registerBundles(): iterable
    {
        $bundles = [
            new FrameworkBundle(),
            new TwigBundle(),
            new TwigComponentBundle(),
            new SdcBundle(),
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
        // Renders any template of Fixtures/templates as a full page, through the
        // whole kernel: GET /page/auto_discovery.html.twig
        $routes->add('sdc_test_page', '/page/{template}')
            ->controller(TemplateController::class)
            ->requirements(['template' => '.+\\.twig']);

        // Before the LiveComponent route: imported without a prefix, it matches any /{a}/{b}.
        foreach ($this->attributeRoutes as $namespace => $directory) {
            $routes->import(['path' => $directory, 'namespace' => $namespace], 'attribute');
        }
        foreach ($this->routeImports as [$resource, $type]) {
            $routes->import($resource, $type);
        }

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

        if ([] !== $this->frameworkConfig) {
            $container->loadFromExtension('framework', $this->frameworkConfig);
        }

        if (null !== $this->stimulusConfig && class_exists(StimulusBundle::class)) {
            $container->loadFromExtension('stimulus', $this->stimulusConfig);
        }

        $container->loadFromExtension('twig', [
            'default_path' => '%kernel.project_dir%/tests/Integration/Fixtures/templates',
        ]);

        if ([] !== $this->twigConfig) {
            $container->loadFromExtension('twig', $this->twigConfig);
        }

        foreach ($this->services as $class) {
            $container->register($class, $class)->setAutowired(true)->setAutoconfigured(true);
        }

        $container->loadFromExtension('twig_component', [
            'anonymous_template_directory' => 'components/',
        ]);

        if ([] !== $this->twigComponentConfig) {
            $container->loadFromExtension('twig_component', $this->twigComponentConfig);
        }

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

        foreach ($this->compilerPasses as $compilerPass) {
            $container->addCompilerPass($compilerPass);
        }

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
        return $this->varDir.'/cache';
    }

    public function getLogDir(): string
    {
        return $this->varDir.'/log';
    }

    /**
     * One directory per PHPUnit process, removed when the process ends.
     *
     * Kernels are created all over the test suite — by KernelTestCase, by
     * WebTestCase, and directly — and a container can outlive the test that
     * booted it, so no single test knows when a kernel's cache is safe to drop.
     * The process does: by the time it shuts down, nothing reads them anymore.
     * The process ID and a random suffix keep parallel runs apart.
     */
    private static function runDir(): string
    {
        if (null !== self::$runDir) {
            return self::$runDir;
        }

        $root = sys_get_temp_dir().'/ux-sdc-tests';
        self::removeStaleRuns($root);

        $pid = getmypid();
        $runDir = $root.'/'.$pid.'-'.bin2hex(random_bytes(4));

        register_shutdown_function(static function () use ($runDir, $pid): void {
            // A forked child inherits this function; only the process that owns the directory removes it.
            if (getmypid() === $pid) {
                (new Filesystem())->remove($runDir);
            }
        });

        return self::$runDir = $runDir;
    }

    private static function removeStaleRuns(string $root): void
    {
        $filesystem = new Filesystem();

        foreach (glob($root.'/*', \GLOB_ONLYDIR | \GLOB_NOSORT) ?: [] as $dir) {
            $modified = @filemtime($dir);
            if (false === $modified || $modified > time() - self::STALE_RUN_TTL) {
                continue;
            }

            try {
                $filesystem->remove($dir);
            } catch (IOException) {
                // Best effort: another run may be removing the same directory.
            }
        }
    }
}

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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\UX\StimulusBundle\AssetMapper\ControllersMapGenerator;
use Symfony\UX\StimulusBundle\StimulusBundle;

/**
 * The component directory is a Stimulus controller path — in addition to the
 * application's own controller paths, never instead of them.
 */
class StimulusControllerPathsIntegrationTest extends TestCase
{
    private const PROJECT_DIR = __DIR__.'/../..';
    private const FIXTURES_DIR = __DIR__.'/Fixtures/Component';

    /** @var list<TestKernel> */
    private array $kernels = [];

    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function setUp(): void
    {
        if (!class_exists(StimulusBundle::class)) {
            $this->markTestSkipped('symfony/stimulus-bundle is not installed.');
        }
    }

    protected function tearDown(): void
    {
        $filesystem = new Filesystem();
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
            $filesystem->remove([$kernel->getCacheDir(), $kernel->getLogDir()]);
        }
        $filesystem->remove($this->temporaryPaths);
    }

    public function testStimulusBundleDefaultIsKeptWithoutApplicationConfig(): void
    {
        $this->assertSame(
            [$this->path(self::PROJECT_DIR.'/assets/controllers'), $this->path(self::FIXTURES_DIR)],
            $this->controllerPaths([]),
        );
    }

    public function testApplicationControllerPathsAreKept(): void
    {
        $applicationDir = $this->temporaryDirectory('controllers');

        $this->assertSame(
            [$applicationDir, $this->path(self::FIXTURES_DIR)],
            $this->controllerPaths([], ['controller_paths' => [$applicationDir]]),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function componentDirSpellingProvider(): iterable
    {
        yield 'the same spelling' => ['%kernel.project_dir%/tests/Integration/Fixtures/Component'];
        yield 'absolute' => [self::FIXTURES_DIR];
        yield 'with a trailing slash' => ['%kernel.project_dir%/tests/Integration/Fixtures/Component/'];
        yield 'not normalised' => ['%kernel.project_dir%/tests/Integration/../Integration/Fixtures/Component'];
    }

    #[DataProvider('componentDirSpellingProvider')]
    public function testComponentDirListedByTheApplicationIsNotAddedTwice(string $componentDir): void
    {
        $this->assertSame(
            [$this->path(self::PROJECT_DIR.'/assets/controllers'), $this->path(self::FIXTURES_DIR)],
            $this->controllerPaths([], [
                'controller_paths' => ['%kernel.project_dir%/assets/controllers', $componentDir],
            ]),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function disabledStimulusProvider(): iterable
    {
        yield 'enabled: false' => [['stimulus' => ['enabled' => false]]];
        yield 'false shorthand' => [['stimulus' => false]];
    }

    /**
     * @param array<string, mixed> $sdcConfig
     */
    #[DataProvider('disabledStimulusProvider')]
    public function testComponentDirIsNotAddedWhenStimulusIsDisabled(array $sdcConfig): void
    {
        $this->assertSame(
            [$this->path(self::PROJECT_DIR.'/assets/controllers')],
            $this->controllerPaths($sdcConfig),
        );
    }

    /**
     * Finder::in() throws on a missing directory, which would take down every
     * controller of the application.
     */
    public function testMissingComponentDirIsNotAdded(): void
    {
        $missingDir = $this->temporaryPath('src_component');

        $this->assertSame(
            [$this->path(self::PROJECT_DIR.'/assets/controllers')],
            $this->controllerPaths(['ux_components_dir' => $missingDir]),
        );
    }

    public function testControllersAreFoundInBothApplicationAndComponentDirs(): void
    {
        $applicationDir = $this->temporaryDirectory('controllers');
        file_put_contents($applicationDir.'/hello_controller.js', 'export default class {}');

        $componentDir = $this->temporaryDirectory('src_component');
        (new Filesystem())->mkdir($componentDir.'/UI/Alert');
        file_put_contents($componentDir.'/UI/Alert/alert_controller.js', 'export default class {}');

        $generator = $this->controllersMapGenerator(
            ['ux_components_dir' => $componentDir],
            ['controller_paths' => [$applicationDir]],
            ['asset_mapper' => ['paths' => [$applicationDir]]],
        );

        $controllerNames = array_keys($generator->getControllersMap());
        sort($controllerNames);

        $this->assertSame(['UI--Alert--alert', 'hello'], $controllerNames);
    }

    /**
     * @param array<string, mixed>      $sdcConfig
     * @param array<string, mixed>|null $stimulusConfig
     *
     * @return list<string>
     */
    private function controllerPaths(array $sdcConfig, ?array $stimulusConfig = null): array
    {
        $paths = $this->controllersMapGenerator($sdcConfig, $stimulusConfig)->getControllerPaths();

        return array_map($this->path(...), $paths);
    }

    /**
     * @param array<string, mixed>      $sdcConfig
     * @param array<string, mixed>|null $stimulusConfig
     * @param array<string, mixed>      $frameworkConfig
     */
    private function controllersMapGenerator(array $sdcConfig, ?array $stimulusConfig, array $frameworkConfig = []): ControllersMapGenerator
    {
        $kernel = new TestKernel($sdcConfig, 'test', true, $frameworkConfig, $stimulusConfig);
        $this->kernels[] = $kernel;
        $kernel->boot();

        // The generator is the one consumer of the controller paths: it finds
        // the controllers in them, and the loader compiler reads them back from it.
        $generator = $kernel->getContainer()->get('test.service_container')->get('stimulus.asset_mapper.controllers_map_generator');
        $this->assertInstanceOf(ControllersMapGenerator::class, $generator);

        return $generator;
    }

    private function path(string $path): string
    {
        $realPath = realpath($path);

        return rtrim(false !== $realPath ? $realPath : $path, '/');
    }

    private function temporaryDirectory(string $name): string
    {
        $path = $this->temporaryPath($name);
        (new Filesystem())->mkdir($path);

        return (string) realpath($path);
    }

    private function temporaryPath(string $name): string
    {
        $path = sys_get_temp_dir().'/'.uniqid('ux-sdc-', true).'/'.$name;
        (new Filesystem())->mkdir(\dirname($path));
        $this->temporaryPaths[] = \dirname($path);

        return $path;
    }
}

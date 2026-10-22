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
use Symfony\Component\AssetMapper\AssetMapperRepository;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The component directory is an AssetMapper path so that component CSS and JS
 * can be served — which must not publish the component Twig templates.
 */
class AssetMapperExclusionIntegrationTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__.'/Fixtures/Component';

    /** @var list<TestKernel> */
    private array $kernels = [];

    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        $filesystem = new Filesystem();
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
            $filesystem->remove([$kernel->getCacheDir(), $kernel->getLogDir()]);
        }
        $filesystem->remove($this->temporaryPaths);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function componentDirProvider(): iterable
    {
        yield 'with %kernel.project_dir%' => ['%kernel.project_dir%/tests/Integration/Fixtures/Component'];
        yield 'absolute' => [self::FIXTURES_DIR];
        yield 'absolute with a trailing slash' => [self::FIXTURES_DIR.'/'];
        yield 'absolute, not normalised' => [self::FIXTURES_DIR.'/../Component'];
    }

    #[DataProvider('componentDirProvider')]
    public function testComponentTemplatesAreNotMapped(string $componentDir): void
    {
        $sourcePaths = $this->mappedSourcePaths(['ux_components_dir' => $componentDir]);

        $this->assertContains($this->fixture('LiveComponentWithAsset.css'), $sourcePaths);
        $this->assertContains($this->fixture('AutoDiscovery/AutoDiscoveryComponent.css'), $sourcePaths);
        $this->assertContains($this->fixture('AutoDiscovery/AutoDiscoveryComponent.js'), $sourcePaths);
        $this->assertSame([], $this->twigFilesIn($sourcePaths));
    }

    public function testComponentTemplatesAreMappedWhenTheExclusionIsDisabled(): void
    {
        $sourcePaths = $this->mappedSourcePaths(['exclude_templates_from_asset_map' => false]);

        $this->assertContains($this->fixture('LiveComponentWithAsset.css'), $sourcePaths);
        $this->assertContains($this->fixture('LiveComponentWithAsset.html.twig'), $sourcePaths);
        $this->assertContains($this->fixture('AutoDiscovery/AutoDiscoveryComponent.html.twig'), $sourcePaths);
    }

    public function testTheExclusionCanBeDisabledThroughAnEnvironmentVariable(): void
    {
        $_SERVER['SDC_TEST_EXCLUDE_TEMPLATES'] = 'false';

        try {
            $sourcePaths = $this->mappedSourcePaths([
                'exclude_templates_from_asset_map' => '%env(bool:SDC_TEST_EXCLUDE_TEMPLATES)%',
            ]);
        } finally {
            unset($_SERVER['SDC_TEST_EXCLUDE_TEMPLATES']);
        }

        $this->assertContains($this->fixture('LiveComponentWithAsset.html.twig'), $sourcePaths);
    }

    public function testApplicationExcludedPatternsAreKept(): void
    {
        $sourcePaths = $this->mappedSourcePaths([], [
            'asset_mapper' => [
                'excluded_patterns' => ['*/AutoDiscovery/*.js'],
            ],
        ]);

        $this->assertNotContains($this->fixture('AutoDiscovery/AutoDiscoveryComponent.js'), $sourcePaths);
        $this->assertContains($this->fixture('AutoDiscovery/AutoDiscoveryComponent.css'), $sourcePaths);
        $this->assertSame([], $this->twigFilesIn($sourcePaths));
    }

    public function testTemplatesAreExcludedFromADirectoryWithGlobCharactersInItsName(): void
    {
        $componentDir = $this->temporaryDirectory('sdc [x] {a,b} *? 100%');
        file_put_contents($componentDir.'/Card.css', '.card {}');
        file_put_contents($componentDir.'/Card.html.twig', '<div class="card"></div>');

        $sourcePaths = $this->mappedSourcePaths([
            'ux_components_dir' => $componentDir,
        ]);

        $this->assertContains(realpath($componentDir.'/Card.css'), $sourcePaths);
        $this->assertNotContains(realpath($componentDir.'/Card.html.twig'), $sourcePaths);
    }

    public function testTemplatesAreExcludedWhenTheComponentDirIsASymlink(): void
    {
        $link = $this->temporaryPath('sdc-components-link');
        try {
            (new Filesystem())->symlink(self::FIXTURES_DIR, $link);
        } catch (IOException $e) {
            $this->markTestSkipped('Symbolic links cannot be created here: '.$e->getMessage());
        }

        $sourcePaths = $this->mappedSourcePaths([
            'ux_components_dir' => $link,
        ]);

        $this->assertContains($this->fixture('LiveComponentWithAsset.css'), $sourcePaths);
        $this->assertSame([], $this->twigFilesIn($sourcePaths));
    }

    /**
     * @param array<string, mixed> $sdcConfig
     * @param array<string, mixed> $frameworkConfig
     *
     * @return list<string>
     */
    private function mappedSourcePaths(array $sdcConfig, array $frameworkConfig = []): array
    {
        $kernel = new TestKernel($sdcConfig, 'test', true, $frameworkConfig);
        $this->kernels[] = $kernel;
        $kernel->boot();

        // The repository is where AssetMapper applies excluded_patterns; reading
        // it avoids compiling every asset, which would need an importmap and a
        // controllers.json this test application does not have.
        /** @var AssetMapperRepository $repository */
        $repository = $kernel->getContainer()->get('test.service_container')->get('asset_mapper.repository');

        $sourcePaths = array_values($repository->all());

        return $sourcePaths;
    }

    /**
     * @param list<string> $sourcePaths
     *
     * @return list<string>
     */
    private function twigFilesIn(array $sourcePaths): array
    {
        return array_values(array_filter($sourcePaths, static fn (string $path): bool => str_ends_with($path, '.twig')));
    }

    private function fixture(string $relativePath): string
    {
        return (string) realpath(self::FIXTURES_DIR.'/'.$relativePath);
    }

    private function temporaryDirectory(string $name): string
    {
        $path = $this->temporaryPath($name);
        (new Filesystem())->mkdir($path);

        return $path;
    }

    private function temporaryPath(string $name): string
    {
        $path = sys_get_temp_dir().'/'.uniqid('ux-sdc-', true).'/'.$name;
        (new Filesystem())->mkdir(\dirname($path));
        $this->temporaryPaths[] = \dirname($path);

        return $path;
    }
}

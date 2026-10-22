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

use Symfony\Component\AssetMapper\AssetMapperRepository;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;
use Twig\Environment;

/**
 * Right after `composer require`, before the first component is made, the
 * component directory does not exist yet. The application has to keep working,
 * and pick the directory up as soon as it is created.
 */
final class MissingComponentsDirFunctionalTest extends FunctionalTestCase
{
    private string $componentsDir;

    protected function setUp(): void
    {
        $this->componentsDir = sys_get_temp_dir().'/'.uniqid('ux-sdc-', true).'/src_component';
        (new Filesystem())->mkdir(\dirname($this->componentsDir));
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem())->remove(\dirname($this->componentsDir));
    }

    public function testPagesRenderBeforeTheComponentsDirExists(): void
    {
        $client = self::createClient($this->kernelOptions());
        $client->request('GET', '/page/plain.html.twig');

        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('<h1>No component on this page</h1>', (string) $client->getResponse()->getContent());
        $this->assertDirectoryDoesNotExist($this->componentsDir);
    }

    /**
     * What `debug:asset-map` and every asset lookup start from. The command
     * itself goes on to compile every asset, which would need an importmap and
     * a controllers.json this test application does not have.
     */
    public function testAssetMapperWorksBeforeTheComponentsDirExists(): void
    {
        self::bootKernel($this->kernelOptions());

        $repository = self::getContainer()->get('asset_mapper.repository');
        $this->assertInstanceOf(AssetMapperRepository::class, $repository);

        $this->assertNotContains($this->componentsDir, array_keys($repository->allDirectories()));
        $this->assertNotSame([], $repository->all(), 'The assets of the bundle itself are still mapped.');
    }

    public function testTheComponentsDirIsRegisteredOnceItIsCreated(): void
    {
        $existence = $this->componentsDirExistenceResources(self::bootKernel($this->kernelOptions()));
        $this->assertNotSame([], $existence, 'The container tracks whether the component directory exists.');

        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->componentsDir.'/Card.html.twig', '<div class="card"></div>');
        $filesystem->dumpFile($this->componentsDir.'/Card.css', '.card {}');

        foreach ($existence as $resource) {
            $this->assertFalse($resource->isFresh(time()), 'A debug container has to be rebuilt once the component directory exists.');
        }

        // The rebuild itself, on a kernel of its own: within one process, the
        // freshness of a resource is cached once checked, so booting the same
        // kernel again would keep the container it has.
        self::ensureKernelShutdown();
        self::bootKernel($this->kernelOptions());

        $twig = self::getContainer()->get('twig');
        $this->assertInstanceOf(Environment::class, $twig);
        $this->assertSame('<div class="card"></div>', $twig->render('Card.html.twig'));
        $this->assertSame('<div class="card"></div>', $twig->render('@component/Card.html.twig'));

        $repository = self::getContainer()->get('asset_mapper.repository');
        $this->assertInstanceOf(AssetMapperRepository::class, $repository);
        $this->assertSame(realpath($this->componentsDir.'/Card.css'), $repository->find('Card.css'));
        $this->assertNull($repository->find('Card.html.twig'), 'The templates of the component directory stay out of the asset map.');
    }

    /**
     * The resources a debug kernel checks before it reuses its container,
     * as dumped next to the container, that watch the component directory.
     *
     * @return list<FileExistenceResource>
     */
    private function componentsDirExistenceResources(KernelInterface $kernel): array
    {
        // The compiled container class has a namespace of its own; the file
        // the kernel loads is named after its short name.
        $containerFile = $kernel->getBuildDir().'/'.(new \ReflectionClass($kernel->getContainer()))->getShortName().'.php';
        $this->assertFileExists($containerFile.'.meta');

        $resources = unserialize((string) file_get_contents($containerFile.'.meta'));
        $this->assertIsArray($resources);

        return array_values(array_filter(
            $resources,
            fn (mixed $resource): bool => $resource instanceof FileExistenceResource && $this->componentsDir === $resource->getResource(),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function kernelOptions(): array
    {
        return ['configs' => ['ux_components_dir' => $this->componentsDir]];
    }
}

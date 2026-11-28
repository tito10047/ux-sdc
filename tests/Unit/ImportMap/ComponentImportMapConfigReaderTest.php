<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Unit\ImportMap;

use PHPUnit\Framework\TestCase;
use Symfony\Component\AssetMapper\AssetMapperRepository;
use Symfony\Component\AssetMapper\ImportMap\ImportMapEntry;
use Symfony\Component\AssetMapper\ImportMap\ImportMapType;
use Symfony\Component\AssetMapper\ImportMap\RemotePackageStorage;
use Symfony\Component\AssetMapper\Path\PublicAssetsPathResolver;
use Symfony\Component\Filesystem\Filesystem;
use Tito10047\UX\Sdc\ImportMap\ComponentImportMapConfigReader;
use Tito10047\UX\Sdc\ImportMap\ComponentImportMapEntries;
use Tito10047\UX\Sdc\Runtime\SdcMetadataRegistry;
use Tito10047\UX\Sdc\Service\ComponentMetadataResolver;

final class ComponentImportMapConfigReaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/' . uniqid('ux-sdc-import-map-reader-', true);
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->dir . '/assets/Card/Card.js', '');
        $filesystem->dumpFile($this->dir . '/assets/Chart/Chart.js', '');
        $filesystem->dumpFile($this->dir . '/importmap.php', '<?php return ' . var_export([
            'app' => ['path' => './assets/app.js', 'entrypoint' => true],
            // The application maps the same URL itself.
            '/assets/Chart/Chart.js' => ['path' => './assets/chart-shim.js'],
        ], true) . ';');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testAddsTheComponentScriptsAfterTheEntriesOfTheApplication(): void
    {
        $entries = $this->reader()->getEntries();

        $this->assertSame(['app', '/assets/Chart/Chart.js', '/assets/Card/Card.js'], array_map(static fn (ImportMapEntry $entry): string => $entry->importName, iterator_to_array($entries)));
        $this->assertSame('Card/Card.js', $entries->get('/assets/Card/Card.js')->path);
        // An entry of the application wins.
        $this->assertSame('./assets/chart-shim.js', $entries->get('/assets/Chart/Chart.js')->path);
    }

    public function testFindsAComponentScriptAsARootEntry(): void
    {
        $this->assertSame('Card/Card.js', $this->reader()->findRootImportMapEntry('/assets/Card/Card.js')?->path);
    }

    public function testWritesOnlyTheEntriesOfTheApplication(): void
    {
        $reader = $this->reader();

        // As importmap:require does: read, change, write back.
        $entries = $reader->getEntries();
        $entries->add(ImportMapEntry::createLocal('lodash', ImportMapType::JS, './assets/lodash.js', false));
        $reader->writeEntries($entries);

        $written = require $this->dir . '/importmap.php';
        $this->assertSame(['app', '/assets/Chart/Chart.js', 'lodash'], array_keys($written));
        $this->assertSame(['path' => './assets/chart-shim.js'], $written['/assets/Chart/Chart.js']);

        // The component entries are still read.
        $this->assertTrue($reader->getEntries()->has('/assets/Card/Card.js'));
    }

    public function testDoesNotChangeTheEntriesOfTheApplicationThroughTheReturnedCollection(): void
    {
        $reader = $this->reader();
        $reader->getEntries()->remove('app');

        $this->assertTrue($reader->getEntries()->has('app'));
    }

    public function testIsAssetMappersReaderWithoutComponentEntries(): void
    {
        $reader = new ComponentImportMapConfigReader($this->dir . '/importmap.php', new RemotePackageStorage($this->dir . '/vendor'));

        $this->assertSame(['app', '/assets/Chart/Chart.js'], array_map(static fn (ImportMapEntry $entry): string => $entry->importName, iterator_to_array($reader->getEntries())));
    }

    private function reader(): ComponentImportMapConfigReader
    {
        $js = static fn (string $path): array => ['path' => $path, 'type' => 'js', 'priority' => 0, 'attributes' => [], 'source' => 'discovered'];
        file_put_contents($this->dir . '/sdc_metadata.php', '<?php return ' . var_export([
            'components' => ['App\Card' => ['assets' => [$js('Card/Card.js'), $js('Chart/Chart.js')], 'template' => null, 'problems' => []]],
            'anonymous' => [],
        ], true) . ';');

        $reader = new ComponentImportMapConfigReader($this->dir . '/importmap.php', new RemotePackageStorage($this->dir . '/vendor'));
        $reader->setComponentEntries(new ComponentImportMapEntries(
            new SdcMetadataRegistry($this->dir . '/sdc_metadata.php'),
            new ComponentMetadataResolver([], [], true),
            true,
            new AssetMapperRepository([$this->dir . '/assets' => ''], $this->dir),
            new PublicAssetsPathResolver('/assets/'),
            ['App\Card'],
        ));

        return $reader;
    }
}

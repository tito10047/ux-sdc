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
use Symfony\Component\AssetMapper\ImportMap\ImportMapType;
use Symfony\Component\AssetMapper\Path\PublicAssetsPathResolver;
use Symfony\Component\Filesystem\Filesystem;
use Tito10047\UX\Sdc\ImportMap\ComponentImportMapEntries;
use Tito10047\UX\Sdc\Runtime\SdcMetadataRegistry;
use Tito10047\UX\Sdc\Service\ComponentMetadataResolver;
use Tito10047\UX\Sdc\Tests\Unit\Service\Fixtures\Alert\Alert;
use Tito10047\UX\Sdc\Tests\Unit\Service\Fixtures\UntypedAsset\UntypedAsset;

final class ComponentImportMapEntriesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/' . uniqid('ux-sdc-import-map-', true);
        $filesystem = new Filesystem();
        foreach (['Card/Card.js', 'Card/format.js', 'Card/legacy.js', 'Card/Card.css', 'Chart/Chart.js', 'Notice/Notice.js'] as $file) {
            $filesystem->dumpFile($this->dir . '/assets/' . $file, '');
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testListsTheModuleScriptsOfTheAssetMap(): void
    {
        $js = static fn (string $path, array $attributes = []): array => ['path' => $path, 'type' => 'js', 'priority' => 0, 'attributes' => $attributes, 'source' => 'discovered'];

        $entries = $this->entries([
            'components' => [
                'App\Card' => ['assets' => [
                    ['path' => 'Card/Card.css', 'type' => 'css', 'priority' => 0, 'attributes' => [], 'source' => 'discovered'],
                    $js('Card/Card.js'),
                    $js('Card/legacy.js', ['type' => false]),
                    $js('Card/missing.js'),
                    $js('https://cdn.example.com/lib.js'),
                ], 'template' => null, 'problems' => []],
                // The same script as Card's, and one with an explicit module type.
                'App\CardList' => ['assets' => [$js('Card/Card.js'), $js('Chart/Chart.js', ['type' => 'module'])], 'template' => null, 'problems' => []],
            ],
            'anonymous' => [
                'components/Notice.html.twig' => ['assets' => [$js('Notice/Notice.js')], 'problems' => []],
            ],
        ], ['App\Card', 'App\CardList', 'App\Unknown'], ['components/Notice.html.twig'])->all();

        $this->assertSame(['/assets/Card/Card.js', '/assets/Chart/Chart.js', '/assets/Notice/Notice.js'], array_keys($entries));

        $card = $entries['/assets/Card/Card.js'];
        $this->assertSame('/assets/Card/Card.js', $card->importName);
        $this->assertSame('Card/Card.js', $card->path);
        $this->assertSame(ImportMapType::JS, $card->type);
        $this->assertFalse($card->isEntrypoint);
        $this->assertFalse($card->isRemotePackage());
    }

    public function testResolvesTheEntriesOnceAndKeepsTheSameObjects(): void
    {
        $entries = $this->entries([
            'components' => ['App\Card' => ['assets' => [['path' => 'Card/Card.js', 'type' => 'js', 'priority' => 0, 'attributes' => [], 'source' => 'discovered']], 'template' => null, 'problems' => []]],
            'anonymous' => [],
        ], ['App\Card'], []);

        $this->assertSame($entries->all()['/assets/Card/Card.js'], $entries->all()['/assets/Card/Card.js']);
    }

    public function testWithoutTheCacheResolvesTheComponentsAndSkipsAnInvalidAsset(): void
    {
        // Alert.js is next to the Alert fixture; UntypedAsset has an #[Asset]
        // whose type cannot be told, which fails its render, not the import map.
        $fixtures = \dirname(__DIR__) . '/Service/Fixtures';
        $entries = new ComponentImportMapEntries(
            new SdcMetadataRegistry($this->dir . '/none.php', false),
            new ComponentMetadataResolver([], [$fixtures => ''], true),
            false,
            new AssetMapperRepository([$fixtures => ''], $fixtures),
            new PublicAssetsPathResolver('/assets/'),
            [Alert::class, UntypedAsset::class],
        );

        $this->assertSame(['/assets/Alert/Alert.js'], array_keys($entries->all()));
    }

    /**
     * @param array<string, mixed> $cache
     * @param list<string>         $classes
     * @param list<string>         $anonymousTemplates
     */
    private function entries(array $cache, array $classes, array $anonymousTemplates): ComponentImportMapEntries
    {
        $cachePath = $this->dir . '/sdc_metadata.php';
        file_put_contents($cachePath, '<?php return ' . var_export($cache, true) . ';');

        return new ComponentImportMapEntries(
            new SdcMetadataRegistry($cachePath),
            new ComponentMetadataResolver([], [], true),
            true,
            new AssetMapperRepository([$this->dir . '/assets' => ''], $this->dir),
            new PublicAssetsPathResolver('/assets/'),
            $classes,
            $anonymousTemplates,
        );
    }
}

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
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\AssetMapper\ImportMap\ImportMapGenerator;

/**
 * A component script imports the file next to it relatively: the Esm:Widget
 * fixture's Widget.js has `import './widget-helper.js'`. AssetMapper leaves
 * the import as it is, so the browser asks for the unversioned URL next to the
 * script, which only the import map maps to the versioned file.
 */
final class ImportMapFunctionalTest extends FunctionalTestCase
{
    /**
     * @return iterable<string, array{bool}>
     */
    public static function provideMetadataCache(): iterable
    {
        yield 'metadata resolved on render' => [false];
        yield 'metadata from the build-time cache' => [true];
    }

    #[DataProvider('provideMetadataCache')]
    public function testTheRelativeImportOfAComponentScriptResolvesThroughTheImportMap(bool $metadataCache): void
    {
        $client = self::createClient(['configs' => ['metadata_cache' => $metadataCache]]);
        $client->request('GET', '/page/esm_importmap.html.twig');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        $imports = $this->importMap($html);

        $assetMapper = self::getContainer()->get(AssetMapperInterface::class);
        $this->assertInstanceOf(AssetMapperInterface::class, $assetMapper);
        $widget = $assetMapper->getAsset('Esm/Widget.js');
        $helper = $assetMapper->getAsset('Esm/widget-helper.js');
        $this->assertNotNull($widget);
        $this->assertNotNull($helper);

        // The page loads the versioned script; the import in it stays relative.
        $this->assertStringContainsString(\sprintf('<script src="%s" type="module"></script>', $widget->publicPath), $html);
        $this->assertStringContainsString("import './widget-helper.js';", $widget->content ?? (string) file_get_contents($widget->sourcePath));

        // The URL the browser resolves it to, against the URL of the script.
        $requested = \dirname($widget->publicPath) . '/widget-helper.js';
        $this->assertSame($helper->publicPathWithoutDigest, $requested);
        $this->assertSame($helper->publicPath, $imports[$requested] ?? null, 'The import map maps the imported file to its versioned URL.');

    }

    public function testOnlyModuleScriptsOfTheAssetMapAreEntries(): void
    {
        $client = self::createClient();
        $client->request('GET', '/page/esm_importmap.html.twig');

        self::assertResponseIsSuccessful();
        $imports = $this->importMap((string) $client->getResponse()->getContent());

        // The component script itself is an entry, under its unversioned URL.
        $this->assertArrayHasKey('/assets/Esm/Widget.js', $imports);
        // legacy.js is a classic script (`'type' => false`): it cannot import.
        $this->assertArrayNotHasKey('/assets/Esm/legacy.js', $imports);
    }

    public function testEveryPageGetsTheSameImportMap(): void
    {
        // Including a page without any component: a component a Live re-render
        // or a Turbo Frame inserts later needs its entries already, and Turbo
        // Drive sees no new import map on a visit.
        $client = self::createClient();
        $client->request('GET', '/page/esm_importmap.html.twig');
        $withComponent = $this->importMap((string) $client->getResponse()->getContent());

        $client->request('GET', '/page/importmap_only.html.twig');
        self::assertResponseIsSuccessful();

        $this->assertSame($withComponent, $this->importMap((string) $client->getResponse()->getContent()));
    }

    public function testTheCompiledImportMapHasTheEntries(): void
    {
        self::bootKernel(['configs' => ['metadata_cache' => true]]);

        // What `asset-map:compile` writes into importmap.json.
        $generator = self::getContainer()->get('asset_mapper.importmap.generator');
        $this->assertInstanceOf(ImportMapGenerator::class, $generator);
        $data = $generator->getRawImportMapData();

        $this->assertArrayHasKey('/assets/Esm/widget-helper.js', $data);
        $this->assertStringStartsWith('/assets/Esm/widget-helper-', $data['/assets/Esm/widget-helper.js']['path']);
    }

    /**
     * @return array<string, string>
     */
    private function importMap(string $html): array
    {
        $this->assertSame(1, preg_match('#<script type="importmap">\s*(\{.*?\})\s*</script>#s', $html, $matches), 'The page has one import map.');
        $data = json_decode($matches[1], true, flags: \JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);
        $this->assertIsArray($data['imports']);

        return $data['imports'];
    }
}

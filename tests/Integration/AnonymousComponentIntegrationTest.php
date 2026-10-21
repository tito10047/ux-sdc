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
use Symfony\UX\LiveComponent\LiveComponentBundle;
use Tito10047\UX\Sdc\Runtime\SdcMetadataRegistry;

/**
 * An anonymous component — a template in ux_components_dir without a class —
 * gets the CSS and JS named after its template, next to it, in both metadata
 * pipelines (issue #3).
 *
 * The page (Fixtures/templates/anonymous.html.twig) renders
 * `Anonymous:Notice:Notice` (CSS and JS), which renders `Anonymous:Icon:Icon`
 * (CSS); `Anonymous:Plain` (an `index.html.twig` without assets); and the class
 * component `AutoDiscovery:AutoDiscoveryComponent`.
 */
final class AnonymousComponentIntegrationTest extends FunctionalTestCase
{
    private const PAGE = '/page/anonymous.html.twig';

    /**
     * @return iterable<string, array{bool}>
     */
    public static function metadataCache(): iterable
    {
        yield 'metadata cache' => [true];
        yield 'no metadata cache' => [false];
    }

    #[DataProvider('metadataCache')]
    public function testAnonymousComponentsGetTheAssetsNextToTheirTemplate(bool $metadataCache): void
    {
        $html = $this->requestPage(['metadata_cache' => $metadataCache]);

        $this->assertStringContainsString('<div class="notice"><i class="icon"></i>Saved</div>', $html);
        $this->assertStringContainsString('<p class="plain">Plain</p>', $html);
        $this->assertStringContainsString('data-testid="auto-discovery-component"', $html);

        // Each once: a class component is not taken for an anonymous one too.
        $this->assertSame([
            $this->publicPath('Anonymous/Notice/Notice.css'),
            $this->publicPath('Anonymous/Icon/Icon.css'),
            $this->publicPath('AutoDiscovery/AutoDiscoveryComponent.css'),
        ], $this->linkedStylesheets($html));
        $this->assertSame([
            $this->publicPath('Anonymous/Notice/Notice.js'),
            $this->publicPath('AutoDiscovery/AutoDiscoveryComponent.js'),
        ], $this->linkedScripts($html));
    }

    #[DataProvider('metadataCache')]
    public function testAutoDiscoveryOffLeavesAnonymousComponentsWithoutAssets(bool $metadataCache): void
    {
        $html = $this->requestPage(['metadata_cache' => $metadataCache, 'auto_discovery' => false]);

        $this->assertStringContainsString('<div class="notice"><i class="icon"></i>Saved</div>', $html);
        $this->assertSame([], $this->linkedStylesheets($html));
        $this->assertSame([], $this->linkedScripts($html));
    }

    /**
     * The build-time cache holds the anonymous components with assets, under
     * the template name Twig Components gives them, and none of the templates
     * of the component classes.
     */
    public function testTheMetadataCacheHoldsAnonymousComponentsByTemplate(): void
    {
        self::bootKernel(['configs' => ['metadata_cache' => true]]);

        $cachePath = self::getContainer()->getParameter('kernel.cache_dir') . '/sdc_metadata.php';
        $this->assertIsString($cachePath);
        $dump = require $cachePath;

        $this->assertIsArray($dump);
        // Without LiveComponent, LiveComponentWithAsset is a template and a stylesheet without a component class.
        $expected = ['Anonymous/Icon/Icon.html.twig', 'Anonymous/Notice/Notice.html.twig', ...(class_exists(LiveComponentBundle::class) ? [] : ['LiveComponentWithAsset.html.twig'])];
        $this->assertSame($expected, array_keys($dump['anonymous']));

        $registry = self::getContainer()->get(SdcMetadataRegistry::class);
        $this->assertInstanceOf(SdcMetadataRegistry::class, $registry);

        $notice = $registry->getAnonymousComponentMetadata('Anonymous/Notice/Notice.html.twig');
        $this->assertSame(['Anonymous/Notice/Notice.css', 'Anonymous/Notice/Notice.js'], array_column($notice->assets, 'path'));
        $this->assertNull($notice->template, 'Twig Components renders the template it found.');
        $this->assertSame([], $notice->problems);

        $this->assertTrue($registry->getAnonymousComponentMetadata('Anonymous/Plain/index.html.twig')->isEmpty());
        $this->assertTrue($registry->getAnonymousComponentMetadata('AutoDiscovery/AutoDiscoveryComponent.html.twig')->isEmpty());
    }

    /**
     * @param array<string, mixed> $configs
     */
    private function requestPage(array $configs): string
    {
        $client = self::createClient(['configs' => $configs]);
        $client->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();

        return (string) $client->getResponse()->getContent();
    }

    private function publicPath(string $logicalPath): string
    {
        $assetMapper = self::getContainer()->get(AssetMapperInterface::class);
        $this->assertInstanceOf(AssetMapperInterface::class, $assetMapper);

        $publicPath = $assetMapper->getAsset($logicalPath)?->publicPath;
        $this->assertNotNull($publicPath, \sprintf('"%s" is not in the asset map.', $logicalPath));

        return $publicPath;
    }

    /**
     * @return list<string>
     */
    private function linkedStylesheets(string $html): array
    {
        preg_match_all('~<link rel="stylesheet" href="([^"]+)" data-turbo-track="dynamic">~', $html, $matches);

        return $matches[1];
    }

    /**
     * @return list<string>
     */
    private function linkedScripts(string $html): array
    {
        preg_match_all('~<script src="([^"]+)" type="module"></script>~', $html, $matches);

        return $matches[1];
    }
}

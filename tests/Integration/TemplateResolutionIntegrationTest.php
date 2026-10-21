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
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Tito10047\UX\Sdc\Tests\Integration\Fixtures\Library\Badge\Badge;

/**
 * Which template a component renders with, and under which names its files
 * are loaded, through a whole request — in both metadata pipelines.
 */
final class TemplateResolutionIntegrationTest extends FunctionalTestCase
{
    /**
     * @return iterable<string, array{bool}>
     */
    public static function provideMetadataCache(): iterable
    {
        yield 'metadata resolved at build time' => [true];
        yield 'metadata resolved on render' => [false];
    }

    /**
     * A template named in #[AsTwigComponent] or #[AsSdcComponent] wins over the
     * one next to the class; the assets next to the class are still loaded.
     */
    #[DataProvider('provideMetadataCache')]
    public function testExplicitTemplateWinsOverTheCoLocatedOne(bool $metadataCache): void
    {
        $client = self::createClient(['configs' => ['metadata_cache' => $metadataCache]]);
        $client->request('GET', '/page/explicit_template.html.twig');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        $this->assertSame(2, substr_count($html, 'data-testid="explicit-template"'));
        $this->assertStringNotContainsString('co-located-template', $html);
        $this->assertStylesheetLinked($html, 'ExplicitTemplate/ExplicitTwigComponent.css');
        $this->assertStylesheetLinked($html, 'ExplicitTemplate/ExplicitSdcComponent.css');
    }

    #[DataProvider('provideMetadataCache')]
    public function testExplicitTemplateOfALiveComponentWins(bool $metadataCache): void
    {
        if (!class_exists(AsLiveComponent::class)) {
            self::markTestSkipped('symfony/ux-live-component is not installed.');
        }

        $client = self::createClient(['configs' => ['metadata_cache' => $metadataCache]]);
        $client->request('GET', '/page/explicit_live_template.html.twig');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        $this->assertStringContainsString('data-testid="explicit-template"', $html);
        $this->assertStringNotContainsString('co-located-template', $html);
    }

    /**
     * A component in a directory registered as a namespaced Twig path and as a
     * namespaced AssetMapper path: its template is loaded by its namespaced Twig
     * name, its assets by their AssetMapper logical paths. The two namespaces
     * are independent.
     */
    #[DataProvider('provideMetadataCache')]
    public function testComponentUnderNamespacedPaths(bool $metadataCache): void
    {
        $client = self::createClient([
            'configs' => ['metadata_cache' => $metadataCache],
            'twig' => ['paths' => ['%kernel.project_dir%/tests/Integration/Fixtures/Library' => 'ui']],
            // Relative, as in a Flex skeleton: AssetMapper resolves it against the project directory.
            'framework' => ['asset_mapper' => ['paths' => ['tests/Integration/Fixtures/Library' => 'lib']]],
            'services' => [Badge::class],
        ]);
        $client->request('GET', '/page/library.html.twig');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        $this->assertStringContainsString('data-testid="library-badge"', $html);
        $this->assertStylesheetLinked($html, 'lib/Badge/Badge.css');

        /** @var AssetMapperInterface $assetMapper */
        $assetMapper = self::getContainer()->get(AssetMapperInterface::class);
        $js = $assetMapper->getAsset('lib/Badge/Badge.js')?->publicPath;
        $this->assertNotNull($js);
        $this->assertSame(1, substr_count($html, \sprintf('<script src="%s" type="module"></script>', $js)));
    }

    private function assertStylesheetLinked(string $html, string $logicalPath): void
    {
        /** @var AssetMapperInterface $assetMapper */
        $assetMapper = self::getContainer()->get(AssetMapperInterface::class);
        $css = $assetMapper->getAsset($logicalPath)?->publicPath;

        $this->assertNotNull($css, \sprintf('"%s" is not in the asset map.', $logicalPath));
        $this->assertSame(1, substr_count($html, \sprintf('<link rel="stylesheet" href="%s">', $css)), \sprintf('"%s" is not linked once.', $logicalPath));
    }
}

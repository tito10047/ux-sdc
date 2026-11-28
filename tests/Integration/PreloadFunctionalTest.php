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

/**
 * Fonts and images declared with #[Asset] are preloaded, never loaded as
 * scripts. The Preload:Hero fixture has a discovered Hero.css, a font and an
 * image, neither of them with a declared type.
 */
final class PreloadFunctionalTest extends FunctionalTestCase
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
    public function testPreloadsFontsAndImagesWithATagAndALinkHeader(bool $metadataCache): void
    {
        $client = self::createClient(['configs' => ['metadata_cache' => $metadataCache]]);
        $client->request('GET', '/page/preload.html.twig');

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        $html = (string) $response->getContent();
        [$css, $font, $image] = $this->publicPaths('Preload/Hero.css', 'Preload/inter.woff2', 'Preload/hero.avif');

        $this->assertStringContainsString('data-testid="preload-hero"', $html);
        $this->assertSame(1, substr_count($html, \sprintf('<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>', $font)));
        $this->assertSame(1, substr_count($html, \sprintf('<link rel="preload" href="%s" as="image" type="image/avif" fetchpriority="high">', $image)));
        $this->assertSame(1, substr_count($html, \sprintf('<link rel="stylesheet" href="%s" data-turbo-track="dynamic">', $css)));
        $this->assertStringNotContainsString('<script', $html, 'Neither the font nor the image is loaded as a script.');

        // Sorted by priority (the image first), then in the order declared.
        $this->assertSame(
            \sprintf(
                '<%s>; rel="preload"; as="image"; type="image/avif"; fetchpriority="high",<%s>; rel="preload"; as="font"; type="font/woff2"; crossorigin,<%s>; rel="preload"; as="style"',
                $image,
                $font,
                $css,
            ),
            $response->headers->get('Link'),
        );
    }

    /**
     * A fragment inserts the stylesheet that uses the font as soon as it
     * arrives; there is nothing for a preload to win, and the header lists
     * only what the loader adds.
     */
    #[DataProvider('provideMetadataCache')]
    public function testAFragmentListsNeitherFontsNorImages(bool $metadataCache): void
    {
        $client = self::createClient(['configs' => ['metadata_cache' => $metadataCache]]);
        $client->request('GET', '/page/preload.html.twig', server: ['HTTP_TURBO_FRAME' => 'content']);

        self::assertResponseIsSuccessful();
        [$css] = $this->publicPaths('Preload/Hero.css');

        $this->assertSame(
            ['v' => 1, 'assets' => [['type' => 'css', 'href' => $css, 'attributes' => ['data-turbo-track' => 'dynamic']]]],
            json_decode((string) $client->getResponse()->headers->get('X-SDC-Assets'), true, flags: \JSON_THROW_ON_ERROR),
        );
        $this->assertNull($client->getResponse()->headers->get('Link'));
    }

    /**
     * @return list<string>
     */
    private function publicPaths(string ...$logicalPaths): array
    {
        /** @var AssetMapperInterface $assetMapper */
        $assetMapper = self::getContainer()->get(AssetMapperInterface::class);

        $paths = [];
        foreach ($logicalPaths as $logicalPath) {
            $path = $assetMapper->getAsset($logicalPath)?->publicPath;
            $this->assertNotNull($path, \sprintf('"%s" is not in the asset map.', $logicalPath));
            $paths[] = $path;
        }

        return $paths;
    }
}

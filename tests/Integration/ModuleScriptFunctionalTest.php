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
use Psr\Log\LogLevel;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Tito10047\UX\Sdc\Tests\TestLogger;

/**
 * Component scripts are ES modules: AssetMapper serves JavaScript as modules.
 * The Esm:Widget fixture has a discovered Widget.js and a legacy.js that opts
 * out with `attributes: ['type' => false]`.
 */
final class ModuleScriptFunctionalTest extends FunctionalTestCase
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
    public function testComponentScriptsAreModulesUnlessTheyOptOut(bool $metadataCache): void
    {
        $client = self::createClient(['configs' => ['metadata_cache' => $metadataCache]]);
        $client->request('GET', '/page/esm.html.twig');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        [$css, $module, $classic] = $this->publicPaths('Esm/Widget.css', 'Esm/Widget.js', 'Esm/legacy.js');

        $this->assertStringContainsString('data-testid="esm-widget"', $html);
        $this->assertSame(1, substr_count($html, \sprintf('<script src="%s" type="module"></script>', $module)));
        $this->assertSame(1, substr_count($html, \sprintf('<script src="%s"></script>', $classic)));
        $this->assertSame(1, substr_count($html, \sprintf('<link rel="stylesheet" href="%s" data-turbo-track="dynamic">', $css)));
        $this->assertStringNotContainsString('nonce', $html, 'No nonce without a nonce provider.');

        // The import map of the page precedes the module script.
        $this->assertLessThan(strpos($html, $module), strpos($html, '<script type="importmap">'));

        // Only the classic script (#[Asset], so first) and the stylesheet are
        // preloaded: a module gets neither `rel="preload"; as="script"` nor
        // `rel="modulepreload"`.
        $this->assertSame(
            \sprintf('<%s>; rel="preload"; as="script",<%s>; rel="preload"; as="style"', $classic, $css),
            $client->getResponse()->headers->get('Link'),
        );
    }

    public function testWarnsWhenTheImportMapComesAfterTheComponentModules(): void
    {
        $client = self::createClient(['compiler_passes' => [new RegisterTestLoggerPass()]]);
        $client->request('GET', '/page/esm_importmap_after.html.twig');

        self::assertResponseIsSuccessful();
        $warnings = $this->logger()->recordsOf(LogLevel::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertSame(['uri' => '/page/esm_importmap_after.html.twig'], $warnings[0]['context']);
        $this->assertStringContainsString('before its import map', $warnings[0]['message']);
    }

    public function testDoesNotWarnWhenTheImportMapComesFirst(): void
    {
        $client = self::createClient(['compiler_passes' => [new RegisterTestLoggerPass()]]);
        $client->request('GET', '/page/esm.html.twig');

        self::assertResponseIsSuccessful();
        $this->assertSame([], $this->logger()->recordsOf(LogLevel::WARNING));
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

    private function logger(): TestLogger
    {
        $logger = self::getContainer()->get('logger');
        $this->assertInstanceOf(TestLogger::class, $logger);

        return $logger;
    }
}

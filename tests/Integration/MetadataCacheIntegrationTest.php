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
use Tito10047\UX\Sdc\EventListener\ComponentRenderListener;
use Tito10047\UX\Sdc\EventListener\DevComponentRenderListener;
use Tito10047\UX\Sdc\Runtime\SdcMetadataRegistry;
use Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component\AutoDiscovery\AutoDiscoveryComponent;
use Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component\AutoDiscovery\SdcAutoDiscoveryComponent;

/**
 * `ux_sdc.metadata_cache` picks the pipeline from its own value, or from
 * kernel.debug when it is "auto" — never from the environment name. Either
 * pipeline has to deliver the same page.
 */
final class MetadataCacheIntegrationTest extends FunctionalTestCase
{
    private const PAGE = '/page/auto_discovery.html.twig';

    /**
     * @return iterable<string, array{bool|string|null, string, bool, bool}>
     */
    public static function provideKernels(): iterable
    {
        // [metadata_cache (null: not configured), environment, debug, cache expected]
        yield 'default in prod' => [null, 'prod', false, true];
        yield 'default in dev' => [null, 'dev', true, false];
        yield 'auto in prod' => ['auto', 'prod', false, true];
        yield 'auto in dev' => ['auto', 'dev', true, false];
        yield 'auto in staging with debug' => ['auto', 'staging', true, false];
        yield 'auto in staging without debug' => ['auto', 'staging', false, true];
        yield 'auto in test without debug' => ['auto', 'test', false, true];
        yield 'true in dev' => [true, 'dev', true, true];
        yield 'false in prod' => [false, 'prod', false, false];
    }

    #[DataProvider('provideKernels')]
    public function testPipelineFollowsTheOption(bool|string|null $metadataCache, string $environment, bool $debug, bool $cacheExpected): void
    {
        $configs = null === $metadataCache ? [] : ['metadata_cache' => $metadataCache];
        $client = self::createClient(['configs' => $configs, 'environment' => $environment, 'debug' => $debug]);
        $container = self::getContainer();

        $this->assertSame($cacheExpected, $container->has(ComponentRenderListener::class), 'The build-time cache listener.');
        $this->assertSame(!$cacheExpected, $container->has(DevComponentRenderListener::class), 'The runtime resolver listener.');

        $cachePath = $container->getParameter('kernel.cache_dir').'/sdc_metadata.php';
        $this->assertSame($cacheExpected, is_file($cachePath), 'The metadata cache file.');

        /** @var SdcMetadataRegistry $registry */
        $registry = $container->get(SdcMetadataRegistry::class);
        $this->assertSame($cacheExpected, [] !== $registry->getAssets(AutoDiscoveryComponent::class), 'The metadata in the registry.');

        // Without the cache, components are still registered with TwigComponent.
        $this->assertTrue($container->has(SdcAutoDiscoveryComponent::class));

        $client->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        $this->assertComponentAssetsDelivered((string) $client->getResponse()->getContent());
    }

    private function assertComponentAssetsDelivered(string $html): void
    {
        /** @var AssetMapperInterface $assetMapper */
        $assetMapper = self::getContainer()->get(AssetMapperInterface::class);
        $css = $assetMapper->getAsset('AutoDiscovery/AutoDiscoveryComponent.css')?->publicPath;
        $js = $assetMapper->getAsset('AutoDiscovery/AutoDiscoveryComponent.js')?->publicPath;

        $this->assertNotNull($css, 'The component stylesheet is not in the asset map.');
        $this->assertNotNull($js, 'The component script is not in the asset map.');

        $this->assertStringContainsString('data-testid="auto-discovery-component"', $html);
        $this->assertSame(1, substr_count($html, \sprintf('<link rel="stylesheet" href="%s">', $css)));
        $this->assertSame(1, substr_count($html, \sprintf('<script src="%s"></script>', $js)));
    }
}

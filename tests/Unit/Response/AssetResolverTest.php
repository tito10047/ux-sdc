<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Unit\Response;

use PHPUnit\Framework\TestCase;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\AssetMapper\MappedAsset;
use Tito10047\UX\Sdc\Response\AssetResolver;
use Tito10047\UX\Sdc\Response\ResolvedAsset;
use Tito10047\UX\Sdc\Tests\TestLogger;

final class AssetResolverTest extends TestCase
{
    private TestLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new TestLogger();
    }

    public function testLinksAMappedAssetByItsPublicPath(): void
    {
        $this->assertEquals(
            [
                new ResolvedAsset('/assets/Alert/Alert-1a2b3c.css', 'css'),
                new ResolvedAsset('/assets/Alert/Alert-4d5e6f.js', 'js', ['defer' => true]),
            ],
            $this->createResolver(true)->resolve([
                ['path' => 'Alert/Alert.css', 'type' => 'css', 'priority' => 0, 'attributes' => []],
                ['path' => 'Alert/Alert.js', 'type' => 'js', 'priority' => 0, 'attributes' => ['defer' => true]],
            ]),
        );
        $this->assertSame([], $this->logger->records);
    }

    /**
     * An asset registered without a type is a stylesheet or a script by its
     * public path; any other is left out.
     */
    public function testKeepsOnlyStylesheetsAndScripts(): void
    {
        $this->assertEquals(
            [
                new ResolvedAsset('/assets/Alert/Alert-1a2b3c.css', 'css'),
                new ResolvedAsset('https://cdn.example.com/x.js', 'js'),
            ],
            $this->createResolver(true)->resolve([
                ['path' => 'Alert/Alert.css', 'type' => '', 'priority' => 0, 'attributes' => []],
                ['path' => 'https://cdn.example.com/x.js', 'type' => '', 'priority' => 0, 'attributes' => []],
                ['path' => 'https://cdn.example.com/font.woff2', 'type' => '', 'priority' => 0, 'attributes' => []],
                ['path' => 'https://cdn.example.com/font.woff2', 'type' => 'font', 'priority' => 0, 'attributes' => []],
            ]),
        );
    }

    public function testLinksAnUnknownPathAsItIsAndWarnsInDebug(): void
    {
        $assets = [['path' => 'Alrt/Alert.css', 'type' => 'css', 'priority' => 0, 'attributes' => []]];

        $this->assertEquals([new ResolvedAsset('Alrt/Alert.css', 'css')], $this->createResolver(true)->resolve($assets));
        $this->assertCount(1, $this->logger->records);
        $this->assertSame(['path' => 'Alrt/Alert.css'], $this->logger->records[0]['context']);

        $this->logger->records = [];
        $this->createResolver(false)->resolve($assets);
        $this->assertSame([], $this->logger->records, 'Not outside debug.');
    }

    public function testDoesNotWarnAboutAUrl(): void
    {
        $this->createResolver(true)->resolve([
            ['path' => '/build/app.css', 'type' => 'css', 'priority' => 0, 'attributes' => []],
            ['path' => 'https://cdn.example.com/x.js', 'type' => 'js', 'priority' => 0, 'attributes' => []],
        ]);

        $this->assertSame([], $this->logger->records);
    }

    /**
     * The asset map knows Alert/Alert.css and Alert/Alert.js.
     */
    private function createResolver(bool $debug): AssetResolver
    {
        $assetMapper = $this->createMock(AssetMapperInterface::class);
        $assetMapper->method('getAsset')->willReturnCallback(static fn (string $logicalPath): ?MappedAsset => match ($logicalPath) {
            'Alert/Alert.css' => new MappedAsset($logicalPath, publicPath: '/assets/Alert/Alert-1a2b3c.css'),
            'Alert/Alert.js' => new MappedAsset($logicalPath, publicPath: '/assets/Alert/Alert-4d5e6f.js'),
            default => null,
        });

        return new AssetResolver($assetMapper, $this->logger, $debug);
    }
}

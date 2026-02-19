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

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Tito10047\UX\Sdc\Runtime\SdcMetadataRegistry;
use Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component\LiveComponentWithAsset;

class LiveComponentAssetIntegrationTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testLiveComponentAutoDiscoveryWorks(): void
    {
        if (!class_exists(\Symfony\UX\LiveComponent\Attribute\AsLiveComponent::class)) {
            self::markTestSkipped('symfony/ux-live-component is not installed.');
        }

        $kernel = new TestKernel(['auto_discovery' => true]);
        $kernel->boot();
        $container = $kernel->getContainer();

        /** @var SdcMetadataRegistry $metadataRegistry */
        $metadataRegistry = $container->get(SdcMetadataRegistry::class);
        $assets = $metadataRegistry->getMetadata(LiveComponentWithAsset::class);

        $this->assertCount(2, $assets);
        $this->assertEquals('LiveComponentWithAsset.css', $assets[0]['path']);
        $this->assertEquals('css', $assets[0]['type']);
        $this->assertEquals('LiveComponentWithAsset.js', $assets[1]['path']);
        $this->assertEquals('js', $assets[1]['type']);
    }

    public function testCanRenderAndInteract(): void
    {
        if (!trait_exists(InteractsWithLiveComponents::class)) {
            self::markTestSkipped('symfony/ux-live-component is not installed.');
        }

        $testCase = new LiveComponentTestCase('test');
        $testCase->doTest();
    }
}

/**
 * Internal class to avoid anonymous class issues in Symfony 6.4.
 * @internal
 */
class LiveComponentTestCase extends KernelTestCase
{
    use InteractsWithLiveComponents;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function doTest(): void
    {
        self::bootKernel();

        $testComponent = $this->createLiveComponent(
            name: 'LiveComponentWithAsset',
        );

        $response = $testComponent->render();
        $rendered = (string) $response;

        $this->assertStringContainsString('Live Component With Asset', $rendered);
        $this->assertStringContainsString('controller: LiveComponentWithAsset', $rendered);

        $response = $testComponent->response();
        $this->assertStringContainsString('LiveComponentWithAsset.css', $response->headers->get('X-SDC-Assets-CSS'));
        $this->assertStringContainsString('LiveComponentWithAsset.js', $response->headers->get('X-SDC-Assets-JS'));
    }
}

<?php

namespace Tito10047\UX\Sdc\Tests\Unit\EventListener;

use PHPUnit\Framework\TestCase;
use Symfony\UX\TwigComponent\Event\PostMountEvent;
use Tito10047\UX\Sdc\Runtime\SdcMetadataRegistry;
use Tito10047\UX\Sdc\EventListener\ComponentRenderListener;
use Tito10047\UX\Sdc\Service\AssetRegistry;

final class ComponentRenderListenerTest extends TestCase
{
    public function testOnPostMountAddsAssetsToRegistry(): void
    {
        $cachePath = sys_get_temp_dir() . '/listener_test_metadata.php';
        $component = new class {};
        $componentClass = $component::class;
        $data = [
            $componentClass => [
                ['path' => 'comp.css', 'type' => 'css', 'priority' => 10, 'attributes' => []]
            ]
        ];
        file_put_contents($cachePath, '<?php return ' . var_export($data, true) . ';');

        $metadataRegistry = new SdcMetadataRegistry($cachePath);
        $registry = new AssetRegistry();
        $listener = new ComponentRenderListener($metadataRegistry, $registry);

        $event = new PostMountEvent($component, []);
        $listener->onPostMount($event);

        $assets = $registry->getSortedAssets();
        $this->assertCount(1, $assets);
        $this->assertSame('comp.css', $assets[0]['path']);

        unlink($cachePath);
    }
}

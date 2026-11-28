<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Tito10047\UX\Sdc\Routing\ComponentRouteLoader;

/**
 * The cases that never reach the attribute loader; loading the routes of a real
 * directory is covered by ComponentRoutesFunctionalTest.
 */
class ComponentRouteLoaderTest extends TestCase
{
    public function testSupportsItsTypeOnly(): void
    {
        $loader = new ComponentRouteLoader(null, null);

        $this->assertTrue($loader->supports('.', 'ux_sdc'));
        $this->assertFalse($loader->supports('.', 'attribute'));
        $this->assertFalse($loader->supports('.'));
    }

    public function testLoadsNothingWhenTheBundleContributesNoRoutes(): void
    {
        $collection = (new ComponentRouteLoader(null, null))->load('.', 'ux_sdc');

        $this->assertCount(0, $collection);
        $this->assertSame([], $collection->getResources());
    }

    public function testTracksADirectoryThatDoesNotExistYet(): void
    {
        $directory = sys_get_temp_dir().'/ux-sdc-missing-'.bin2hex(random_bytes(4));

        $collection = (new ComponentRouteLoader($directory, 'App\\Component\\'))->load('.', 'ux_sdc');

        $this->assertCount(0, $collection);
        $this->assertEquals([new FileExistenceResource($directory)], $collection->getResources());
    }
}

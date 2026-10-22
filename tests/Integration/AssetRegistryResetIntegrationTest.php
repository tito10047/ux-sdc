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

use Tito10047\UX\Sdc\Service\AssetRegistry;
use Twig\Environment;

/**
 * Long-running runtimes (FrankenPHP worker mode, RoadRunner) keep the container
 * between requests and call `services_resetter` in between. The registry must not
 * carry the assets of one response into the next.
 */
final class AssetRegistryResetIntegrationTest extends IntegrationTestCase
{
    public function testServicesResetterEmptiesTheRegistry(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var Environment $twig */
        $twig = $container->get(Environment::class);
        $twig->render('base.html.twig');

        /** @var AssetRegistry $registry */
        $registry = $container->get(AssetRegistry::class);
        $this->assertNotEmpty($registry->getSortedAssets(), 'Rendering a component should have collected its assets.');

        $container->get('services_resetter')->reset();

        $this->assertSame([], $registry->getSortedAssets());
    }
}

<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\EventListener;

use Symfony\UX\TwigComponent\ComponentAttributes;
use Symfony\UX\TwigComponent\Event\PreRenderEvent;
use Tito10047\UX\Sdc\Service\AssetRegistry;
use Tito10047\UX\Sdc\Twig\ComponentNamespaceInterface;

/**
 * @phpstan-import-type SdcAsset from AssetRegistry
 */
trait ComponentRenderTrait
{
    private function setComponentNamespace(object $component): void
    {
        if ($component instanceof ComponentNamespaceInterface && null !== $this->componentNamespace) {
            $component->setComponentNamespace($this->componentNamespace);
        }
    }

    /**
     * @param list<SdcAsset> $assets
     */
    private function addAssets(array $assets): void
    {
        foreach ($assets as $asset) {
            $this->assetRegistry->addAsset(
                $asset['path'],
                $asset['type'] ?: (str_ends_with($asset['path'], '.css') ? 'css' : 'js'),
                $asset['priority'],
                $asset['attributes']
            );
        }
    }
}

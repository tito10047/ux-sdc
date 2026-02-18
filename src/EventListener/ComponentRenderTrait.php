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
use Tito10047\UX\Sdc\Twig\ComponentNamespaceInterface;

trait ComponentRenderTrait
{
    private function setComponentNamespace(object $component): void
    {
        if ($component instanceof ComponentNamespaceInterface && null !== $this->componentNamespace) {
            $component->setComponentNamespace($this->componentNamespace);
        }
    }

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

    private function injectAssetAttributes(PreRenderEvent $event, array $assets): void
    {
        $css = [];
        $js = [];
        foreach ($assets as $asset) {
            $type = $asset['type'] ?: (str_ends_with($asset['path'], '.css') ? 'css' : 'js');
            if ('css' === $type) {
                $css[] = $asset['path'];
            } elseif ('js' === $type) {
                $js[] = $asset['path'];
            }
        }

        if (!$css && !$js) {
            return;
        }

        $variables = $event->getVariables();
        $attributesVar = $event->getMetadata()->getAttributesVar();
        $bag = $variables[$attributesVar] ?? null;
        if ($bag instanceof ComponentAttributes) {
            $extra = [];
            if ($css) {
                $extra['data-sdc-css'] = implode(',', array_unique($css));
            }
            if ($js) {
                $extra['data-sdc-js'] = implode(',', array_unique($js));
            }
            if ($extra) {
                $variables[$attributesVar] = $bag->defaults($extra);
                $event->setVariables($variables);
            }
        }
    }
}

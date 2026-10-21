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

use Symfony\UX\TwigComponent\Event\PreRenderEvent;
use Tito10047\UX\Sdc\Service\AssetRegistry;
use Tito10047\UX\Sdc\Service\ComponentMetadata;
use Tito10047\UX\Sdc\Twig\ComponentNamespaceInterface;

/**
 * @phpstan-import-type SdcAsset from AssetRegistry
 *
 * @internal
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
                $asset['type'],
                $asset['priority'],
                $asset['attributes']
            );
        }
    }

    /**
     * Records the render for the profiler, once the template is final. Without
     * the profiler there is no trace, and nothing to do.
     */
    private function traceRender(PreRenderEvent $event, ComponentMetadata $componentMetadata): void
    {
        $metadata = $event->getMetadata();

        $this->trace?->componentRendered(
            $metadata->getName(),
            $metadata->isAnonymous() ? null : $metadata->getClass(),
            $event->getTemplate(),
            $componentMetadata,
        );
    }

    /**
     * Sets the `controller` template variable of a component in
     * `component_namespace` to the name of its Stimulus controller.
     *
     * A `controller` the component exposes itself wins (the Stimulus trait, a
     * public property, #[ExposeInTemplate]). Anything else already in the
     * variables does not: an embedded component (`<twig:X>…</twig:X>`) receives
     * the context of the template around it, so the `controller` found there
     * may be that of the parent component or of the page.
     *
     * An anonymous component gets none. Its controller would be named after
     * the path of its template in the component directory, but its template
     * name does not tell which Twig path holds the file, and finding out
     * would take a filesystem lookup on every render.
     */
    private function setStimulusController(PreRenderEvent $event): void
    {
        $metadata = $event->getMetadata();
        if ($metadata->isAnonymous()) {
            return;
        }

        $controller = $this->controllerNameResolver->resolve($metadata->getClass());
        if (null === $controller) {
            return;
        }

        if ($this->controllerNameResolver->isExposedByComponent($metadata->getClass(), $metadata->isPublicPropsExposed())) {
            return;
        }

        $variables = $event->getVariables();
        $variables['controller'] = $controller;
        $event->setVariables($variables);
    }
}

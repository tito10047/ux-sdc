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

use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\UX\TwigComponent\Event\PostMountEvent;
use Symfony\UX\TwigComponent\Event\PreRenderEvent;
use Tito10047\UX\Sdc\Service\AssetRegistry;
use Tito10047\UX\Sdc\Service\ComponentMetadataResolver;
use Tito10047\UX\Sdc\Service\StimulusControllerNameResolver;

/**
 * Resolves the metadata of a component when it renders, from the files next to
 * its class, or to its template for an anonymous component
 * (`ux_sdc.metadata_cache` false). The resolver caches it per class and template.
 *
 * Wired like ComponentRenderListener, which it replaces in that mode.
 */
final class DevComponentRenderListener
{
    use ComponentRenderTrait;

    public function __construct(
        private ComponentMetadataResolver $metadataResolver,
        private AssetRegistry $assetRegistry,
        private StimulusControllerNameResolver $controllerNameResolver,
        private ?string $componentNamespace = null
    ) {
    }

    public function onPostMount(PostMountEvent $event): void
    {
        $this->setComponentNamespace($event->getComponent());
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $controller = $event->getController();

        if (\is_array($controller)) {
            $controller = $controller[0];
        }

        // Static callables ("Class::method", [Class::class, 'method']) have no instance to configure.
        if (\is_object($controller)) {
            $this->setComponentNamespace($controller);
        }
    }

    public function onPreRender(PreRenderEvent $event): void
    {
        $metadata = $event->getMetadata();
        if ($metadata->isAnonymous()) {
            $this->addAssets($this->metadataResolver->resolveAnonymous($metadata->getTemplate())->assets);

            return;
        }

        $componentMetadata = $this->metadataResolver->resolve($metadata->getClass());

        $this->addAssets($componentMetadata->assets);

        if (null !== $componentMetadata->template) {
            $event->setTemplate($componentMetadata->template);
        }

        $this->setStimulusController($event);
    }
}

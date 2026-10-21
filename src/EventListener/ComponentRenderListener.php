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
use Tito10047\UX\Sdc\DataCollector\RequestTrace;
use Tito10047\UX\Sdc\Runtime\SdcMetadataRegistry;
use Tito10047\UX\Sdc\Service\AssetRegistry;
use Tito10047\UX\Sdc\Service\StimulusControllerNameResolver;

/**
 * Reads the metadata of a component from the cache dumped when the container
 * is built (`ux_sdc.metadata_cache` true).
 *
 * Its events are tagged in config/services.php; SdcExtension keeps either this
 * listener or DevComponentRenderListener.
 *
 * @internal
 */
final class ComponentRenderListener
{
    use ComponentRenderTrait;

    public function __construct(
        private SdcMetadataRegistry $metadataRegistry,
        private AssetRegistry $assetRegistry,
        private StimulusControllerNameResolver $controllerNameResolver,
        private ?string $componentNamespace = null,
        private ?RequestTrace $trace = null,
    ) {
    }

    public function onPostMount(PostMountEvent $event): void
    {
        $component = $event->getComponent();

        $this->setComponentNamespace($component);

        // An anonymous component is known by its template, which onPreRender() gets.
        if (true !== $event->getMetadata()?->isAnonymous()) {
            $this->addAssets($this->metadataRegistry->getAssets($component::class));
        }
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
            $componentMetadata = $this->metadataRegistry->getAnonymousComponentMetadata($metadata->getTemplate());
            $this->addAssets($componentMetadata->assets);
            $this->traceRender($event, $componentMetadata);

            return;
        }

        $component = $event->getComponent();
        $template = $this->metadataRegistry->getTemplate($component::class);

        if (null !== $template) {
            $event->setTemplate($template);
        }

        $this->addAssets($this->metadataRegistry->getAssets($component::class));

        $this->setStimulusController($event);

        // Only the profiler needs the whole metadata; rendering takes just the
        // assets and the template, without building an object per render.
        if (null !== $this->trace) {
            $this->traceRender($event, $this->metadataRegistry->getComponentMetadata($component::class));
        }
    }
}

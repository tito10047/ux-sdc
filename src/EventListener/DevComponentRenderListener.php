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

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\UX\TwigComponent\Event\PostMountEvent;
use Symfony\UX\TwigComponent\Event\PreRenderEvent;
use Tito10047\UX\Sdc\Service\AssetRegistry;
use Tito10047\UX\Sdc\Service\ComponentMetadataResolver;

/**
 * @phpstan-import-type SdcMetadata from ComponentMetadataResolver
 */
final class DevComponentRenderListener
{
    use ComponentRenderTrait;

    /**
     * Per-component resolution cache, deliberately kept for the lifetime of the
     * process and not reset between requests (this listener is not tagged
     * `kernel.reset`).
     *
     * Every entry is derived from the component class alone — its attributes and
     * the files sitting next to it — never from the request, so a worker serving
     * many requests can safely reuse it. The per-request state lives in
     * AssetRegistry, which is reset. Clearing this cache as well would only make
     * every request re-run reflection and filesystem lookups.
     *
     * The listener is used when `ux_sdc.metadata_cache` resolves to false, debug
     * or not. Under PHP-FPM each request gets a fresh instance, so an asset file
     * added next to a component shows up on the next request; a long-running
     * worker picks it up once the worker restarts.
     *
     * @var SdcMetadata
     */
    private array $runtimeMetadata = [];

    public function __construct(
        private ComponentMetadataResolver $metadataResolver,
        private AssetRegistry $assetRegistry,
        private ?string $componentNamespace = null
    ) {
    }

    #[AsEventListener(event: PostMountEvent::class)]
    public function onPostMount(PostMountEvent $event): void
    {
        $this->setComponentNamespace($event->getComponent());
    }

    #[AsEventListener(event: ControllerEvent::class)]
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

    #[AsEventListener(event: PreRenderEvent::class)]
    public function onPreRender(PreRenderEvent $event): void
    {
        $metadata = $event->getMetadata();
        if ($metadata->isAnonymous()) {
            return;
        }
        $componentName = $metadata->getName();

        $assets = $this->runtimeMetadata[$componentName] ?? null;
        if (!\is_array($assets)) {
            $componentClass = $metadata->getClass();
            $assets = $this->metadataResolver->resolveMetadata($componentClass, $componentName, $this->runtimeMetadata);
            $this->runtimeMetadata[$componentName] = $assets;
        }

        $this->addAssets($assets);

        $templatePath = $this->runtimeMetadata[$componentName . '_template'] ?? null;
        if (is_string($templatePath)) {
            $event->setTemplate($templatePath);
        }
    }
}

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
use Symfony\UX\TwigComponent\ComponentAttributes;
use Symfony\UX\TwigComponent\Event\PostMountEvent;
use Symfony\UX\TwigComponent\Event\PreRenderEvent;
use Tito10047\UX\Sdc\Service\AssetRegistry;
use Tito10047\UX\Sdc\Service\ComponentMetadataResolver;
use Tito10047\UX\Sdc\Twig\ComponentNamespaceInterface;

final class DevComponentRenderListener
{
    use ComponentRenderTrait;

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

    #[AsEventListener(event: PreRenderEvent::class)]
    public function onPreRender(PreRenderEvent $event): void
    {
        $metadata = $event->getMetadata();
        if ($metadata->isAnonymous()) {
            return;
        }
        $componentName = $metadata->getName();

        if (isset($this->runtimeMetadata[$componentName])) {
            $assets = $this->runtimeMetadata[$componentName];
        } else {
            $componentClass = $metadata->getClass();
            $assets = $this->metadataResolver->resolveMetadata($componentClass, $componentName, $this->runtimeMetadata);
            $this->runtimeMetadata[$componentName] = $assets;
        }

        $this->addAssets($assets);

        // Inject data-sdc-css and data-sdc-js into attributes bag for dynamic loading on live updates
        $this->injectAssetAttributes($event, $assets);

        $templatePath = $this->runtimeMetadata[$componentName . '_template'] ?? null;
        if (is_string($templatePath)) {
            $event->setTemplate($templatePath);
        }
    }
}

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
use Tito10047\UX\Sdc\Runtime\SdcMetadataRegistry;
use Tito10047\UX\Sdc\Service\AssetRegistry;
use Tito10047\UX\Sdc\Twig\ComponentNamespaceInterface;

final class ComponentRenderListener
{
    use ComponentRenderTrait;

    public function __construct(
        private SdcMetadataRegistry $metadataRegistry,
        private AssetRegistry $assetRegistry,
        private ?string $componentNamespace = null
    ) {
    }

    #[AsEventListener(event: PostMountEvent::class)]
    public function onPostMount(PostMountEvent $event): void
    {
        $component = $event->getComponent();

        $this->setComponentNamespace($component);

        $assets = $this->metadataRegistry->getMetadata($component::class);

        if (!$assets) {
            return;
        }

        $this->addAssets($assets);
    }

    #[AsEventListener(event: PreRenderEvent::class)]
    public function onPreRender(PreRenderEvent $event): void
    {
        $component = $event->getComponent();
        $templatePath = $this->metadataRegistry->getMetadata($component::class . '_template');

        if ($templatePath) {
            $event->setTemplate($templatePath);
        }

        // Inject data-sdc-css and data-sdc-js attributes so they render via {{ attributes }}
        $assets = $this->metadataRegistry->getMetadata($component::class);
        if (!$assets) {
            return;
        }

        $this->injectAssetAttributes($event, $assets);
    }
}

<?php

namespace Tito10047\UX\Sdc\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\UX\TwigComponent\Event\PostMountEvent;
use Symfony\UX\TwigComponent\Event\PreRenderEvent;
use Tito10047\UX\Sdc\Runtime\SdcMetadataRegistry;
use Tito10047\UX\Sdc\Service\AssetRegistry;
use Tito10047\UX\Sdc\Twig\ComponentNamespaceInterface;

final class ComponentRenderListener
{
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

        if ($component instanceof ComponentNamespaceInterface && null !== $this->componentNamespace) {
            $component->setComponentNamespace($this->componentNamespace);
        }

        $assets = $this->metadataRegistry->getMetadata($component::class);

        if (!$assets) {
            return;
        }

        foreach ($assets as $asset) {
            $this->assetRegistry->addAsset(
                $asset['path'],
                $asset['type'] ?: (str_ends_with($asset['path'], '.css') ? 'css' : 'js'),
                $asset['priority'],
                $asset['attributes']
            );
        }
    }

    #[AsEventListener(event: PreRenderEvent::class)]
    public function onPreRender(PreRenderEvent $event): void
    {
		$component = $event->getComponent();
        $templatePath = $this->metadataRegistry->getMetadata($component::class . '_template');

        if ($templatePath) {
            $event->setTemplate($templatePath);
        }
    }
}

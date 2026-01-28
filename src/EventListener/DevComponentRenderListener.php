<?php

namespace Tito10047\UX\Sdc\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\UX\TwigComponent\Event\PostMountEvent;
use Symfony\UX\TwigComponent\Event\PreRenderEvent;
use Tito10047\UX\Sdc\Service\AssetRegistry;
use Tito10047\UX\Sdc\Service\ComponentMetadataResolver;
use Tito10047\UX\Sdc\Twig\ComponentNamespaceInterface;

final class DevComponentRenderListener
{
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
        $component = $event->getComponent();
        if ($component instanceof ComponentNamespaceInterface && null !== $this->componentNamespace) {
            $component->setComponentNamespace($this->componentNamespace);
        }
    }

    #[AsEventListener(event: PreRenderEvent::class)]
    public function onPreRender(PreRenderEvent $event): void
    {
        $metadata = $event->getMetadata();
		if ($metadata->isAnonymous()){
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

        foreach ($assets as $asset) {
            $this->assetRegistry->addAsset(
                $asset['path'],
                $asset['type'] ?: (str_ends_with($asset['path'], '.css') ? 'css' : 'js'),
                $asset['priority'],
                $asset['attributes']
            );
        }

        $templatePath = $this->runtimeMetadata[$componentName . '_template'] ?? null;
        if (is_string($templatePath)) {
            $event->setTemplate($templatePath);
        }
    }
}

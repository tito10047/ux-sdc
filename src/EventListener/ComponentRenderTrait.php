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

use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;
use Symfony\UX\TwigComponent\Event\PreRenderEvent;
use Tito10047\UX\Sdc\Service\AssetRegistry;
use Tito10047\UX\Sdc\Twig\ComponentNamespaceInterface;

/**
 * @phpstan-import-type SdcAsset from AssetRegistry
 */
trait ComponentRenderTrait
{
    /**
     * Per component class: whether it exposes a `controller` variable with
     * #[ExposeInTemplate], and whether it declares a public `controller` property.
     *
     * @var array<class-string, array{bool, bool}>
     */
    private array $ownControllerVariables = [];

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

    /**
     * Sets the `controller` template variable of a component in
     * `component_namespace` to the name of its Stimulus controller.
     *
     * A `controller` the component exposes itself wins (the Stimulus trait, a
     * public property, #[ExposeInTemplate]). Anything else already in the
     * variables does not: an embedded component (`<twig:X>…</twig:X>`) receives
     * the context of the template around it, so the `controller` found there
     * may be that of the parent component or of the page.
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

        [$exposedByAttribute, $hasPublicProperty] = $this->ownControllerVariables[$metadata->getClass()]
            ??= self::findOwnControllerVariable($metadata->getClass());

        if ($exposedByAttribute || ($hasPublicProperty && $metadata->isPublicPropsExposed())) {
            return;
        }

        $variables = $event->getVariables();
        $variables['controller'] = $controller;
        $event->setVariables($variables);
    }

    /**
     * Mirrors how TwigComponent names the variables of a component class: a
     * property is exposed under the name given to #[ExposeInTemplate] or its
     * own (private ones of parent classes included), a public method under
     * that name or its own without a "get" prefix, and public properties under
     * their own when `exposePublicProps` is on. Destructured values are not
     * looked into: their keys are only known once the component has run.
     *
     * @param class-string $class
     *
     * @return array{bool, bool}
     */
    private static function findOwnControllerVariable(string $class): array
    {
        $reflection = new \ReflectionClass($class);

        $hasPublicProperty = $reflection->hasProperty('controller')
            && $reflection->getProperty('controller')->isPublic()
            && !$reflection->getProperty('controller')->isStatic();

        for ($declaringClass = $reflection; false !== $declaringClass; $declaringClass = $declaringClass->getParentClass()) {
            foreach ($declaringClass->getProperties() as $property) {
                if ('controller' === self::exposedName($property, $property->name)) {
                    return [true, $hasPublicProperty];
                }
            }
        }

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $defaultName = str_starts_with($method->name, 'get') ? lcfirst(substr($method->name, 3)) : $method->name;

            if ('controller' === self::exposedName($method, $defaultName)) {
                return [true, $hasPublicProperty];
            }
        }

        return [false, $hasPublicProperty];
    }

    private static function exposedName(\ReflectionProperty|\ReflectionMethod $member, string $defaultName): ?string
    {
        $attribute = ($member->getAttributes(ExposeInTemplate::class)[0] ?? null)?->newInstance();

        if (null === $attribute || $attribute->destruct) {
            return null;
        }

        return $attribute->name ?? $defaultName;
    }
}

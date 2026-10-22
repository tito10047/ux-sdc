<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Service;

use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;

/**
 * Names the Stimulus controller of a component after its class.
 *
 * The component directory is a StimulusBundle controller path, and
 * StimulusBundle (ControllersMapGenerator) names a controller after its path
 * relative to that directory: the `_controller.js` (or `-controller.js`)
 * suffix is dropped, `/` becomes `--` and `_` becomes `-`; the case is kept.
 * The class `App\Component\UI\Date_Picker\Date_Picker` lives in
 * `UI/Date_Picker/Date_Picker.php`, so the controller next to it,
 * `UI/Date_Picker/Date_Picker_controller.js`, is registered as
 * `UI--Date-Picker--Date-Picker` — which is what this resolver derives from
 * the class name relative to `component_namespace`.
 *
 * The bundle hands that name to the template as `controller`, unless the
 * component exposes a `controller` variable of its own.
 *
 * @internal
 */
final class StimulusControllerNameResolver
{
    /** The namespace with a trailing backslash, or null when none is configured. */
    private readonly ?string $componentNamespace;

    /**
     * Per component class: whether it exposes a `controller` variable with
     * #[ExposeInTemplate], and whether it declares a public `controller`
     * property. Derived from the class alone, so kept for the lifetime of the
     * process.
     *
     * @var array<string, array{bool, bool}>
     */
    private array $ownControllerVariables = [];

    public function __construct(?string $componentNamespace)
    {
        $componentNamespace = trim((string) $componentNamespace, '\\');

        $this->componentNamespace = '' === $componentNamespace ? null : $componentNamespace.'\\';
    }

    /**
     * @return string|null null when no namespace is configured or the class is outside it
     */
    public function resolve(string $class): ?string
    {
        $class = ltrim($class, '\\');

        if (null === $this->componentNamespace || !str_starts_with($class, $this->componentNamespace)) {
            return null;
        }

        return strtr(substr($class, \strlen($this->componentNamespace)), ['\\' => '--', '_' => '-']);
    }

    /**
     * Whether the component exposes a `controller` template variable of its
     * own — the Stimulus trait, #[ExposeInTemplate], or a public property when
     * public properties are exposed — which then wins over the derived name.
     *
     * @param class-string $class
     */
    public function isExposedByComponent(string $class, bool $publicPropsExposed): bool
    {
        [$exposedByAttribute, $hasPublicProperty] = $this->ownControllerVariables[$class]
            ??= self::findOwnControllerVariable($class);

        return $exposedByAttribute || ($hasPublicProperty && $publicPropsExposed);
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

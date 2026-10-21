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
 * @internal
 */
final class StimulusControllerNameResolver
{
    /** The namespace with a trailing backslash, or null when none is configured. */
    private readonly ?string $componentNamespace;

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
}

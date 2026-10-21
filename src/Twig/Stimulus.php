<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Twig;

use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;
use Tito10047\UX\Sdc\Service\StimulusControllerNameResolver;

/**
 * Gives a class the name of its Stimulus controller, in PHP (getController())
 * and in its template ({{ controller }}). The class must also implement
 * ComponentNamespaceInterface, which is how the bundle hands it the namespace.
 *
 * Components do not need it for the template variable: the bundle sets
 * `controller` for every component in `component_namespace`. Use it when the
 * name is needed in PHP, or on a controller class.
 */
trait Stimulus
{
    private ?string $componentNamespace = null;

    public function setComponentNamespace(string $namespace): void
    {
        $this->componentNamespace = $namespace;
    }

    #[ExposeInTemplate("controller")]
    public function getController(): string
    {
        if (null === $this->componentNamespace) {
            throw new \LogicException('Component namespace is not set. Did you forget to implement ComponentNamespaceInterface?');
        }

        return (new StimulusControllerNameResolver($this->componentNamespace))->resolve(self::class)
            ?? throw new \LogicException(\sprintf('Cannot name the Stimulus controller of "%s": the class is not in the component namespace "%s", so no controller in the component directory is named after it.', self::class, $this->componentNamespace));
    }
}

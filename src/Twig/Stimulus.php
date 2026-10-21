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

        $controller = self::class;
        $controller = str_replace($this->componentNamespace, "", $controller);
        return str_replace("\\", "--", ltrim($controller, "\\"));
    }
}

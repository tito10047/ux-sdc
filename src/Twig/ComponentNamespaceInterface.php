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

/**
 * Hands `ux_sdc.component_namespace` to a class using the Stimulus trait: the
 * bundle calls setComponentNamespace() on a component after it is mounted, and
 * on a controller before it is called.
 */
interface ComponentNamespaceInterface
{
    public function setComponentNamespace(string $namespace): void;

    public function getController(): string;
}

<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Icons\Twig;

use Twig\Extension\AbstractExtension;

final class ComponentIconExtension extends AbstractExtension
{
    public function __construct(
        private readonly ComponentIconNodeVisitor $visitor,
    ) {
    }

    public function getNodeVisitors(): array
    {
        return [$this->visitor];
    }
}

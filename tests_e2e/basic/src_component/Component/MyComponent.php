<?php

/*
 * This file is part of the Progressive Image Bundle.
 *
 * (c) Jozef Môstka <https://github.com/tito10047/progressive-image-bundle>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Component\Component;

use Tito10047\UX\TwigComponentSdc\Attribute\AsSdcComponent;

#[AsSdcComponent] // No need to define names, templates, or assets. It's all inferred!
class MyComponent
{
    public string $type = 'info';
    public string $message;
}

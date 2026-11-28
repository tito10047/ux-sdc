<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component\Failing;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Fails while its template renders, after its assets are registered.
 */
#[AsTwigComponent]
class Broken
{
    public function getData(): string
    {
        throw new \RuntimeException('The component could not load its data.');
    }
}

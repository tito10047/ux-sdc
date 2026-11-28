<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Unit\Service\Fixtures\SdcAlert;

use Tito10047\UX\Sdc\Attribute\AsSdcComponent;

/**
 * Assets named by #[AsSdcComponent], and a stylesheet next to the class.
 */
#[AsSdcComponent(css: 'css/sdc.css', js: 'js/sdc.js')]
final class SdcAlert
{
}

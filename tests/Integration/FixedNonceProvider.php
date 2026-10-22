<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Integration;

use Tito10047\UX\Sdc\Csp\NonceProviderInterface;

/**
 * An application's own nonce provider, with nonces known to the test.
 */
final class FixedNonceProvider implements NonceProviderInterface
{
    public const SCRIPT_NONCE = 'c2NyaXB0LW5vbmNl';
    public const STYLE_NONCE = 'c3R5bGUtbm9uY2U=';

    public function getScriptNonce(): string
    {
        return self::SCRIPT_NONCE;
    }

    public function getStyleNonce(): string
    {
        return self::STYLE_NONCE;
    }
}

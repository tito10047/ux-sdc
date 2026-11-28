<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Csp;

/**
 * Supplies the Content Security Policy nonces of the current request, which the
 * bundle puts on the `<script>` and `<link>` tags it injects.
 *
 * Select an implementation with `ux_sdc.csp_nonce_provider`. It is called on
 * `kernel.response` of the main request, before most other response listeners
 * (see the priority in `config/services.php`), and only for the kinds of tags the
 * response actually gets: `getScriptNonce()` when it injects a script,
 * `getStyleNonce()` when it injects a stylesheet.
 *
 * Return the nonce the `Content-Security-Policy` header of this response allows,
 * or null to inject the tags without one. The nonce belongs to one request: an
 * implementation that keeps it in a property has to forget it when the request
 * ends (`kernel.reset`), or a long-running worker would hand it to the next one.
 */
interface NonceProviderInterface
{
    public function getScriptNonce(): ?string;

    public function getStyleNonce(): ?string;
}

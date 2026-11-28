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

use Nelmio\SecurityBundle\EventListener\ContentSecurityPolicyListener;

/**
 * The nonces of NelmioSecurityBundle's Content Security Policy, the ones its
 * `csp_nonce()` Twig function prints.
 *
 * Registered as a service when NelmioSecurityBundle is enabled; select it with
 * `ux_sdc.csp_nonce_provider: Tito10047\UX\Sdc\Csp\NelmioNonceProvider`. It needs
 * the bundle's `csp` feature on.
 *
 * Asking Nelmio for a nonce is what adds it to the policy: Nelmio writes the
 * `Content-Security-Policy` header on `kernel.response` at priority 0, after the
 * bundle's listener (64) has asked. Nelmio forgets the nonce once the header is
 * written, so every request gets its own.
 */
final class NelmioNonceProvider implements NonceProviderInterface
{
    public function __construct(
        private ContentSecurityPolicyListener $listener,
    ) {
    }

    public function getScriptNonce(): string
    {
        return $this->listener->getNonce('script');
    }

    public function getStyleNonce(): string
    {
        return $this->listener->getNonce('style');
    }
}

<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\CompilerPass;

use Psr\Log\AbstractLogger;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * A PSR-3 logger for code that runs while the container is being built, where
 * the `logger` service does not exist yet: it writes to the compiler log
 * instead, the channel Symfony's own compiler passes report through.
 *
 * The kernel keeps that log for debug containers only, in
 * `var/cache/<env>/<ContainerClass>Compiler.log`, and the web profiler shows it
 * in its Logs panel under "Container".
 *
 * @internal
 */
final class CompilerLogLogger extends AbstractLogger
{
    public function __construct(
        private readonly ContainerBuilder $container,
        private readonly CompilerPassInterface $pass,
    ) {
    }

    /**
     * Untyped arguments: the signature has to be compatible with every
     * supported major version of psr/log.
     *
     * @param mixed                $level
     * @param string|\Stringable   $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $replacements = [];
        foreach ($context as $key => $value) {
            if (null === $value || \is_scalar($value) || $value instanceof \Stringable) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }

        $this->container->log($this->pass, \sprintf('[%s] %s', \is_scalar($level) ? $level : get_debug_type($level), strtr((string) $message, $replacements)));
    }
}

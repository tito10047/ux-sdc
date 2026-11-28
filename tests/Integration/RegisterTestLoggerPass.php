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

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tito10047\UX\Sdc\Tests\TestLogger;

/**
 * Makes the `logger` service a public TestLogger, the way MonologBundle would
 * provide one. It runs before FrameworkBundle's LoggerPass, which then leaves
 * it alone instead of registering its stderr logger.
 */
final class RegisterTestLoggerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $container->register('logger', TestLogger::class)->setPublic(true);
    }
}

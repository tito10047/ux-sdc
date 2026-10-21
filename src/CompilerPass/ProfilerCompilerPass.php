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

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tito10047\UX\Sdc\DataCollector\RequestTrace;
use Tito10047\UX\Sdc\DataCollector\SdcDataCollector;

/**
 * Removes the data collector and its trace from a debug container without a
 * profiler (`framework.profiler` off, or no FrameworkBundle profiler at all).
 *
 * Nothing would collect them, yet the trace would go on recording every
 * render: the services recording into it reference it as an optional
 * dependency, which keeps a service alive. Removed before the references are
 * resolved, they get null, as in a container without debug.
 *
 * Whether the profiler exists is known only once every extension is loaded —
 * FrameworkBundle registers it, and its extension may come after this one —
 * so it is decided here rather than in SdcExtension. ProfilerPass of
 * FrameworkBundle makes the same check.
 */
final class ProfilerCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition('profiler')) {
            return;
        }

        $container->removeDefinition(SdcDataCollector::class);
        $container->removeDefinition(RequestTrace::class);
    }
}

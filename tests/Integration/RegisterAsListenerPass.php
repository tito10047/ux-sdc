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

/**
 * Re-registers a service of another bundle as a plain `kernel.event_listener` with
 * the given priority, to put the listeners of an event in an order a test needs.
 *
 * An event subscriber loses all its subscriptions but this one, so only use it
 * on subscribers of a single event.
 */
final class RegisterAsListenerPass implements CompilerPassInterface
{
    public function __construct(
        private readonly string $serviceId,
        private readonly string $event,
        private readonly string $method,
        private readonly int $priority,
    ) {
    }

    public function process(ContainerBuilder $container): void
    {
        $definition = $container->getDefinition($this->serviceId);

        $definition->clearTag('kernel.event_subscriber');
        $definition->clearTag('kernel.event_listener');
        $definition->addTag('kernel.event_listener', [
            'event' => $this->event,
            'method' => $this->method,
            'priority' => $this->priority,
        ]);
    }
}

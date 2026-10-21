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
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Tito10047\UX\Sdc\Attribute\AsSdcComponent;

/**
 * Makes #[AsSdcComponent] register a Twig component the way #[AsTwigComponent] does.
 *
 * TwigComponentBundle autoconfigures classes carrying #[AsTwigComponent]. Up to
 * Symfony 7.0, AttributeAutoconfigurationPass looks configurators up by the exact
 * attribute class, so a class carrying only the AsSdcComponent subclass was never
 * tagged "twig.component" and did not exist as a component. Later versions walk up
 * the attribute's class hierarchy and find the parent's configurator themselves.
 *
 * Instead of copying what TwigComponentBundle does with the attribute, its own
 * configurator is registered for AsSdcComponent too. Symfony 7.1 and 7.2 walk the
 * hierarchy but still have the old API, and get the same configurator they would
 * have found themselves. Symfony 7.3 replaced getAutoconfiguredAttributes() with
 * getAttributeAutoconfigurators(); where the latter exists there is nothing to do.
 *
 * Runs before AttributeAutoconfigurationPass (priority 100), after every
 * extension has registered its configurators.
 *
 * @internal
 */
final class SdcComponentAttributePass implements CompilerPassInterface
{
    public const PRIORITY = 110;

    public function process(ContainerBuilder $container): void
    {
        // Checked first: on 7.3 and 7.4 the old method still exists, deprecated.
        // @phpstan-ignore function.alreadyNarrowedType (analysed against the newest Symfony; absent before 7.3)
        if (method_exists($container, 'getAttributeAutoconfigurators')) {
            return;
        }

        if (!method_exists($container, 'getAutoconfiguredAttributes')) {
            return;
        }

        $configurators = $container->getAutoconfiguredAttributes();

        // A configurator registered for AsSdcComponent on purpose wins.
        if (isset($configurators[AsSdcComponent::class]) || !isset($configurators[AsTwigComponent::class])) {
            return;
        }

        $container->registerAttributeForAutoconfiguration(AsSdcComponent::class, $configurators[AsTwigComponent::class]);
    }
}

<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Twig;

use Tito10047\UX\Sdc\UxSdcBundle;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

final class AssetExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private string $placeholder = '<!-- __UX_TWIG_COMPONENT_ASSETS__ -->'
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('render_component_assets', [$this, 'renderAssets'], ['is_safe' => ['html']]),
        ];
    }

    public function renderAssets(): string
    {
        return $this->placeholder;
    }

    public function getGlobals(): array
    {
        return [
            'sdc_loader_controller' => UxSdcBundle::STIMULUS_CONTROLLER,
        ];
    }
}

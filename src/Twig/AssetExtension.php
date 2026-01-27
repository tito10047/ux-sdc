<?php

namespace Tito10047\UX\Sdc\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AssetExtension extends AbstractExtension
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
}

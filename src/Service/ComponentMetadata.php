<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Service;

/**
 * What ComponentMetadataResolver found for one component class: the assets to
 * load with it and the template sitting next to it, if any.
 *
 * @phpstan-import-type SdcAsset from AssetRegistry
 */
final class ComponentMetadata
{
    /**
     * @param list<SdcAsset> $assets
     * @param string|null    $template the Twig name of the co-located template; null lets Twig Components pick the template
     */
    public function __construct(
        public readonly array $assets = [],
        public readonly ?string $template = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->assets && null === $this->template;
    }
}

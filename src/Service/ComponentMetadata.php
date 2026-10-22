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
 * What ComponentMetadataResolver found for one component class, or for the
 * template of one anonymous component: the assets to load with it, the
 * template sitting next to the class, if any, and the files next to it that
 * could not be used.
 *
 * `SdcComponentAsset` is an asset as AssetRegistry takes it, plus where it was
 * found: next to the class or template, in #[Asset] or in #[AsSdcComponent].
 *
 * `SdcProblem` is a file left out, as a PSR-3 message and its context: the
 * resolver logs it as is, and `debug:sdc` shows it.
 *
 * @phpstan-type SdcComponentAsset array{path: string, type: string, priority: int, attributes: array<string, mixed>, source: self::SOURCE_*}
 * @phpstan-type SdcProblem array{message: string, context: array<string, string>}
 *
 * @internal
 */
final class ComponentMetadata
{
    public const SOURCE_DISCOVERED = 'discovered';
    public const SOURCE_ASSET_ATTRIBUTE = '#[Asset]';
    public const SOURCE_SDC_COMPONENT_ATTRIBUTE = '#[AsSdcComponent]';

    /**
     * @param list<SdcComponentAsset> $assets
     * @param string|null             $template the Twig name of the co-located template; null lets Twig Components pick the template
     * @param list<SdcProblem>        $problems
     */
    public function __construct(
        public readonly array $assets = [],
        public readonly ?string $template = null,
        public readonly array $problems = [],
    ) {
    }

    /**
     * Whether there is nothing to load with the component, and nothing to report about it.
     */
    public function isEmpty(): bool
    {
        return [] === $this->assets && null === $this->template && [] === $this->problems;
    }

    /**
     * @return list<string> the problems, with their placeholders replaced
     */
    public function getProblemMessages(): array
    {
        return array_map(
            static function (array $problem): string {
                $replacements = [];
                foreach ($problem['context'] as $key => $value) {
                    $replacements['{' . $key . '}'] = $value;
                }

                return strtr($problem['message'], $replacements);
            },
            $this->problems,
        );
    }
}

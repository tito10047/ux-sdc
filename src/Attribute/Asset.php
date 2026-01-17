<?php

namespace Tito10047\UX\TwigComponentSdc\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Asset
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public ?string $path = null,
        public ?string $type = null,
        public int $priority = 0,
        public array $attributes = [],
    ) {
    }
}

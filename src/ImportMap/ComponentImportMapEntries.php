<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\ImportMap;

use Symfony\Component\AssetMapper\AssetMapperRepository;
use Symfony\Component\AssetMapper\ImportMap\ImportMapEntry;
use Symfony\Component\AssetMapper\ImportMap\ImportMapType;
use Symfony\Component\AssetMapper\Path\PublicAssetsPathResolverInterface;
use Tito10047\UX\Sdc\Attribute\Asset;
use Tito10047\UX\Sdc\Response\ResolvedAsset;
use Tito10047\UX\Sdc\Runtime\SdcMetadataRegistry;
use Tito10047\UX\Sdc\Service\ComponentMetadata;
use Tito10047\UX\Sdc\Service\ComponentMetadataResolver;

/**
 * The module scripts of every component, as entries of the import map.
 *
 * A component script is loaded by a `<script type="module">` of its own, not
 * from an entrypoint, so AssetMapper never sees the files it imports.
 * AssetMapper leaves a relative import (`import './format.js'`) as it is and
 * maps the unversioned URL it resolves to (`/assets/Card/format.js`) to the
 * versioned file in the import map — but only for the modules it walks: those
 * of `importmap.php` and, transitively, what they import. As an entry of the
 * import map, a component script is one of them; its relative imports then get
 * their entries, as those of the application's own modules do.
 *
 * The entries are those of every component, not only of those a page renders:
 * a component a Live Component re-render or a Turbo Frame inserts later needs
 * them in the import map the page already has, and a browser takes one import
 * map only (Firefox does not merge a second one). Every page also gets the same
 * import map, so that Turbo Drive does not see a new one on each visit.
 *
 * Each entry is named after the unversioned public path of the script
 * (`/assets/Card/Card.js`), the name AssetMapper gives the target of a relative
 * import: it is no bare module name the application could mean otherwise. A
 * classic script (`'type' => false`) cannot `import`, a URL is not served by
 * AssetMapper and a path the asset map does not know would fail the import map
 * of every page: none of them gets an entry.
 *
 * The scripts come from where the pages get them: the build-time cache with
 * `ux_sdc.metadata_cache` on, the resolver otherwise — so that, as on render,
 * a script added next to a component is picked up without clearing the cache.
 * The resolver is one of its own, without a logger: a file left out is
 * reported when its component renders, not on every page with an import map.
 *
 * @internal
 */
final class ComponentImportMapEntries
{
    /** @var array<string, ImportMapEntry>|null */
    private ?array $entries = null;

    /**
     * @param bool         $metadataCache      whether `ux_sdc.metadata_cache` resolved to true
     * @param list<string> $componentClasses   the classes of the `twig.component` services
     * @param list<string> $anonymousTemplates the templates of anonymous components, as Twig Components names them
     */
    public function __construct(
        private readonly SdcMetadataRegistry $metadataRegistry,
        private readonly ComponentMetadataResolver $metadataResolver,
        private readonly bool $metadataCache,
        private readonly AssetMapperRepository $assetMapperRepository,
        private readonly PublicAssetsPathResolverInterface $publicAssetsPathResolver,
        private readonly array $componentClasses = [],
        private readonly array $anonymousTemplates = [],
    ) {
    }

    /**
     * Reads no compiled asset: it is called while AssetMapper compiles one, to
     * look up a bare import, and compiling the scripts here would recurse.
     *
     * @return array<string, ImportMapEntry> import name => entry
     */
    public function all(): array
    {
        if (null !== $this->entries) {
            return $this->entries;
        }

        $entries = [];
        foreach ($this->collectMetadata() as $metadata) {
            foreach ($metadata->assets as $asset) {
                $path = $asset['path'];
                if (Asset::JS !== $asset['type'] || !(new ResolvedAsset($path, Asset::JS, $asset['attributes']))->isModule()) {
                    continue;
                }

                // A URL is not in the asset map either.
                if (null === $this->assetMapperRepository->find($path)) {
                    continue;
                }

                $importName = $this->publicAssetsPathResolver->resolvePublicPath($path);
                $entries[$importName] ??= ImportMapEntry::createLocal($importName, ImportMapType::JS, $path, false);
            }
        }

        return $this->entries = $entries;
    }

    /**
     * @return iterable<ComponentMetadata>
     */
    private function collectMetadata(): iterable
    {
        foreach ($this->componentClasses as $class) {
            if ($this->metadataCache) {
                yield $this->metadataRegistry->getComponentMetadata($class);

                continue;
            }

            try {
                yield $this->metadataResolver->resolve($class);
            } catch (\InvalidArgumentException) {
                // An invalid #[Asset]: the component fails with the reason when it renders.
            }
        }

        foreach ($this->anonymousTemplates as $template) {
            yield $this->metadataCache
                ? $this->metadataRegistry->getAnonymousComponentMetadata($template)
                : $this->metadataResolver->resolveAnonymous($template);
        }
    }
}

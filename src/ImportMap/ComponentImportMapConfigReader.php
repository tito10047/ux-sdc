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

use Symfony\Component\AssetMapper\ImportMap\ImportMapConfigReader;
use Symfony\Component\AssetMapper\ImportMap\ImportMapEntries;

/**
 * The entries of `importmap.php`, followed by the module scripts of the
 * components (ComponentImportMapEntries).
 *
 * AssetMapper reads its root entries from this service alone: the import map
 * of a page, `importmap.json` written by `asset-map:compile`, the lookup of a
 * bare import. A component script listed here is therefore walked like any
 * module of `importmap.php`, and the files it imports relatively get their
 * entries in the import map. An entry of the application of the same name
 * wins.
 *
 * The component entries are never written: `importmap:require`, `remove` and
 * `update` read the entries, change them and write them back, and get
 * `importmap.php` back without them.
 *
 * FrameworkBundle's `asset_mapper.importmap.config_reader` service is built
 * from this class, with its own arguments (ImportMapCompilerPass); the
 * component entries come through setComponentEntries(), so that the
 * constructor stays AssetMapper's.
 *
 * @internal
 */
final class ComponentImportMapConfigReader extends ImportMapConfigReader
{
    private ?ComponentImportMapEntries $componentEntries = null;

    public function setComponentEntries(ComponentImportMapEntries $componentEntries): void
    {
        $this->componentEntries = $componentEntries;
    }

    public function getEntries(): ImportMapEntries
    {
        $applicationEntries = parent::getEntries();
        $componentEntries = $this->componentEntries?->all() ?? [];

        if ([] === $componentEntries) {
            return $applicationEntries;
        }

        // A new collection every time: the caller may change it, and only
        // writeEntries() is to change what the application has.
        $entries = new ImportMapEntries(iterator_to_array($applicationEntries));
        foreach ($componentEntries as $importName => $entry) {
            if (!$entries->has($importName)) {
                $entries->add($entry);
            }
        }

        return $entries;
    }

    public function writeEntries(ImportMapEntries $entries): void
    {
        $componentEntries = $this->componentEntries?->all() ?? [];

        $applicationEntries = new ImportMapEntries();
        foreach ($entries as $entry) {
            // The very entry getEntries() added; one the application wrote
            // under the same name is another object, and is kept.
            if (($componentEntries[$entry->importName] ?? null) !== $entry) {
                $applicationEntries->add($entry);
            }
        }

        parent::writeEntries($applicationEntries);
    }
}

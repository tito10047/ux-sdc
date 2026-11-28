<?php

declare(strict_types=1);

/*
 * Checks that every bare module specifier ("@hotwired/stimulus", not "./x.js")
 * imported by the compiled JavaScript of the bundle has an entry in the
 * import map of the application.
 *
 * asset-map:compile does not check it: "missing_import_mode" is about relative
 * imports, a bare one it cannot resolve is left to the browser, which fails
 * to load the module ("Failed to resolve module specifier") and with it every
 * Stimulus controller of the page.
 *
 * Usage: php assert_imports_resolve.php <public directory of the application>
 */

if (2 !== $argc) {
    fwrite(STDERR, "Usage: php assert_imports_resolve.php <public directory>\n");
    exit(2);
}

$assets = $argv[1].'/assets';
$importMap = json_decode((string) file_get_contents($assets.'/importmap.json'), true, 512, JSON_THROW_ON_ERROR);
$files = glob($assets.'/@tito10047/ux-sdc/*.js') ?: [];
if ([] === $files) {
    fwrite(STDERR, "No compiled JavaScript of the bundle in {$assets}/@tito10047/ux-sdc/.\n");
    exit(1);
}

$pattern = '/(?:^|[;\s])(?:import|export)\s*(?:[\w$*{},\s]+?\s*from\s*)?["\']([^"\']+)["\']|\bimport\(\s*["\']([^"\']+)["\']\s*\)/';
$failed = false;
foreach ($files as $file) {
    preg_match_all($pattern, (string) file_get_contents($file), $matches);
    $specifiers = array_unique(array_filter([...$matches[1], ...$matches[2]]));
    foreach ($specifiers as $specifier) {
        if (preg_match('#^(\.{0,2}/|[a-z][a-z0-9+.-]*:)#i', $specifier)) {
            continue; // a relative or absolute URL, which the browser resolves itself
        }
        $relative = substr($file, strlen($argv[1]));
        if (isset($importMap[$specifier])) {
            echo "  ok: {$relative} imports \"{$specifier}\", which is in the import map\n";
        } else {
            echo "  {$relative} imports \"{$specifier}\", which is not in the import map\n";
            $failed = true;
        }
    }
}

if ($failed) {
    echo '  import map entries: '.implode(', ', array_keys($importMap))."\n";
    exit(1);
}

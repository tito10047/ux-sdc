<?php

declare(strict_types=1);

/*
 * Applies the Flex recipe in tests_e2e/recipe/ to an application, the way
 * symfony/flex would: the configurators the recipe uses (bundles,
 * copy-from-recipe, add-lines), with the same rules.
 *
 * The recipe is proposed to symfony/recipes-contrib and is not available from
 * a Flex endpoint yet, so the end-to-end test cannot let Flex install it.
 * Whatever the recipe does differently from this script is not tested; what
 * matters is that its files and its line in templates/base.html.twig are what
 * the end-to-end test runs against.
 *
 * Unlike Flex, a line that cannot be added is an error, not a warning: in the
 * end-to-end test, it is the failure to find.
 *
 * Usage: php apply_recipe.php <recipe directory> <application directory>
 */

if (3 !== $argc) {
    fwrite(STDERR, "Usage: php apply_recipe.php <recipe directory> <application directory>\n");
    exit(2);
}

[, $recipeDir, $appDir] = $argv;

function fail(string $message): never
{
    fwrite(STDERR, "apply_recipe: {$message}\n");
    exit(1);
}

function note(string $message): void
{
    echo "  recipe: {$message}\n";
}

$manifest = json_decode((string) file_get_contents($recipeDir.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($manifest)) {
    fail('manifest.json is not a JSON object.');
}

$unsupported = array_diff(array_keys($manifest), ['bundles', 'copy-from-recipe', 'add-lines']);
if ([] !== $unsupported) {
    fail(sprintf('The manifest uses configurators this script does not apply: %s. Add them here.', implode(', ', $unsupported)));
}

// "bundles": register each bundle in config/bundles.php, unless it is already there.
$bundlesFile = $appDir.'/config/bundles.php';
foreach ($manifest['bundles'] ?? [] as $class => $environments) {
    $bundles = require $bundlesFile;
    if (isset($bundles[$class])) {
        note("{$class} is already registered");
        continue;
    }
    $envs = implode(', ', array_map(static fn (string $env): string => var_export($env, true).' => true', $environments));
    $contents = (string) file_get_contents($bundlesFile);
    $position = strrpos($contents, '];');
    if (false === $position) {
        fail("{$bundlesFile} does not end with \"];\".");
    }
    file_put_contents($bundlesFile, substr_replace($contents, "    {$class}::class => [{$envs}],\n", $position, 0));
    note("registered {$class}");
}

// "copy-from-recipe": copy files that do not exist yet; Flex does not overwrite either.
foreach ($manifest['copy-from-recipe'] ?? [] as $source => $target) {
    $target = str_replace('%CONFIG_DIR%', 'config', $target);
    $sourcePath = $recipeDir.'/'.$source;
    $files = is_dir($sourcePath)
        ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourcePath, FilesystemIterator::SKIP_DOTS))
        : [new SplFileInfo($sourcePath)];
    foreach ($files as $file) {
        $relative = is_dir($sourcePath) ? substr($file->getPathname(), strlen(rtrim($sourcePath, '/')) + 1) : '';
        $destination = $appDir.'/'.rtrim($target, '/').('' === $relative ? '' : '/'.$relative);
        if (file_exists($destination)) {
            note("kept the existing {$destination}");
            continue;
        }
        if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0777, true)) {
            fail('Cannot create '.dirname($destination));
        }
        if (!copy($file->getPathname(), $destination)) {
            fail("Cannot copy {$file->getPathname()} to {$destination}");
        }
        note("created {$destination}");
    }
}

// "add-lines": the positions this recipe uses. A line already in the file is not added twice.
foreach ($manifest['add-lines'] ?? [] as $line) {
    $file = $appDir.'/'.$line['file'];
    if (!is_file($file)) {
        fail("{$line['file']} does not exist, so \"{$line['content']}\" cannot be added to it.");
    }
    $contents = (string) file_get_contents($file);
    if (str_contains($contents, $line['content'])) {
        note("{$line['file']} already contains the line");
        continue;
    }
    $lines = explode("\n", $contents);
    switch ($line['position']) {
        case 'top':
            array_unshift($lines, $line['content']);
            break;
        case 'bottom':
            $lines[] = $line['content'];
            break;
        case 'after_target':
            $index = null;
            foreach ($lines as $i => $candidate) {
                if (str_contains($candidate, $line['target'])) {
                    $index = $i;
                    break;
                }
            }
            if (null === $index) {
                fail("{$line['file']} has no line containing \"{$line['target']}\", so \"{$line['content']}\" cannot be added after it.");
            }
            array_splice($lines, $index + 1, 0, [$line['content']]);
            break;
        default:
            fail("Unsupported add-lines position \"{$line['position']}\".");
    }
    file_put_contents($file, implode("\n", $lines));
    note("added \"".trim($line['content'])."\" to {$line['file']}");
}

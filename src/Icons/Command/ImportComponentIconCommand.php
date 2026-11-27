<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Icons\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\UX\Icons\Iconify;
use Symfony\UX\TwigComponent\ComponentFactory;

/**
 * Imports icons from Iconify into a component's directory.
 *
 *     bin/console ux:sdc:icons:import Alert mdi:home tabler:x
 *     bin/console ux:sdc:icons:import Alert tabler:arrow-right --as=arrow
 *
 * The icon is saved under its bare name (home.svg), which is how the
 * component's template asks for it: `<twig:ux:icon name="home" />`.
 *
 * The import itself is ux-icons' Iconify service: `ux:icons:import` cannot be
 * reused, it is final and always writes to the global icon directory.
 */
#[AsCommand(
    name: 'ux:sdc:icons:import',
    description: 'Import icon(s) from iconify.design into a component\'s directory',
)]
final class ImportComponentIconCommand extends Command
{
    public function __construct(
        private readonly Iconify $iconify,
        private readonly ?ComponentFactory $componentFactory,
        private readonly string $componentsDir,
        private readonly string $subdir = '',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('component', InputArgument::REQUIRED, 'Component name (e.g. "Alert") or a directory inside the components directory')
            ->addArgument('names', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Icon name from ux.symfony.com/icons (e.g. "mdi:home")')
            ->addOption('as', null, InputOption::VALUE_REQUIRED, 'Save the icon under this name (only with a single icon)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite an icon the component already has')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $names = $input->getArgument('names');
        $as = $input->getOption('as');

        if (null !== $as && 1 !== \count($names)) {
            $io->error('The --as option can be used with a single icon only.');

            return Command::INVALID;
        }

        if (null !== $as && !preg_match('#^[a-z0-9]+(?:-[a-z0-9]+)*$#', $as)) {
            $io->error(\sprintf('"%s" is not a valid icon name: use lowercase letters, digits and dashes.', $as));

            return Command::INVALID;
        }

        try {
            $directory = $this->componentDirectory($input->getArgument('component'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $target = $directory . ('' !== trim($this->subdir, '/') ? '/' . trim($this->subdir, '/') : '');

        $byPrefix = [];
        foreach ($names as $name) {
            if (!preg_match('#^([\w-]+):([\w-]+)$#', $name, $matches)) {
                $io->error(\sprintf('Invalid icon name "%s", expected "prefix:name".', $name));

                return Command::INVALID;
            }
            $byPrefix[$matches[1]][$matches[2]] = $matches[2];
        }

        $filesystem = new Filesystem();
        $imported = 0;
        $lastSaved = '';
        $result = Command::SUCCESS;

        foreach ($byPrefix as $prefix => $icons) {
            if (!$this->iconify->hasIconSet($prefix)) {
                $io->error(\sprintf('Icon set "%s" not found.', $prefix));
                $result = Command::FAILURE;

                continue;
            }

            $metadata = $this->iconify->metadataFor($prefix);
            $io->writeln(\sprintf(' Icon set: %s (License: %s)', $metadata['name'], $metadata['license']['title']));

            foreach ($this->iconify->chunk($prefix, array_keys($icons)) as $chunk) {
                $fetched = $this->iconify->fetchIcons($prefix, $chunk);

                foreach ($chunk as $icon) {
                    if (null === $svg = $fetched[$icon] ?? null) {
                        $io->writeln(\sprintf(' <fg=red;options=bold>✗</> Not Found %s:%s', $prefix, $icon));
                        $result = Command::FAILURE;

                        continue;
                    }

                    $file = \sprintf('%s/%s.svg', $target, $as ?? $icon);
                    if (is_file($file) && !$input->getOption('force')) {
                        $io->writeln(\sprintf(' <fg=yellow;options=bold>!</> Skipped %s:%s, %s already exists (use --force)', $prefix, $icon, $this->relative($file)));

                        continue;
                    }

                    $filesystem->dumpFile($file, (string) $svg);
                    ++$imported;
                    $lastSaved = $as ?? $icon;
                    $io->writeln(\sprintf(' <fg=bright-green;options=bold>✓</> Imported %s:%s → %s', $prefix, $icon, $this->relative($file)));
                }
            }
        }

        if ($imported > 0) {
            $io->success(\sprintf('Imported %d/%d icons. Use them in the component\'s template as <twig:ux:icon name="%s" />.', $imported, \count($names), $lastSaved));
        }

        return $result;
    }

    private function componentDirectory(string $component): string
    {
        $root = realpath($this->componentsDir);
        if (false === $root) {
            throw new \InvalidArgumentException(\sprintf('The components directory "%s" does not exist.', $this->componentsDir));
        }

        // a directory, relative to the components directory or absolute
        foreach ([$root . '/' . $component, $component] as $candidate) {
            if (is_dir($candidate) && str_starts_with((string) realpath($candidate) . '/', $root . '/')) {
                return (string) realpath($candidate);
            }
        }

        if (null === $this->componentFactory) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a directory inside "%s".', $component, $root));
        }

        try {
            $class = $this->componentFactory->metadataFor($component)->getClass();
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException(\sprintf('"%s" is neither a component nor a directory inside "%s".', $component, $root), previous: $e);
        }

        $file = (new \ReflectionClass($class))->getFileName();
        $directory = $file ? \dirname($file) : null;

        if (null === $directory || !str_starts_with($directory . '/', $root . '/')) {
            throw new \InvalidArgumentException(\sprintf('The component "%s" (%s) does not live in the components directory "%s", so its templates would not find a local icon.', $component, $class, $root));
        }

        return $directory;
    }

    private function relative(string $file): string
    {
        $cwd = getcwd() ?: '';

        return '' !== $cwd && str_starts_with($file, $cwd . '/') ? substr($file, \strlen($cwd) + 1) : $file;
    }
}

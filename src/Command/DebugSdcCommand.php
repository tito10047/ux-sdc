<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tito10047\UX\Sdc\Debug\ComponentInspector;
use Tito10047\UX\Sdc\Debug\ComponentReport;
use Tito10047\UX\Sdc\Service\ComponentMetadata;

/**
 * Shows what the bundle adds to each component — template, CSS, JS, Stimulus
 * controller, routes — and what keeps any of it from loading. Anonymous
 * components are listed too, with the assets next to their template.
 *
 * It complements `debug:twig-component`, which shows the props and hooks of a
 * component.
 *
 * Like the other `debug:*` commands, it exits with 0 whatever it finds;
 * `--strict` makes problems fail it, for a CI step.
 *
 * @phpstan-import-type ReportedAsset from ComponentReport
 *
 * @internal
 */
#[AsCommand(name: 'debug:sdc', description: 'Display the template, assets and Stimulus controller of each component, and what keeps them from loading')]
final class DebugSdcCommand extends Command
{
    private const FORMATS = ['txt', 'json'];

    private const PROBLEM = '<fg=yellow>⚠</> ';

    public function __construct(
        private readonly ComponentInspector $inspector,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDefinition([
                new InputArgument('name', InputArgument::OPTIONAL, 'A component name or class, or a part of it'),
                new InputOption('format', null, InputOption::VALUE_REQUIRED, \sprintf('The output format ("%s")', implode('", "', self::FORMATS)), 'txt', self::FORMATS),
                new InputOption('strict', null, InputOption::VALUE_NONE, 'Exit with a non-zero code when a problem is found'),
            ])
            ->setHelp(<<<'EOF'
                The <info>%command.name%</info> command displays the components of the application
                as UX SDC renders them: the template, the CSS and JS with their public paths,
                the Stimulus controller and the routes of the controllers next to the class.
                Anonymous components (a template without a class) are marked <comment>(anonymous)</comment>.
                It flags (<fg=yellow>⚠</>) what keeps a part of a component from loading.

                  <info>php %command.full_name%</info>

                Give a component name or class, or a part of it (case-insensitive), to see only
                the matching components. A single match is shown in detail:

                  <info>php %command.full_name% Alert</info>

                With <info>--strict</info>, the command fails when it finds a problem, e.g. in a CI step:

                  <info>php %command.full_name% --strict</info>

                The metadata comes from where the pages get it: the build-time cache with
                <comment>ux_sdc.metadata_cache</comment> on, the files next to each component otherwise.
                The props of a component are shown by <info>debug:twig-component</info>.
                EOF);
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        // The values of --format.
        parent::complete($input, $suggestions);

        if ($input->mustSuggestArgumentValuesFor('name')) {
            $suggestions->suggestValues(array_keys($this->inspector->listComponents()));
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $format = $input->getOption('format');
        if (!\in_array($format, self::FORMATS, true)) {
            throw new InvalidArgumentException(\sprintf('The format "%s" is not supported. Supported formats are "%s".', \is_scalar($format) ? $format : get_debug_type($format), implode('", "', self::FORMATS)));
        }

        $components = $this->inspector->listComponents();

        $name = $input->getArgument('name');
        if (\is_string($name)) {
            $components = self::filter($components, $name);

            if ([] === $components) {
                $io->getErrorStyle()->error(\sprintf('No component matches "%s".', $name));

                return Command::FAILURE;
            }
        }

        $reports = [];
        foreach (array_keys($components) as $componentName) {
            $reports[] = $this->inspector->inspect($componentName);
        }

        if ('json' === $format) {
            $this->renderJson($output, $reports);
        } elseif (\is_string($name) && 1 === \count($reports)) {
            $this->renderDetail($io, $reports[0]);
        } else {
            $this->renderList($io, $reports);
        }

        if ($input->getOption('strict')) {
            foreach ($reports as $report) {
                if ([] !== $report->problems) {
                    return Command::FAILURE;
                }
            }
        }

        return Command::SUCCESS;
    }

    /**
     * An exact name or class selects that component alone; anything else
     * every component whose name or class contains it.
     *
     * @param array<string, string|null> $components name => class, null for an anonymous component
     *
     * @return array<string, string|null>
     */
    private static function filter(array $components, string $search): array
    {
        $class = ltrim($search, '\\');

        foreach ($components as $componentName => $componentClass) {
            if ($componentName === $search || $componentClass === $class) {
                return [$componentName => $componentClass];
            }
        }

        return array_filter(
            $components,
            static fn (?string $componentClass, string $componentName): bool => false !== stripos($componentName, $search) || (null !== $componentClass && false !== stripos($componentClass, $class)),
            \ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @param list<ComponentReport> $reports
     */
    private function renderList(SymfonyStyle $io, array $reports): void
    {
        $rows = [];
        foreach ($reports as $report) {
            $rows[] = [
                ([] === $report->problems ? '' : self::PROBLEM) . OutputFormatter::escape($report->name),
                $this->formatTemplate($report),
                $this->formatAssets($report, 'css', false),
                $this->formatAssets($report, 'js', false),
                $this->formatStimulusController($report, false),
            ];
        }

        $io->table(['Component', 'Template', 'CSS', 'JS', 'Stimulus controller'], $rows);

        $this->renderSummary($io, $reports);
    }

    private function renderDetail(SymfonyStyle $io, ComponentReport $report): void
    {
        $routes = array_map(
            static fn (array $route): string => OutputFormatter::escape(\sprintf('%s (%s)', $route['name'], $route['path'])),
            $report->routes,
        );

        $io->table(['Property', 'Value'], [
            ['Name', OutputFormatter::escape($report->name)],
            ['Class', null === $report->class ? '<comment>none (anonymous component)</comment>' : OutputFormatter::escape($report->class)],
            ['Template', $this->formatTemplate($report)],
            ['CSS', $this->formatAssets($report, 'css', true)],
            ['JS', $this->formatAssets($report, 'js', true)],
            ['Stimulus controller', $this->formatStimulusController($report, true)],
            ['Routes', [] === $routes ? '-' : implode("\n", $routes)],
        ]);

        $this->renderSummary($io, [$report]);
    }

    /**
     * Where the metadata comes from, then the problems of each component.
     *
     * @param list<ComponentReport> $reports
     */
    private function renderSummary(SymfonyStyle $io, array $reports): void
    {
        $io->comment($this->inspector->usesMetadataCache()
            ? 'Metadata from the build-time cache ("ux_sdc.metadata_cache" on): a file added next to a class or an anonymous component template needs "bin/console cache:clear".'
            : 'Metadata resolved from the files next to each class or anonymous component template ("ux_sdc.metadata_cache" off).');

        $count = 0;
        foreach ($reports as $report) {
            if ([] === $report->problems) {
                continue;
            }

            $io->section(\sprintf('%s (%s)', OutputFormatter::escape($report->name), OutputFormatter::escape($report->class ?? $report->template)));
            $io->listing(array_map(OutputFormatter::escape(...), $report->problems));
            $count += \count($report->problems);
        }

        if (0 === $count) {
            $io->success('No problems found.');

            return;
        }

        $io->warning(\sprintf('%d problem%s found.', $count, 1 === $count ? '' : 's'));
    }

    /**
     * @param list<ComponentReport> $reports
     */
    private function renderJson(OutputInterface $output, array $reports): void
    {
        $output->writeln(json_encode(
            [
                'metadata_cache' => $this->inspector->usesMetadataCache(),
                'components' => array_map(static fn (ComponentReport $report): array => $report->toArray(), $reports),
            ],
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        ), OutputInterface::OUTPUT_RAW);
    }

    private function formatTemplate(ComponentReport $report): string
    {
        return (false === $report->templateFound ? self::PROBLEM : '')
            . OutputFormatter::escape($report->template)
            . \sprintf(' <comment>(%s)</comment>', $report->templateOrigin);
    }

    /**
     * @param 'css'|'js' $type
     */
    private function formatAssets(ComponentReport $report, string $type, bool $detailed): string
    {
        $lines = [];
        foreach ($report->assets as $asset) {
            // An asset of any other type is not loaded: listed with the JS, flagged.
            if ($type !== ('css' === $asset['type'] ? 'css' : 'js')) {
                continue;
            }

            $line = ($this->hasProblem($asset) ? self::PROBLEM : '') . OutputFormatter::escape($asset['path']);

            if ($detailed) {
                if (null !== $asset['public_path'] && $asset['public_path'] !== $asset['path']) {
                    $line .= ' → ' . OutputFormatter::escape($asset['public_path']);
                }
                $line .= \sprintf(' <comment>(%s)</comment>', $asset['source']);
            } elseif (ComponentMetadata::SOURCE_DISCOVERED !== $asset['source']) {
                $line .= \sprintf(' <comment>(%s)</comment>', $asset['source']);
            }

            $lines[] = $line;
        }

        return [] === $lines ? '-' : implode("\n", $lines);
    }

    /**
     * @param ReportedAsset $asset
     */
    private function hasProblem(array $asset): bool
    {
        return false === $asset['mapped'] || ('css' !== $asset['type'] && 'js' !== $asset['type']);
    }

    /**
     * In the list, the name of a controller that is there or that the template
     * uses, flagged when it is not there; in detail, the name whatever its
     * state. Without StimulusBundle whether it is there is not known, and
     * nothing is flagged.
     */
    private function formatStimulusController(ComponentReport $report, bool $detailed): string
    {
        $controller = $report->stimulusController;

        if ($controller['set_by_component']) {
            return '<comment>(set by the component)</comment>';
        }

        if (null === $controller['name']) {
            return '-';
        }

        $name = OutputFormatter::escape($controller['name']);

        if (true === $controller['registered']) {
            return $name;
        }

        if (null === $controller['registered']) {
            if ($detailed) {
                return $name . ' <comment>(not checked without StimulusBundle)</comment>';
            }

            return $controller['used'] ? $name : '-';
        }

        if ($controller['used']) {
            return self::PROBLEM . $name . ($detailed ? ' <comment>(no such controller)</comment>' : '');
        }

        return $detailed ? $name . ' <comment>(no such controller, not used by the template)</comment>' : '-';
    }
}

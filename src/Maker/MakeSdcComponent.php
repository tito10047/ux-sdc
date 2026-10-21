<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Maker;

use Symfony\Bundle\MakerBundle\ConsoleStyle;
use Symfony\Bundle\MakerBundle\DependencyBuilder;
use Symfony\Bundle\MakerBundle\Generator;
use Symfony\Bundle\MakerBundle\InputConfiguration;
use Symfony\Bundle\MakerBundle\Maker\AbstractMaker;
use Symfony\Bundle\MakerBundle\Str;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Tito10047\UX\Sdc\Twig\Stimulus;

final class MakeSdcComponent extends AbstractMaker
{
    public function __construct(
        private string $uxComponentsDir,
        private ?string $componentNamespace,
    ) {
    }

    public static function getCommandName(): string
    {
        return 'make:sdc-component';
    }

    public static function getCommandDescription(): string
    {
        return 'Creates a new SDC component';
    }

    public function configureCommand(Command $command, InputConfiguration $inputConfig): void
    {
        $command
            ->addArgument('name', InputArgument::OPTIONAL, 'The name of the component (e.g. <alternate>Alert</alternate>)')
            ->addOption('stimulus', null, InputOption::VALUE_NONE, 'Whether to generate a Stimulus controller')
            ->addOption('action', null, InputOption::VALUE_NONE, 'Whether to generate an action class and template')
            ->setHelp(
                <<<EOF
The <info>make:sdc-component</info> command generates a new Single Directory Component (SDC).

It creates:
- A PHP class in the component directory
- A Twig template
- A CSS file
- (Optional) A Stimulus controller
- (Optional) An Action class and template

Example:
<info>php bin/console make:sdc-component UI:Alert</info>
EOF
            )
        ;
    }

    public function generate(InputInterface $input, ConsoleStyle $io, Generator $generator): void
    {
        $name = $input->getArgument('name');
        if (!$name) {
            $name = $io->ask('The name of the component (e.g. Alert or UI:Alert)', null);
        }

        if ($input->getOption('stimulus')) {
            $withStimulus = true;
        } elseif (!$input->isInteractive()) {
            // In non-interactive mode, do NOT generate Stimulus unless explicitly requested
            $withStimulus = false;
        } else {
            $withStimulus = $io->confirm('Do you want to generate a Stimulus controller?', true);
        }

        if ($input->getOption('action')) {
            $withAction = true;
        } elseif (!$input->isInteractive()) {
            // In non-interactive mode, do NOT generate Action unless explicitly requested
            $withAction = false;
        } else {
            $withAction = $io->confirm('Do you want to generate an Action class and template?', false);
        }

        $name = str_replace(['/', ':'], '\\', $name);
        $parts = explode('\\', $name);
        $componentName = Str::asClassName(array_pop($parts));
        $subNamespace = implode('\\', array_map([Str::class, 'asClassName'], $parts));
        $subPath = implode('/', array_map([Str::class, 'asClassName'], $parts));

        $fullNamespace = $this->componentNamespace ? rtrim($this->componentNamespace, '\\') : 'App\\Component';
        if ($subNamespace) {
            $fullNamespace .= '\\' . $subNamespace;
        }

        $directory = $this->uxComponentsDir;
        if ($subPath) {
            $directory .= '/' . $subPath;
        }

        $generator->generateClass(
            $fullNamespace . '\\' . $componentName . '\\' . $componentName,
            __DIR__.'/../../templates/sdc/Component.tpl.php',
            [
                'with_stimulus' => $withStimulus,
            ]
        );

        $generator->generateFile(
            $directory . '/' . $componentName . '/' . $componentName . '.html.twig',
            __DIR__.'/../../templates/sdc/template.tpl.php',
            [
                'component_name' => $componentName,
                'with_stimulus' => $withStimulus,
            ]
        );

        $generator->generateFile(
            $directory . '/' . $componentName . '/' . $componentName . '.css',
            __DIR__.'/../../templates/sdc/style.tpl.php',
            [
                'component_name' => $componentName,
            ]
        );

        if ($withStimulus) {
            $generator->generateFile(
                $directory . '/' . $componentName . '/' . $componentName . '_controller.js',
                __DIR__.'/../../templates/sdc/controller.tpl.php',
                [
                    'component_name' => $componentName,
                ]
            );
        }

        if ($withAction) {
            $fullTwigComponentName = ($subPath ? str_replace('/', ':', $subPath) . ':' : '') . $componentName . ':' . $componentName;

            $generator->generateClass(
                $fullNamespace . '\\' . $componentName . '\\' . $componentName . 'Action',
                __DIR__.'/../../templates/sdc/Action.tpl.php',
                [
                    'component_name' => $componentName,
                    'sub_path' => $subPath,
                ]
            );

            $generator->generateFile(
                $directory . '/' . $componentName . '/' . $componentName . 'Action.html.twig',
                __DIR__.'/../../templates/sdc/ActionTemplate.tpl.php',
                [
                    'full_twig_component_name' => $fullTwigComponentName,
                ]
            );
        }

        $generator->writeChanges();

        $this->writeSuccessMessage($io);

        $io->text([
            'Next: Open your new component class and start customizing it.',
            'Find the documentation at <fg=yellow>https://github.com/tito10047/ux-sdc</>',
        ]);
    }

    public function configureDependencies(DependencyBuilder $dependencies): void
    {
    }
}

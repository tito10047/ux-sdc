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
use Symfony\Bundle\MakerBundle\Exception\RuntimeCommandException;
use Symfony\Bundle\MakerBundle\Generator;
use Symfony\Bundle\MakerBundle\InputConfiguration;
use Symfony\Bundle\MakerBundle\Maker\AbstractMaker;
use Symfony\Bundle\MakerBundle\Str;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpKernel\Kernel;
use Twig\Environment;
use Twig\Error\Error as TwigError;

final class MakeSdcComponent extends AbstractMaker
{
    /**
     * Base templates a generated page extends when --layout is not given, the
     * first one Twig finds: a layout of the application's own, then the one
     * every Flex application has.
     */
    private const LAYOUTS = ['layout.html.twig', 'base.html.twig'];

    /**
     * Blocks of the base template a generated page fills, the first one it has:
     * the usual name of the content area of a layout, then the block Flex's
     * base.html.twig has for the whole body.
     */
    private const CONTENT_BLOCKS = ['content', 'body'];

    /**
     * The conventional namespace of page components. It groups them, and is not
     * part of their URL: Page:Blog:Post is served at /blog/post.
     */
    private const PAGE_NAMESPACE = 'Page';

    /**
     * @param \Closure(): Environment $twig
     */
    public function __construct(
        private string $uxComponentsDir,
        private ?string $componentNamespace,
        private \Closure $twig,
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
            ->addOption('route-path', null, InputOption::VALUE_REQUIRED, 'Path of the action route (implies --action; default: derived from the name)')
            ->addOption('layout', null, InputOption::VALUE_REQUIRED, 'Template the action template extends (implies --action; default: layout.html.twig, else base.html.twig)')
            ->setHelp(
                <<<EOF
The <info>make:sdc-component</info> command generates a new Single Directory Component (SDC).

It creates, in the component directory:
- A PHP class
- A Twig template
- A CSS file
- (Optional) A Stimulus controller
- (Optional) An action: a controller with a route, and a page template rendering the component

Example:
<info>php bin/console make:sdc-component UI:Alert</info>

An action is served at the path of the component name, kebab-cased and
without a leading <comment>Page</comment> namespace, under the route name
MakerBundle's make:controller would give it:
<info>php bin/console make:sdc-component Page:Blog:BlogPost --action</info>
  /blog/blog-post, route app_blog_blog_post

Its template extends <comment>layout.html.twig</comment> if Twig finds one, else
<comment>base.html.twig</comment>, and fills the <comment>content</comment> block if that template has one, else <comment>body</comment>.
Choose another with <info>--layout</info>, and another path with <info>--route-path</info>:
<info>php bin/console make:sdc-component Page:Homepage --route-path=/ --layout=admin.html.twig</info>
EOF
            )
        ;
    }

    public function generate(InputInterface $input, ConsoleStyle $io, Generator $generator): void
    {
        if (null === $this->componentNamespace) {
            throw new RuntimeCommandException(\sprintf('make:sdc-component needs "ux_sdc.component_namespace": the classes it generates are only registered, and named after their directory, inside that namespace. Set it to the namespace Composer\'s PSR-4 autoloading maps to "%s" (e.g. App\Component for src_component/).', $this->uxComponentsDir));
        }

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

        $routePath = $input->getOption('route-path');
        $layout = $input->getOption('layout');

        if ($input->getOption('action') || null !== $routePath || null !== $layout) {
            $withAction = true;
        } elseif (!$input->isInteractive()) {
            // In non-interactive mode, do NOT generate Action unless explicitly requested
            $withAction = false;
        } else {
            $withAction = $io->confirm('Do you want to generate an Action class and template?', false);
        }

        $name = str_replace(['/', ':'], '\\', $name);
        $parts = array_map([Str::class, 'asClassName'], explode('\\', $name));
        $componentName = array_pop($parts);
        $subNamespace = implode('\\', $parts);
        $subPath = implode('/', $parts);

        $fullNamespace = rtrim($this->componentNamespace, '\\');
        if ($subNamespace) {
            $fullNamespace .= '\\' . $subNamespace;
        }
        $fullNamespace .= '\\' . $componentName;

        $directory = $this->uxComponentsDir;
        if ($subPath) {
            $directory .= '/' . $subPath;
        }
        $directory .= '/' . $componentName;

        $action = null;
        if ($withAction) {
            // Resolved before anything is generated, so that nothing is when one fails.
            $layout = $this->resolveLayout($layout);
            $action = [
                'route_path' => $this->resolveRoutePath($routePath, $parts, $componentName),
                'route_name' => Str::asRouteName(implode('\\', $this->routeSegments($parts, $componentName))),
                'layout' => $layout,
                'block' => $this->resolveContentBlock($layout),
            ];
        }

        $this->generateComponentClass($generator, $fullNamespace . '\\' . $componentName, $directory);

        $generator->generateFile(
            $directory . '/' . $componentName . '.html.twig',
            __DIR__.'/../../templates/sdc/template.tpl.php',
            [
                'component_name' => $componentName,
                'with_stimulus' => $withStimulus,
            ]
        );

        $generator->generateFile(
            $directory . '/' . $componentName . '.css',
            __DIR__.'/../../templates/sdc/style.tpl.php',
            [
                'component_name' => $componentName,
            ]
        );

        if ($withStimulus) {
            $generator->generateFile(
                $directory . '/' . $componentName . '_controller.js',
                __DIR__.'/../../templates/sdc/controller.tpl.php',
                [
                    'component_name' => $componentName,
                ]
            );
        }

        if (null !== $action) {
            $generator->generateClass(
                $fullNamespace . '\\' . $componentName . 'Action',
                __DIR__.'/../../templates/sdc/Action.tpl.php',
                [
                    'route_path' => $action['route_path'],
                    'route_name' => $action['route_name'],
                    'template_name' => ($subPath ? $subPath . '/' : '') . $componentName . '/' . $componentName . 'Action.html.twig',
                ]
            );

            $generator->generateFile(
                $directory . '/' . $componentName . 'Action.html.twig',
                __DIR__.'/../../templates/sdc/ActionTemplate.tpl.php',
                [
                    'layout' => $action['layout'],
                    'block' => $action['block'],
                    'full_twig_component_name' => implode(':', [...$parts, $componentName, $componentName]),
                ]
            );
        }

        $generator->writeChanges();

        $this->writeSuccessMessage($io);

        $nextSteps = ['Next: Open your new component class and start customizing it.'];
        if (null !== $action) {
            $nextSteps = [...$nextSteps, '', ...$this->describeRouting($action['route_path'], $action['route_name'], $generator->getRootDirectory())];
        }
        $nextSteps[] = '';
        $nextSteps[] = 'Find the documentation at <fg=yellow>https://github.com/tito10047/ux-sdc</>';

        $io->text($nextSteps);
    }

    public function configureDependencies(DependencyBuilder $dependencies): void
    {
    }

    /**
     * Adds the component class, where Composer's PSR-4 autoloading puts it, and
     * makes sure that is the directory the other files of the component go to.
     * Otherwise the class would end up apart from its template and assets, where
     * nothing finds them.
     */
    private function generateComponentClass(Generator $generator, string $className, string $directory): void
    {
        $settingsHint = \sprintf('"ux_sdc.component_namespace" (%s) has to be the namespace Composer\'s PSR-4 autoloading maps to "ux_sdc.ux_components_dir" (%s): fix whichever of the two is wrong, or the "autoload" section of composer.json, and run "composer dump-autoload".', $this->componentNamespace, $this->uxComponentsDir);

        try {
            $classPath = $generator->generateClass($className, __DIR__.'/../../templates/sdc/Component.tpl.php');
        } catch (\LogicException $e) {
            // MakerBundle's way of saying that no PSR-4 prefix covers the class.
            throw new RuntimeCommandException(\sprintf('Composer\'s autoloading does not know where to put the class "%s". %s', $className, $settingsHint), 0, $e);
        }

        $classDirectory = \dirname(Path::makeAbsolute($classPath, $generator->getRootDirectory()));
        if ($classDirectory !== Path::canonicalize($directory)) {
            throw new RuntimeCommandException(\sprintf('The class "%s" would be written to "%s", but its template and assets to "%s". %s', $className, $classDirectory, Path::canonicalize($directory), $settingsHint));
        }
    }

    /**
     * @param list<string> $namespaces
     */
    private function resolveRoutePath(?string $routePath, array $namespaces, string $componentName): string
    {
        if (null === $routePath) {
            return '/' . implode('/', array_map(
                static fn (string $segment): string => str_replace('_', '-', Str::asSnakeCase($segment)),
                $this->routeSegments($namespaces, $componentName),
            ));
        }

        if (!str_starts_with($routePath, '/')) {
            throw new RuntimeCommandException(\sprintf('The route path "%s" has to start with "/".', $routePath));
        }

        return $routePath;
    }

    /**
     * The name of the component, without the namespace of page components.
     *
     * @param list<string> $namespaces
     *
     * @return list<string>
     */
    private function routeSegments(array $namespaces, string $componentName): array
    {
        if (self::PAGE_NAMESPACE === ($namespaces[0] ?? null)) {
            array_shift($namespaces);
        }

        return [...$namespaces, $componentName];
    }

    private function resolveLayout(?string $layout): string
    {
        $loader = $this->twig()->getLoader();

        if (null !== $layout) {
            if (!$loader->exists($layout)) {
                throw new RuntimeCommandException(\sprintf('The layout "%s" given with --layout is not a template Twig can find.', $layout));
            }

            return $layout;
        }

        foreach (self::LAYOUTS as $candidate) {
            if ($loader->exists($candidate)) {
                return $candidate;
            }
        }

        // A page of its own, with its own <head>, would not have the application's
        // stylesheets, importmap or render_component_assets(), and would look
        // fine until somebody notices that nothing on it is styled.
        throw new RuntimeCommandException(\sprintf('The action template has no layout to extend: Twig finds none of "%s". Pass the template your pages extend with --layout.', implode('", "', self::LAYOUTS)));
    }

    /**
     * Asks Twig, so a block is found however it is written, and also when the
     * layout inherits it from a template it extends.
     */
    private function resolveContentBlock(string $layout): string
    {
        try {
            $template = $this->twig()->load($layout);

            foreach (self::CONTENT_BLOCKS as $block) {
                if ($template->hasBlock($block)) {
                    return $block;
                }
            }
        } catch (TwigError $e) {
            throw new RuntimeCommandException(\sprintf('The layout "%s" cannot be loaded to find the block the action template fills: %s', $layout, $e->getMessage()), 0, $e);
        }

        throw new RuntimeCommandException(\sprintf('The layout "%s" has none of the blocks "%s" the action template could fill. Add one, or pass another layout with --layout.', $layout, implode('", "', self::CONTENT_BLOCKS)));
    }

    private function twig(): Environment
    {
        try {
            return ($this->twig)();
        } catch (TwigError $e) {
            // The Twig paths of the application, ux_components_dir among them, have to exist.
            throw new RuntimeCommandException(\sprintf('Twig is needed to find the layout of the action template, and cannot be set up: %s', $e->getMessage()), 0, $e);
        }
    }

    /**
     * What it takes for the route of the action to be loaded. The bundle loads
     * no routes itself, so this depends on the application's config/routes.yaml.
     *
     * @return list<string>
     */
    private function describeRouting(string $routePath, string $routeName, string $projectDir): array
    {
        $import = [
            '',
            '    <comment># config/routes.yaml</comment>',
            '    components:',
            '        resource:',
            \sprintf('            path: %s/', Path::makeRelative($this->uxComponentsDir, $projectDir . '/config')),
            \sprintf('            namespace: %s', rtrim((string) $this->componentNamespace, '\\')),
            '        type: attribute',
            '',
        ];

        $lines = [\sprintf('The page is served at <info>%s</info> (route <info>%s</info>) once its route is loaded.', $routePath, $routeName)];

        if (Kernel::VERSION_ID >= 70400) {
            $lines[] = 'A config/routes.yaml with "resource: routing.controllers", the default since Symfony 7.4, loads it.';
            $lines[] = 'One that imports ../src/Controller/ by path does not; import the component directory as well:';
        } else {
            $lines[] = 'Import the component directory in config/routes.yaml, or the page returns 404:';
        }

        return [
            ...$lines,
            ...$import,
            \sprintf('Check with <info>php bin/console debug:router %s</info>', $routeName),
        ];
    }
}

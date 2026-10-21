<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Integration\Maker;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\MakerBundle\Exception\RuntimeCommandException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\AttributeServicesLoader;
use Tito10047\UX\Sdc\Tests\Integration\FunctionalTestCase;

/**
 * make:sdc-component --action, on applications laid out the way Flex leaves
 * them: the generated page has to open without being edited.
 */
final class MakeSdcComponentActionTest extends FunctionalTestCase
{
    private const COMPONENT_DIR = __DIR__.'/../Fixtures/Component';
    private const NAMESPACE = 'Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component';

    /** A Flex skeleton: base.html.twig with a body block. */
    private const FLEX = __DIR__.'/../Fixtures/maker/flex';

    /** An application with a layout.html.twig of its own, with a content block. */
    private const LAYOUT = __DIR__.'/../Fixtures/maker/layout';

    /** Neither base.html.twig nor layout.html.twig. */
    private const NO_LAYOUT = __DIR__.'/../Fixtures/maker';

    protected function setUp(): void
    {
        if (!class_exists(\Symfony\Bundle\MakerBundle\MakerBundle::class)) {
            self::markTestSkipped('MakerBundle is not installed.');
        }

        $this->removeGeneratedComponents();
    }

    protected function tearDown(): void
    {
        $this->removeGeneratedComponents();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideApplicationLayouts(): iterable
    {
        yield 'Flex skeleton' => [self::FLEX, 'base.html.twig', 'body'];
        yield 'application layout' => [self::LAYOUT, 'layout.html.twig', 'content'];
    }

    #[DataProvider('provideApplicationLayouts')]
    public function testGeneratedPageExtendsTheLayoutOfTheApplication(string $templates, string $layout, string $block): void
    {
        $tester = $this->runMaker($templates, ['name' => 'Page:Homepage', '--action' => true]);

        $tester->assertCommandIsSuccessful();
        $this->assertSame(<<<TWIG
            {% extends '$layout' %}

            {% block $block %}
                <twig:Page:Homepage:Homepage />
            {% endblock %}

            TWIG, file_get_contents(self::COMPONENT_DIR.'/Page/Homepage/HomepageAction.html.twig'));
    }

    /**
     * @return iterable<string, array{string, array{0: string, 1: string|null}}>
     */
    public static function provideApplicationsAndRouteImports(): iterable
    {
        foreach (self::provideApplicationLayouts() as $name => [$templates]) {
            // config/routes/ux_sdc.yaml, the import the maker prints.
            yield $name.', bundle route import' => [$templates, ['.', 'ux_sdc']];
        }

        // The config/routes.yaml of a Flex application created with Symfony 7.4+.
        yield 'Flex skeleton, routing.controllers' => [self::FLEX, ['routing.controllers', null]];
    }

    /**
     * The whole point: generated, then requested, with nothing edited in between.
     *
     * @param array{0: string, 1: string|null} $routeImport
     */
    #[DataProvider('provideApplicationsAndRouteImports')]
    public function testGeneratedPageRendersThroughTheKernel(string $templates, array $routeImport): void
    {
        if ('routing.controllers' === $routeImport[0] && !class_exists(AttributeServicesLoader::class)) {
            self::markTestSkipped('"resource: routing.controllers" exists from Symfony 7.4 on.');
        }

        $this->runMaker($templates, ['name' => 'Page:Homepage', '--action' => true])->assertCommandIsSuccessful();
        self::ensureKernelShutdown();

        $client = self::createClient([
            'twig' => ['default_path' => $templates],
            'route_imports' => [$routeImport],
        ]);
        $client->request('GET', '/homepage');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('<!-- Component: Homepage -->', $html);
        $this->assertMatchesRegularExpression('#<link rel="stylesheet" href="[^"]*/Page/Homepage/Homepage-[^"]*\.css"#', $html);
    }

    public function testGeneratedActionIsAnInvokableController(): void
    {
        $this->runMaker(self::FLEX, ['name' => 'Page:Homepage', '--action' => true])->assertCommandIsSuccessful();

        $this->assertSame(<<<'PHP'
            <?php

            namespace Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component\Page\Homepage;

            use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
            use Symfony\Component\HttpFoundation\Response;
            use Symfony\Component\Routing\Attribute\Route;

            final class HomepageAction extends AbstractController
            {
                #[Route('/homepage', name: 'app_homepage')]
                public function __invoke(): Response
                {
                    return $this->render('Page/Homepage/HomepageAction.html.twig');
                }
            }

            PHP, file_get_contents(self::COMPONENT_DIR.'/Page/Homepage/HomepageAction.php'));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideRoutes(): iterable
    {
        yield 'page' => ['Page:Homepage', '/homepage', 'app_homepage'];
        yield 'nested page' => ['Page:Blog:BlogPost', '/blog/blog-post', 'app_blog_blog_post'];
        yield 'outside Page' => ['Admin:Dashboard', '/admin/dashboard', 'app_admin_dashboard'];
        yield 'component named Page' => ['Page', '/page', 'app_page'];
    }

    #[DataProvider('provideRoutes')]
    public function testRouteIsDerivedFromTheComponentName(string $name, string $path, string $routeName): void
    {
        $tester = $this->runMaker(self::FLEX, ['name' => $name, '--action' => true]);

        $tester->assertCommandIsSuccessful();
        $action = (string) glob(self::COMPONENT_DIR.'/'.str_replace(':', '/', $name).'/*Action.php')[0];
        $this->assertStringContainsString("#[Route('$path', name: '$routeName')]", (string) file_get_contents($action));
        $this->assertStringContainsString("debug:router $routeName", $tester->getDisplay());
    }

    public function testOptionsOverrideRoutePathAndLayout(): void
    {
        // Either option asks for an action by itself.
        $tester = $this->runMaker(self::LAYOUT, ['name' => 'Page:Homepage', '--route-path' => '/', '--layout' => 'base.html.twig']);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString("#[Route('/', name: 'app_homepage')]", (string) file_get_contents(self::COMPONENT_DIR.'/Page/Homepage/HomepageAction.php'));
        $twig = (string) file_get_contents(self::COMPONENT_DIR.'/Page/Homepage/HomepageAction.html.twig');
        $this->assertStringContainsString("{% extends 'base.html.twig' %}", $twig);
        $this->assertStringContainsString('{% block body %}', $twig);
    }

    public function testInteractiveAction(): void
    {
        self::bootKernel(['twig' => ['default_path' => self::FLEX]]);
        $tester = new CommandTester((new Application(self::$kernel))->find('make:sdc-component'));
        $tester->setInputs([
            'Page:Homepage', // Component name
            'n',             // Stimulus?
            'y',             // Action?
        ]);

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertFileExists(self::COMPONENT_DIR.'/Page/Homepage/HomepageAction.php');
        $this->assertFileExists(self::COMPONENT_DIR.'/Page/Homepage/HomepageAction.html.twig');
    }

    public function testSuccessMessageTellsHowTheRouteIsLoaded(): void
    {
        $display = $this->runMaker(self::FLEX, ['name' => 'Page:Homepage', '--action' => true])->getDisplay();

        $this->assertStringContainsString('The page is served at /homepage (route app_homepage) once its route is loaded.', $display);
        $this->assertStringContainsString('# config/routes/ux_sdc.yaml', $display);
        $this->assertStringContainsString('type: ux_sdc', $display);
        $this->assertSame(Kernel::VERSION_ID >= 70400, str_contains($display, 'resource: routing.controllers'));
    }

    public function testSuccessMessageWithRoutingDisabledTellsToImportTheDirectory(): void
    {
        self::bootKernel(['twig' => ['default_path' => self::FLEX], 'configs' => ['routing' => false]]);
        $tester = new CommandTester((new Application(self::$kernel))->find('make:sdc-component'));
        $tester->execute(['name' => 'Page:Homepage', '--action' => true], ['interactive' => false]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('"ux_sdc.routing.enabled" is off', $display);
        // Relative to config/, where routes.yaml is.
        $this->assertStringContainsString('path: ../tests/Integration/Fixtures/Component/', $display);
        $this->assertStringContainsString('namespace: '.self::NAMESPACE, $display);
        $this->assertStringContainsString('type: attribute', $display);
        $this->assertStringNotContainsString('type: ux_sdc', $display);
    }

    /**
     * @return iterable<string, array{string, array<string, string|bool>, string}>
     */
    public static function provideUnusableInput(): iterable
    {
        yield 'no layout' => [self::NO_LAYOUT, ['--action' => true], 'Twig finds none of "layout.html.twig", "base.html.twig". Pass the template your pages extend with --layout.'];
        yield 'unknown --layout' => [self::FLEX, ['--layout' => 'missing.html.twig'], 'The layout "missing.html.twig" given with --layout is not a template Twig can find.'];
        yield 'layout without a block to fill' => [self::FLEX, ['--layout' => 'plain.html.twig'], 'The layout "plain.html.twig" has none of the blocks "content", "body" the action template could fill.'];
        yield 'relative route path' => [self::FLEX, ['--route-path' => 'homepage'], 'The route path "homepage" has to start with "/".'];
    }

    /**
     * @param array<string, string|bool> $options
     */
    #[DataProvider('provideUnusableInput')]
    public function testUnusableInputFailsBeforeAnythingIsWritten(string $templates, array $options, string $message): void
    {
        try {
            $this->runMaker($templates, ['name' => 'Page:Homepage', ...$options]);
            $this->fail('The maker generated a page it could not complete.');
        } catch (RuntimeCommandException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }

        $this->assertDirectoryDoesNotExist(self::COMPONENT_DIR.'/Page');
    }

    /**
     * @param array<string, string|bool> $input
     */
    private function runMaker(string $templates, array $input): CommandTester
    {
        self::bootKernel(['twig' => ['default_path' => $templates]]);
        $tester = new CommandTester((new Application(self::$kernel))->find('make:sdc-component'));
        $tester->execute($input, ['interactive' => false]);

        return $tester;
    }

    private function removeGeneratedComponents(): void
    {
        (new Filesystem())->remove([
            self::COMPONENT_DIR.'/Page',
            self::COMPONENT_DIR.'/Admin',
        ]);
    }
}

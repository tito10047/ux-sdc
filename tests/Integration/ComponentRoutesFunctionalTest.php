<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\Loader\AttributeServicesLoader;
use Symfony\Component\Routing\RouterInterface;

/**
 * The routes of #[Route] controllers in ux_components_dir, through the imports
 * an application can have: the bundle's own (`type: ux_sdc`, what the Flex
 * recipe adds), Symfony 7.4's `routing.controllers`, and the directory imported
 * by path — alone or together, never twice.
 */
final class ComponentRoutesFunctionalTest extends FunctionalTestCase
{
    private const NAMESPACE = 'Tito10047\UX\Sdc\Tests\Integration\Fixtures\RoutedComponent';
    private const COMPONENT_DIR = __DIR__.'/Fixtures/RoutedComponent';

    private const SDC_IMPORT = ['.', 'ux_sdc'];
    private const ROUTING_CONTROLLERS_IMPORT = ['routing.controllers', null];

    public function testBundleImportLoadsTheRoutesOfTheComponentDirectory(): void
    {
        $client = self::createClient($this->options([self::SDC_IMPORT]));

        $client->request('GET', '/homepage');
        self::assertResponseIsSuccessful();
        $this->assertSame('Homepage', $client->getResponse()->getContent());

        $client->request('GET', '/about');
        self::assertResponseIsSuccessful();
        $this->assertSame('About', $client->getResponse()->getContent());
    }

    /**
     * The config/routes.yaml of a Flex application created with Symfony 7.4 or
     * later: nothing about the component directory in it.
     */
    public function testRoutingControllersImportLoadsTheRoutesOfTheComponentDirectory(): void
    {
        self::requireRoutingControllers();

        $client = self::createClient($this->options([self::ROUTING_CONTROLLERS_IMPORT]));

        $client->request('GET', '/homepage');
        self::assertResponseIsSuccessful();
        $this->assertSame('Homepage', $client->getResponse()->getContent());
    }

    /**
     * @return iterable<string, array{list<array{0: string, 1: string|null}>, bool, bool}>
     */
    public static function provideImportCombinations(): iterable
    {
        yield 'bundle import and directory import' => [[self::SDC_IMPORT], true, false];
        yield 'bundle import and routing.controllers' => [[self::SDC_IMPORT, self::ROUTING_CONTROLLERS_IMPORT], false, true];
        yield 'all three' => [[self::SDC_IMPORT, self::ROUTING_CONTROLLERS_IMPORT], true, true];
        yield 'bundle import twice' => [[self::SDC_IMPORT, self::SDC_IMPORT], false, false];
    }

    /**
     * @param list<array{0: string, 1: string|null}> $imports
     */
    #[DataProvider('provideImportCombinations')]
    public function testRoutesImportedMoreThanOnceAreRegisteredOnce(array $imports, bool $directoryImport, bool $needsRoutingControllers): void
    {
        if ($needsRoutingControllers) {
            self::requireRoutingControllers();
        }

        $client = self::createClient($this->options($imports, $directoryImport));

        $this->assertSame(['/homepage' => 1, '/about' => 1], $this->countRoutesByPath(['/homepage', '/about']));
        $this->assertSame('/homepage', $this->routes()['app_homepage']['path'] ?? null);

        $client->request('GET', '/homepage');
        self::assertResponseIsSuccessful();
    }

    public function testDisabledRoutingLoadsNothingThroughTheBundle(): void
    {
        $imports = [self::SDC_IMPORT];
        if (class_exists(AttributeServicesLoader::class)) {
            $imports[] = self::ROUTING_CONTROLLERS_IMPORT;
        }

        self::bootKernel($this->options($imports, configs: ['routing' => false]));

        $this->assertSame(['/homepage' => 0, '/about' => 0], $this->countRoutesByPath(['/homepage', '/about']));
    }

    /**
     * The option governs what the bundle contributes, not an import the
     * application writes itself.
     */
    public function testDisabledRoutingKeepsAnImportOfTheApplication(): void
    {
        self::bootKernel($this->options([self::SDC_IMPORT], directoryImport: true, configs: ['routing' => ['enabled' => false]]));

        $this->assertSame(['/homepage' => 1, '/about' => 1], $this->countRoutesByPath(['/homepage', '/about']));
    }

    /**
     * The recipe's import must not break an application that has no component
     * namespace (yet).
     */
    public function testBundleImportWithoutComponentNamespaceLoadsNothing(): void
    {
        self::bootKernel([
            ...$this->options([self::SDC_IMPORT], configs: ['component_namespace' => null]),
            // Required by TwigComponent 2.13+, prepended by the bundle only for component_namespace.
            'twig_component' => ['defaults' => ['App\\Twig\\Components\\' => 'components/']],
        ]);

        $this->assertSame(['/homepage' => 0, '/about' => 0], $this->countRoutesByPath(['/homepage', '/about']));
    }

    /**
     * Right after `composer require`: the import is there, the directory is not.
     */
    public function testBundleImportOfADirectoryThatDoesNotExistYet(): void
    {
        $directory = sys_get_temp_dir().'/ux-sdc-missing-'.bin2hex(random_bytes(4));

        self::bootKernel($this->options([self::SDC_IMPORT], configs: ['ux_components_dir' => $directory]));

        $router = self::getContainer()->get('router');
        $this->assertInstanceOf(RouterInterface::class, $router);
        $collection = $router->getRouteCollection();

        $this->assertNull($collection->get('app_homepage'));
        // Tracked, so that the routes are loaded once the directory is created.
        $this->assertContainsEquals(new FileExistenceResource($directory), $collection->getResources());
    }

    /**
     * @param list<array{0: string, 1: string|null}> $imports
     * @param array<string, mixed>                   $configs
     *
     * @return array<string, mixed>
     */
    private function options(array $imports, bool $directoryImport = false, array $configs = []): array
    {
        return [
            'configs' => [
                'component_namespace' => self::NAMESPACE,
                'ux_components_dir' => self::COMPONENT_DIR,
                ...$configs,
            ],
            'route_imports' => $imports,
            'attribute_routes' => $directoryImport ? [self::NAMESPACE => self::COMPONENT_DIR] : [],
        ];
    }

    /**
     * The routes as `debug:router --format=json` lists them, by name.
     *
     * @return array<string, array<string, mixed>>
     */
    private function routes(): array
    {
        self::assertNotNull(self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find('debug:router'));
        $tester->execute(['--format' => 'json']);
        $tester->assertCommandIsSuccessful();

        $routes = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertIsArray($routes);

        return $routes;
    }

    /**
     * @param list<string> $paths
     *
     * @return array<string, int>
     */
    private function countRoutesByPath(array $paths): array
    {
        $count = array_fill_keys($paths, 0);
        foreach ($this->routes() as $route) {
            if (isset($count[$route['path']])) {
                ++$count[$route['path']];
            }
        }

        return $count;
    }

    private static function requireRoutingControllers(): void
    {
        if (!class_exists(AttributeServicesLoader::class)) {
            self::markTestSkipped('"resource: routing.controllers" exists from Symfony 7.4 on.');
        }
    }
}

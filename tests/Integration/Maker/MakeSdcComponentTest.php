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
use Symfony\UX\StimulusBundle\AssetMapper\ControllersMapGenerator;
use Symfony\UX\StimulusBundle\StimulusBundle;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Tito10047\UX\Sdc\Tests\Integration\IntegrationTestCase;

class MakeSdcComponentTest extends IntegrationTestCase
{
    private const NEW_COMPONENTS_DIR = __DIR__.'/../Fixtures/maker/components';

    protected function setUp(): void
    {
        if (!class_exists(\Symfony\Bundle\MakerBundle\MakerBundle::class)) {
            $this->markTestSkipped('MakerBundle is not installed.');
        }

        parent::setUp();
        $this->removeGeneratedComponents();
    }

    protected function tearDown(): void
    {
        $this->removeGeneratedComponents();
        parent::tearDown();
    }

    /**
     * Fixed paths rather than the ux_components_dir of the kernel: some tests
     * boot one with another directory, and a failing one may have written files.
     */
    private function removeGeneratedComponents(): void
    {
        $fixtures = \dirname(__DIR__).'/Fixtures';

        (new Filesystem())->remove([
            $fixtures.'/Component/UI',
            $fixtures.'/Component/Alert',
            $fixtures.'/Component/Page',
            $fixtures.'/Library/UI',
            self::NEW_COMPONENTS_DIR,
        ]);
    }

    public function testMakeSdcComponent(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        $command = $application->find('make:sdc-component');
        $tester = new CommandTester($command);

        $tester->setInputs([
            'UI\Alert', // Component name
            // Generate stimulus controller? Only asked with StimulusBundle.
            ...(self::hasStimulusBundle() ? ['y'] : []),
        ]);

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();

        $baseDir = self::getContainer()->getParameter('ux_sdc.ux_components_dir');
        $this->assertFileExists($baseDir . '/UI/Alert/Alert.php');
        $this->assertFileExists($baseDir . '/UI/Alert/Alert.html.twig');
        $this->assertFileExists($baseDir . '/UI/Alert/Alert.css');
        if (self::hasStimulusBundle()) {
            $this->assertFileExists($baseDir . '/UI/Alert/Alert_controller.js');
        } else {
            $this->assertFileDoesNotExist($baseDir . '/UI/Alert/Alert_controller.js');
            $this->assertStringContainsString('No Stimulus controller is offered', $display);
        }

        $phpContent = file_get_contents($baseDir . '/UI/Alert/Alert.php');
        $this->assertStringContainsString('namespace Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component\UI\Alert;', $phpContent);
        $this->assertStringContainsString('class Alert', $phpContent);
        $this->assertStringContainsString('use Tito10047\UX\Sdc\Attribute\AsSdcComponent;', $phpContent);
        $this->assertStringContainsString('#[AsSdcComponent]', $phpContent);
        // {{ controller }} needs neither: the bundle sets it for every component in the namespace.
        $this->assertStringNotContainsString('ComponentNamespaceInterface', $phpContent);
        $this->assertStringNotContainsString('Stimulus', $phpContent);

        $twigContent = file_get_contents($baseDir . '/UI/Alert/Alert.html.twig');
        $this->assertStringContainsString(self::hasStimulusBundle() ? '<div {{ attributes.defaults({class:"alert"}).defaults(stimulus_controller(controller)) }}>' : '<div {{ attributes.defaults({class:"alert"}) }}>', $twigContent);

        $cssContent = file_get_contents($baseDir . '/UI/Alert/Alert.css');
        $this->assertStringContainsString('@layer components {', $cssContent);
        $this->assertStringContainsString('.alert{', $cssContent);

        $this->assertStringContainsString('tests/Integration/Fixtures/Component/UI/Alert/Alert.php', $display);
    }

    /**
     * The generated template passes {{ controller }} to stimulus_controller();
     * it has to name the generated Alert_controller.js the way StimulusBundle does.
     */
    public function testGeneratedComponentRendersItsStimulusController(): void
    {
        if (!self::hasStimulusBundle()) {
            self::markTestSkipped('symfony/stimulus-bundle is not installed; testStimulusOptionNeedsStimulusBundle covers its absence.');
        }

        self::bootKernel();
        $tester = new CommandTester((new Application(self::$kernel))->find('make:sdc-component'));
        $tester->execute(['name' => 'UI:Alert', '--stimulus' => true], ['interactive' => false]);
        $tester->assertCommandIsSuccessful();

        // A new container, which registers the generated class.
        self::ensureKernelShutdown();
        self::bootKernel();

        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');
        $html = $renderer->createAndRender('UI:Alert:Alert');

        $generator = self::getContainer()->get('stimulus.asset_mapper.controllers_map_generator');
        $this->assertInstanceOf(ControllersMapGenerator::class, $generator);
        $this->assertArrayHasKey('UI--Alert--Alert', $generator->getControllersMap());

        $this->assertStringContainsString('data-controller="UI--Alert--Alert"', $html);
    }

    /**
     * Without StimulusBundle the template of the controller would call a Twig
     * function that does not exist, and the component would not render.
     */
    public function testStimulusOptionNeedsStimulusBundle(): void
    {
        if (self::hasStimulusBundle()) {
            self::markTestSkipped('Only without symfony/stimulus-bundle installed, as in the "Tests without LiveComponent" CI job.');
        }

        self::bootKernel();
        $tester = new CommandTester((new Application(self::$kernel))->find('make:sdc-component'));

        try {
            $tester->execute(['name' => 'UI:Alert', '--stimulus' => true], ['interactive' => false]);
            $this->fail('The maker generated a Stimulus controller without StimulusBundle.');
        } catch (RuntimeCommandException $e) {
            $this->assertStringContainsString('Run "composer require symfony/stimulus-bundle" first, or leave --stimulus out.', $e->getMessage());
        }

        $this->assertDirectoryDoesNotExist(\dirname(__DIR__).'/Fixtures/Component/UI');
    }

    public function testMakeSdcComponentWithColonSeparator(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        $command = $application->find('make:sdc-component');
        $tester = new CommandTester($command);

        $tester->setInputs([
            'UI:Alert', // Component name with colon
            // Generate stimulus controller? Only asked with StimulusBundle.
            ...(self::hasStimulusBundle() ? ['n'] : []),
        ]);

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();

        $baseDir = self::getContainer()->getParameter('ux_sdc.ux_components_dir');
        $this->assertFileExists($baseDir . '/UI/Alert/Alert.php');
        $this->assertFileExists($baseDir . '/UI/Alert/Alert.html.twig');
        $this->assertFileExists($baseDir . '/UI/Alert/Alert.css');

        $phpContent = file_get_contents($baseDir . '/UI/Alert/Alert.php');
        $this->assertStringContainsString('namespace Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component\UI\Alert;', $phpContent);
    }

    public function testMakeSdcComponentNoInteractionNoStimulus(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        $command = $application->find('make:sdc-component');
        $tester = new CommandTester($command);

        // Name is passed as argument, no stimulus option
        $tester->execute([
            'name' => 'Alert',
        ], [
            'interactive' => false,
        ]);

        $tester->assertCommandIsSuccessful();

        $baseDir = self::getContainer()->getParameter('ux_sdc.ux_components_dir');
        $this->assertFileExists($baseDir . '/Alert/Alert.php');
        $this->assertFileExists($baseDir . '/Alert/Alert.html.twig');
        $this->assertFileDoesNotExist($baseDir . '/Alert/Alert_controller.js');

        $twigContent = file_get_contents($baseDir . '/Alert/Alert.html.twig');
        $this->assertStringNotContainsString('stimulus_controller', $twigContent);
    }

    private static function hasStimulusBundle(): bool
    {
        return class_exists(StimulusBundle::class);
    }

    /**
     * Kernel options, and the message. ux_components_dir does not exist yet, as
     * in an application about to get its first component: once it holds classes,
     * a namespace that does not match makes the container fail to build.
     *
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideMismatchedConfiguration(): iterable
    {
        $fixtures = \dirname(__DIR__).'/Fixtures';

        yield 'namespace of another directory' => [
            ['configs' => ['component_namespace' => 'Tito10047\\UX\\Sdc\\Tests\\Integration\\Fixtures\\Library', 'ux_components_dir' => self::NEW_COMPONENTS_DIR]],
            'would be written to "'.$fixtures.'/Library/UI/Alert", but its template and assets to "'.$fixtures.'/maker/components/UI/Alert". "ux_sdc.component_namespace" (Tito10047\\UX\\Sdc\\Tests\\Integration\\Fixtures\\Library\\) has to be the namespace Composer\'s PSR-4 autoloading maps to "ux_sdc.ux_components_dir"',
        ];
        yield 'namespace Composer does not autoload' => [
            ['configs' => ['component_namespace' => 'Acme\\NotAutoloaded', 'ux_components_dir' => self::NEW_COMPONENTS_DIR]],
            'Composer\'s autoloading does not know where to put the class "Acme\\NotAutoloaded\\UI\\Alert\\Alert".',
        ];
        yield 'no namespace' => [
            // Without component_namespace, TwigComponent 3 wants defaults of the application.
            ['configs' => ['component_namespace' => null], 'twig_component' => ['defaults' => ['App\\Twig\\Components\\' => 'components/']]],
            'make:sdc-component needs "ux_sdc.component_namespace"',
        ];
    }

    /**
     * Instead of a class in one directory and its template and assets in another.
     *
     * @param array<string, mixed> $kernelOptions
     */
    #[DataProvider('provideMismatchedConfiguration')]
    public function testMismatchedConfigurationFailsBeforeAnythingIsWritten(array $kernelOptions, string $message): void
    {
        self::bootKernel($kernelOptions);
        $tester = new CommandTester((new Application(self::$kernel))->find('make:sdc-component'));

        try {
            $tester->execute(['name' => 'UI:Alert'], ['interactive' => false]);
            $this->fail('The maker generated a component with a configuration that scatters it.');
        } catch (RuntimeCommandException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }

        $this->assertDirectoryDoesNotExist(self::NEW_COMPONENTS_DIR);
        $this->assertDirectoryDoesNotExist(\dirname(__DIR__).'/Fixtures/Library/UI');
        $this->assertDirectoryDoesNotExist(\dirname(__DIR__).'/Fixtures/Component/UI');
    }
}

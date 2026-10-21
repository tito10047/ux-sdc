<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Integration\Maker;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tito10047\UX\Sdc\Tests\Integration\IntegrationTestCase;

class MakeSdcComponentTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\Symfony\Bundle\MakerBundle\MakerBundle::class)) {
            $this->markTestSkipped('MakerBundle is not installed.');
        }

        parent::setUp();
        $this->removeDir(self::getContainer()->getParameter('ux_sdc.ux_components_dir') . '/UI');
        $this->removeDir(self::getContainer()->getParameter('ux_sdc.ux_components_dir') . '/Alert');
        $this->removeDir(self::getContainer()->getParameter('ux_sdc.ux_components_dir') . '/Page');
    }

    protected function tearDown(): void
    {
        // Before parent::tearDown(): getContainer() would boot the kernel again
        // after it was shut down, and leave it booted for the next test class.
        $this->removeDir(self::getContainer()->getParameter('ux_sdc.ux_components_dir') . '/UI');
        $this->removeDir(self::getContainer()->getParameter('ux_sdc.ux_components_dir') . '/Alert');
        $this->removeDir(self::getContainer()->getParameter('ux_sdc.ux_components_dir') . '/Page');
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->removeDir("$dir/$file") : unlink("$dir/$file");
        }
        rmdir($dir);
    }

    public function testMakeSdcComponent(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        $command = $application->find('make:sdc-component');
        $tester = new CommandTester($command);

        $tester->setInputs([
            'UI\Alert', // Component name
            'y',     // Generate stimulus controller?
        ]);

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();

        $baseDir = self::getContainer()->getParameter('ux_sdc.ux_components_dir');
        $this->assertFileExists($baseDir . '/UI/Alert/Alert.php');
        $this->assertFileExists($baseDir . '/UI/Alert/Alert.html.twig');
        $this->assertFileExists($baseDir . '/UI/Alert/Alert.css');
        $this->assertFileExists($baseDir . '/UI/Alert/Alert_controller.js');

        $phpContent = file_get_contents($baseDir . '/UI/Alert/Alert.php');
        $this->assertStringContainsString('namespace Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component\UI\Alert;', $phpContent);
        $this->assertStringContainsString('class Alert', $phpContent);
        $this->assertStringContainsString('use Tito10047\UX\Sdc\Attribute\AsSdcComponent;', $phpContent);
        $this->assertStringContainsString('#[AsSdcComponent]', $phpContent);

        $twigContent = file_get_contents($baseDir . '/UI/Alert/Alert.html.twig');
        $this->assertStringContainsString('<div {{ attributes.defaults({class:"alert"}).defaults(stimulus_controller(controller)) }}>', $twigContent);

        $cssContent = file_get_contents($baseDir . '/UI/Alert/Alert.css');
        $this->assertStringContainsString('@layer components {', $cssContent);
        $this->assertStringContainsString('.alert{', $cssContent);

        $this->assertStringContainsString('tests/Integration/Fixtures/Component/UI/Alert/Alert.php', $display);
    }

    public function testMakeSdcComponentWithAction(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        $command = $application->find('make:sdc-component');
        $tester = new CommandTester($command);

        // Page:Homepage with --action
        $tester->execute([
            'name' => 'Page:Homepage',
            '--action' => true,
        ], [
            'interactive' => false,
        ]);

        $tester->assertCommandIsSuccessful();

        $baseDir = self::getContainer()->getParameter('ux_sdc.ux_components_dir');
        $this->assertFileExists($baseDir . '/Page/Homepage/Homepage.php');
        $this->assertFileExists($baseDir . '/Page/Homepage/HomepageAction.php');
        $this->assertFileExists($baseDir . '/Page/Homepage/HomepageAction.html.twig');

        $actionPhpContent = file_get_contents($baseDir . '/Page/Homepage/HomepageAction.php');
        $this->assertStringContainsString('namespace Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component\Page\Homepage;', $actionPhpContent);
        $this->assertStringContainsString('class HomepageAction extends AbstractController', $actionPhpContent);
        $this->assertStringContainsString('#[Route(\'/en\', name: \'app.homepage\')]', $actionPhpContent);
        $this->assertStringContainsString('return $this->render(\'Page/Homepage/HomepageAction.html.twig\');', $actionPhpContent);

        $actionTwigContent = file_get_contents($baseDir . '/Page/Homepage/HomepageAction.html.twig');
        $this->assertStringContainsString('{% extends \'layout.html.twig\' %}', $actionTwigContent);
        $this->assertStringContainsString('<twig:Page:Homepage:Homepage />', $actionTwigContent);
    }

    public function testMakeSdcComponentInteractiveWithAction(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        $command = $application->find('make:sdc-component');
        $tester = new CommandTester($command);

        $tester->setInputs([
            'Page:Homepage', // Component name
            'n',             // Stimulus?
            'y',             // Action?
        ]);

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();

        $baseDir = self::getContainer()->getParameter('ux_sdc.ux_components_dir');
        $this->assertFileExists($baseDir . '/Page/Homepage/HomepageAction.php');
        $this->assertFileExists($baseDir . '/Page/Homepage/HomepageAction.html.twig');
    }

    public function testMakeSdcComponentWithColonSeparator(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        $command = $application->find('make:sdc-component');
        $tester = new CommandTester($command);

        $tester->setInputs([
            'UI:Alert', // Component name with colon
            'n',     // Generate stimulus controller?
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
}

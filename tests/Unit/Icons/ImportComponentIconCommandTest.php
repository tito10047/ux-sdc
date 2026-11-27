<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Unit\Icons;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\UX\Icons\IconFactory;
use Symfony\UX\Icons\Iconify;
use Tito10047\UX\Sdc\Icons\Command\ImportComponentIconCommand;

class ImportComponentIconCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (!class_exists(Iconify::class) || !class_exists(MockHttpClient::class)) {
            $this->markTestSkipped('symfony/ux-icons and symfony/http-client are needed.');
        }

        $this->dir = sys_get_temp_dir() . '/ux-sdc-icons-' . uniqid();
        (new Filesystem())->mkdir($this->dir . '/Alert');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testImportsIntoTheComponentDirectory(): void
    {
        $tester = $this->tester();
        $tester->execute(['component' => 'Alert', 'names' => ['mdi:home', 'mdi:close']]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertFileExists($this->dir . '/Alert/home.svg');
        $this->assertFileExists($this->dir . '/Alert/close.svg');
        $this->assertStringContainsString('<path d="HOME"/>', file_get_contents($this->dir . '/Alert/home.svg'));
        $this->assertStringContainsString('License: Apache 2.0', $tester->getDisplay());
    }

    public function testRenamesWithAsAndUsesTheSubdirectory(): void
    {
        $tester = $this->tester('icons');
        $tester->execute(['component' => 'Alert', 'names' => ['mdi:home'], '--as' => 'house']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertFileExists($this->dir . '/Alert/icons/house.svg');
    }

    public function testKeepsAnExistingIconUnlessForced(): void
    {
        file_put_contents($this->dir . '/Alert/home.svg', 'MINE');

        $this->tester()->execute(['component' => 'Alert', 'names' => ['mdi:home']]);
        $this->assertSame('MINE', file_get_contents($this->dir . '/Alert/home.svg'));

        $this->tester()->execute(['component' => 'Alert', 'names' => ['mdi:home'], '--force' => true]);
        $this->assertNotSame('MINE', file_get_contents($this->dir . '/Alert/home.svg'));
    }

    public function testReportsMissingIconsAndComponents(): void
    {
        $tester = $this->tester();

        $tester->execute(['component' => 'Alert', 'names' => ['mdi:nope']]);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Not Found mdi:nope', $tester->getDisplay());

        $tester->execute(['component' => 'Missing', 'names' => ['mdi:home']]);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());

        $tester->execute(['component' => 'Alert', 'names' => ['mdi:home', 'mdi:close'], '--as' => 'x']);
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
    }

    private function tester(string $subdir = ''): CommandTester
    {
        $client = new MockHttpClient(static function (string $method, string $url): JsonMockResponse {
            if (str_contains($url, '/collections')) {
                return new JsonMockResponse(['mdi' => ['name' => 'Material Design Icons', 'license' => ['title' => 'Apache 2.0']]]);
            }

            return new JsonMockResponse([
                'icons' => [
                    'home' => ['body' => '<path d="HOME"/>'],
                    'close' => ['body' => '<path d="CLOSE"/>'],
                ],
                'width' => 24,
                'height' => 24,
            ]);
        });

        $iconify = new Iconify(new ArrayAdapter(), new IconFactory(), 'https://api.iconify.design', $client);

        return new CommandTester(new ImportComponentIconCommand($iconify, null, $this->dir, $subdir));
    }
}

<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Tito10047\UX\Sdc\DependencyInjection\Configuration;

class ConfigurationTest extends TestCase
{
    public function testDefaultConfig(): void
    {
        $configuration = new Configuration();
        $processor = new Processor();

        $config = $processor->processConfiguration($configuration, []);

        $this->assertEquals('%kernel.project_dir%/src_component', $config['ux_components_dir']);
        $this->assertNull($config['component_namespace']);
        $this->assertTrue($config['stimulus']['enabled']);
        $this->assertTrue($config['auto_discovery']);
        $this->assertEquals('<!-- __UX_TWIG_COMPONENT_ASSETS__ -->', $config['placeholder']);
    }

    public function testCustomConfig(): void
    {
        $configuration = new Configuration();
        $processor = new Processor();

        $config = $processor->processConfiguration($configuration, [[
            'ux_components_dir' => '%kernel.project_dir%/custom_dir',
            'component_namespace' => 'My\\Custom\\Namespace\\',
            'stimulus' => [
                'enabled' => false,
            ],
        ]]);

        $this->assertEquals('%kernel.project_dir%/custom_dir', $config['ux_components_dir']);
        $this->assertEquals('My\\Custom\\Namespace\\', $config['component_namespace']);
        $this->assertFalse($config['stimulus']['enabled']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideEmptyPlaceholders(): iterable
    {
        yield 'empty string' => [''];
        yield 'null' => [null];
    }

    /**
     * An empty placeholder is found in every response, so it cannot mark where the assets go.
     */
    #[DataProvider('provideEmptyPlaceholders')]
    public function testPlaceholderCannotBeEmpty(mixed $placeholder): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [['placeholder' => $placeholder]]);
    }
}

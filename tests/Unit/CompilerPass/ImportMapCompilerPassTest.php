<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Unit\CompilerPass;

use PHPUnit\Framework\TestCase;
use Symfony\Component\AssetMapper\ImportMap\ImportMapConfigReader;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Tito10047\UX\Sdc\CompilerPass\ImportMapCompilerPass;
use Tito10047\UX\Sdc\ImportMap\ComponentImportMapConfigReader;
use Tito10047\UX\Sdc\ImportMap\ComponentImportMapEntries;

final class ImportMapCompilerPassTest extends TestCase
{
    public function testBuildsTheReaderFromTheSubclassWithItsOwnArguments(): void
    {
        $container = new ContainerBuilder();
        $container->register(ComponentImportMapEntries::class, ComponentImportMapEntries::class);
        $container->setParameter('importmap_class', ImportMapConfigReader::class);
        $container->register('asset_mapper.importmap.config_reader', '%importmap_class%')
            ->setArguments(['%kernel.project_dir%/importmap.php', new Reference('asset_mapper.importmap.remote_package_storage')]);

        (new ImportMapCompilerPass())->process($container);

        $definition = $container->getDefinition('asset_mapper.importmap.config_reader');
        $this->assertSame(ComponentImportMapConfigReader::class, $definition->getClass());
        $this->assertEquals(['%kernel.project_dir%/importmap.php', new Reference('asset_mapper.importmap.remote_package_storage')], $definition->getArguments());
        $this->assertEquals([['setComponentEntries', [new Reference(ComponentImportMapEntries::class)]]], $definition->getMethodCalls());
    }

    public function testLeavesAReaderOfAnotherClassAlone(): void
    {
        $container = new ContainerBuilder();
        $container->register(ComponentImportMapEntries::class, ComponentImportMapEntries::class);
        $container->register('asset_mapper.importmap.config_reader', 'App\ImportMap\CustomReader');

        (new ImportMapCompilerPass())->process($container);

        $this->assertSame('App\ImportMap\CustomReader', $container->getDefinition('asset_mapper.importmap.config_reader')->getClass());
        $this->assertSame([], $container->getDefinition('asset_mapper.importmap.config_reader')->getMethodCalls());
        $this->assertFalse($container->hasDefinition(ComponentImportMapEntries::class));
        $this->assertStringContainsString('a relative import in one of them does not resolve', implode("\n", $container->getCompiler()->getLog()));
    }

    public function testRemovesTheEntriesWithoutAssetMapper(): void
    {
        $container = new ContainerBuilder();
        $container->register(ComponentImportMapEntries::class, ComponentImportMapEntries::class);

        (new ImportMapCompilerPass())->process($container);

        $this->assertFalse($container->hasDefinition(ComponentImportMapEntries::class));
    }
}

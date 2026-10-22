<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\CompilerPass;

use Symfony\Component\AssetMapper\ImportMap\ImportMapConfigReader;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Tito10047\UX\Sdc\ImportMap\ComponentImportMapConfigReader;
use Tito10047\UX\Sdc\ImportMap\ComponentImportMapEntries;

/**
 * Builds AssetMapper's import map configuration reader from
 * ComponentImportMapConfigReader, so that the module scripts of the components
 * are entries of the import map and the files they import relatively resolve.
 *
 * The class of the service is replaced and its arguments are kept: the
 * subclass only adds the component entries, through a setter, and everything
 * else stays AssetMapper's own, whatever its constructor takes. A reader the
 * application or another bundle has replaced is left alone, with a note in
 * the container compiler log.
 *
 * @internal
 */
final class ImportMapCompilerPass implements CompilerPassInterface
{
    private const CONFIG_READER = 'asset_mapper.importmap.config_reader';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ComponentImportMapEntries::class)) {
            return;
        }

        // Absent when AssetMapper is disabled.
        if (!$container->hasDefinition(self::CONFIG_READER)) {
            $container->removeDefinition(ComponentImportMapEntries::class);

            return;
        }

        $definition = $container->getDefinition(self::CONFIG_READER);
        $class = $container->getParameterBag()->resolveValue($definition->getClass());

        if (ImportMapConfigReader::class !== $class) {
            $container->log($this, \sprintf('The "%s" service is a "%s", not AssetMapper\'s "%s": the module scripts of the components are not added to the import map, so a relative import in one of them does not resolve.', self::CONFIG_READER, get_debug_type($class), ImportMapConfigReader::class));
            $container->removeDefinition(ComponentImportMapEntries::class);

            return;
        }

        $definition
            ->setClass(ComponentImportMapConfigReader::class)
            ->addMethodCall('setComponentEntries', [new Reference(ComponentImportMapEntries::class)]);
    }
}

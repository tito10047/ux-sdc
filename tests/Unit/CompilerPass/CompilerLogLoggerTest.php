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
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tito10047\UX\Sdc\CompilerPass\AssetComponentCompilerPass;
use Tito10047\UX\Sdc\CompilerPass\CompilerLogLogger;

final class CompilerLogLoggerTest extends TestCase
{
    public function testWritesInterpolatedMessagesToTheCompilerLog(): void
    {
        $container = new ContainerBuilder();
        $logger = new CompilerLogLogger($container, new AssetComponentCompilerPass());

        $logger->warning('Component "{component}" ({count} files, {missing}, {list}) in {path}', [
            'component' => 'App\Alert',
            'count' => 2,
            'path' => new class () implements \Stringable {
                public function __toString(): string
                {
                    return '/srv/app';
                }
            },
            'list' => ['not', 'a', 'string'],
        ]);

        $this->assertSame(
            [AssetComponentCompilerPass::class . ': [warning] Component "App\Alert" (2 files, {missing}, {list}) in /srv/app'],
            $container->getCompiler()->getLog(),
        );
    }
}

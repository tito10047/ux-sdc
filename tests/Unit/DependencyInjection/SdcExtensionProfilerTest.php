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
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Tito10047\UX\Sdc\CompilerPass\ProfilerCompilerPass;
use Tito10047\UX\Sdc\DataCollector\RequestTrace;
use Tito10047\UX\Sdc\DataCollector\SdcDataCollector;
use Tito10047\UX\Sdc\DependencyInjection\SdcExtension;
use Tito10047\UX\Sdc\EventListener\AssetResponseListener;
use Tito10047\UX\Sdc\Response\PageAssetInjector;

final class SdcExtensionProfilerTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideDebugValues(): iterable
    {
        yield 'false' => [false];
        yield 'unreadable' => ['maybe'];
    }

    #[DataProvider('provideDebugValues')]
    public function testThereIsNoCollectorWithoutDebug(mixed $debug): void
    {
        $container = $this->load($debug);

        $this->assertFalse($container->hasDefinition(SdcDataCollector::class));
        $this->assertFalse($container->hasDefinition(RequestTrace::class));
    }

    public function testRegistersTheCollectorInDebug(): void
    {
        $container = $this->load(true);

        $this->assertSame(
            [['template' => '@Sdc/data_collector/ux_sdc.html.twig', 'id' => 'ux_sdc', 'priority' => 255]],
            $container->getDefinition(SdcDataCollector::class)->getTag('data_collector'),
        );
        $this->assertSame([['method' => 'reset']], $container->getDefinition(RequestTrace::class)->getTag('kernel.reset'));
    }

    public function testKernelDebugFromAnEnvironmentVariableIsReadAtBuildTime(): void
    {
        $_SERVER['UX_SDC_TEST_DEBUG'] = '1';

        try {
            $this->assertTrue($this->load('%env(bool:UX_SDC_TEST_DEBUG)%')->hasDefinition(SdcDataCollector::class));
        } finally {
            unset($_SERVER['UX_SDC_TEST_DEBUG']);
        }
    }

    /**
     * The services recording into the trace take it as an optional dependency.
     */
    public function testTheServicesGetTheTraceOnlyIfItExists(): void
    {
        $container = $this->load(true);

        foreach ([AssetResponseListener::class, PageAssetInjector::class] as $id) {
            $trace = $container->getDefinition($id)->getArgument('$trace');
            $this->assertInstanceOf(Reference::class, $trace);
            $this->assertSame(RequestTrace::class, (string) $trace);
            $this->assertSame(ContainerInterface::NULL_ON_INVALID_REFERENCE, $trace->getInvalidBehavior());
        }
    }

    public function testThePassRemovesTheCollectorWithoutAProfiler(): void
    {
        $container = $this->load(true);

        (new ProfilerCompilerPass())->process($container);

        $this->assertFalse($container->hasDefinition(SdcDataCollector::class));
        $this->assertFalse($container->hasDefinition(RequestTrace::class));
    }

    public function testThePassKeepsTheCollectorForTheProfiler(): void
    {
        $container = $this->load(true);
        $container->register('profiler');

        (new ProfilerCompilerPass())->process($container);

        $this->assertTrue($container->hasDefinition(SdcDataCollector::class));
        $this->assertTrue($container->hasDefinition(RequestTrace::class));
    }

    private function load(mixed $debug): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', realpath(__DIR__ . '/../../..'));
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', $debug);
        $container->setParameter('kernel.cache_dir', sys_get_temp_dir());

        (new SdcExtension())->load([['metadata_cache' => true]], $container);

        return $container;
    }
}

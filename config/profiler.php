<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Tito10047\UX\Sdc\DataCollector\RequestTrace;
use Tito10047\UX\Sdc\DataCollector\SdcDataCollector;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The web profiler panel, loaded by SdcExtension in a debug container only.
 * ProfilerCompilerPass removes both services again when there is no profiler
 * to collect for, and the services recording into the trace then get null.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(RequestTrace::class)
        ->tag('kernel.reset', ['method' => 'reset']);

    // Right after Twig (257) and Twig Components (256) in the profiler menu.
    $services->set(SdcDataCollector::class)
        ->args([service(RequestTrace::class)])
        ->tag('data_collector', [
            'template' => SdcDataCollector::TEMPLATE,
            'id' => SdcDataCollector::NAME,
            'priority' => 255,
        ]);
};

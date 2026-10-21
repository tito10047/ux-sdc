<?php

use Symfony\Bundle\MakerBundle\MakerBundle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Tito10047\UX\Sdc\Runtime\SdcMetadataRegistry;
use Tito10047\UX\Sdc\Service\AssetRegistry;
use Tito10047\UX\Sdc\Service\ComponentMetadataResolver;
use Tito10047\UX\Sdc\Service\StimulusControllerNameResolver;
use Tito10047\UX\Sdc\EventListener\AssetResponseListener;
use Tito10047\UX\Sdc\EventListener\ComponentRenderListener;
use Tito10047\UX\Sdc\EventListener\DevComponentRenderListener;
use Tito10047\UX\Sdc\Twig\AssetExtension;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Tito10047\UX\Sdc\Maker\MakeSdcComponent;
use Tito10047\UX\Sdc\Routing\ComponentRouteLoader;
use Tito10047\UX\Sdc\Command\DebugSdcCommand;
use Tito10047\UX\Sdc\Debug\ComponentInspector;

use function Symfony\Component\DependencyInjection\Loader\Configurator\abstract_arg;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_closure;

/**
 * @link https://symfony.com/doc/current/bundles/best_practices.html#services
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Tagged explicitly: these definitions are not autoconfigured, so implementing
    // ResetInterface alone would not register the service with `services_resetter`.
    $services->set(AssetRegistry::class)
        ->tag('kernel.reset', ['method' => 'reset']);

    // Priority 64: the listener stores the preload links in the `_links` request
    // attribute, which WebLink's AddLinkHeaderListener (priority 0) turns into the
    // `Link` header — so it has to run first. At equal priority the order would
    // depend on how the two services happen to be registered.
    //
    // The other core `kernel.response` listeners run at 0 or below, and after this
    // one is what they should see: ResponseListener (0, `Response::prepare()`,
    // which empties the content of a HEAD response), the web profiler toolbar
    // (-128, injects before `</body>`, independent of the placeholder),
    // DisallowRobotsIndexingListener (-255), SessionListener (-1000) and
    // ProfilerListener (-1024, records the final response). The only one above is
    // ControllerAttributesListener (10000, Symfony 8.1+), which only applies the
    // headers of controller attributes such as #[Cache]. 64 keeps clear of the
    // default priority without claiming the top of the stack.
    //
    // The logger is optional, on the `ux_sdc` channel when MonologBundle is
    // installed (the tag is ignored otherwise).
    $services->set(AssetResponseListener::class)
        ->args([
            service(AssetRegistry::class),
            service(AssetMapperInterface::class),
        ])
        ->arg('$logger', service('logger')->nullOnInvalid())
        ->arg('$debug', '%kernel.debug%')
        ->tag('kernel.event_listener', [
            'event' => 'kernel.response',
            'method' => 'onKernelResponse',
            'priority' => 64,
        ])
        ->tag('monolog.logger', ['channel' => 'ux_sdc']);

    $services->set(StimulusControllerNameResolver::class)
        ->args(['%ux_sdc.component_namespace%']);

    // Besides rendering, both listeners hand the component namespace on
    // `kernel.controller` to a controller implementing ComponentNamespaceInterface:
    // a page controller using the Stimulus trait, or a LiveComponent, which is the
    // controller of its own re-render request and is never mounted in it.
    $services->set(ComponentRenderListener::class)
        ->args([
            service(SdcMetadataRegistry::class),
            service(AssetRegistry::class),
            service(StimulusControllerNameResolver::class),
            '%ux_sdc.component_namespace%',
        ])
        ->tag('kernel.event_listener', [
            'event' => 'Symfony\UX\TwigComponent\Event\PostMountEvent',
            'method' => 'onPostMount',
        ])
        ->tag('kernel.event_listener', [
            'event' => 'Symfony\UX\TwigComponent\Event\PreRenderEvent',
            'method' => 'onPreRender',
        ])
        ->tag('kernel.event_listener', [
            'event' => 'kernel.controller',
            'method' => 'onKernelController',
        ]);

    $services->set(DevComponentRenderListener::class)
        ->args([
            service(ComponentMetadataResolver::class),
            service(AssetRegistry::class),
            service(StimulusControllerNameResolver::class),
            '%ux_sdc.component_namespace%',
        ])
        ->tag('kernel.event_listener', [
            'event' => 'Symfony\UX\TwigComponent\Event\PostMountEvent',
            'method' => 'onPostMount',
        ])
        ->tag('kernel.event_listener', [
            'event' => 'Symfony\UX\TwigComponent\Event\PreRenderEvent',
            'method' => 'onPreRender',
        ])
        ->tag('kernel.event_listener', [
            'event' => 'kernel.controller',
            'method' => 'onKernelController',
        ]);

    $services->set(AssetExtension::class)
        ->args(['$placeholder' => '<!-- __UX_TWIG_COMPONENT_ASSETS__ -->'])
        ->tag('twig.extension');

    // Registered whether or not the bundle contributes routes: an application
    // importing "type: ux_sdc" keeps working with "ux_sdc.routing.enabled: false"
    // or without a component namespace, and the import then loads nothing.
    $services->set(ComponentRouteLoader::class)
        ->args([
            abstract_arg('ux_components_dir, absolute, or null'),
            abstract_arg('component_namespace, or null'),
        ])
        ->tag('routing.loader');

    if (class_exists(Command::class)) {
        // Instantiated only when debug:sdc runs: the command is lazy, its name
        // being known from #[AsCommand].
        $services->set(ComponentInspector::class)
            ->args([
                service('ux.twig_component.component_factory'),
                service(SdcMetadataRegistry::class),
                service(ComponentMetadataResolver::class),
                abstract_arg('whether ux_sdc.metadata_cache resolved to true, set by SdcExtension'),
                service('twig'),
                service(AssetMapperInterface::class),
                service(StimulusControllerNameResolver::class),
                // Absent without StimulusBundle, or when it does not use AssetMapper.
                service('stimulus.asset_mapper.controllers_map_generator')->nullOnInvalid(),
                service('router')->nullOnInvalid(),
                abstract_arg('the classes of the twig.component services, set by AssetComponentCompilerPass'),
                abstract_arg('the component classes registered with an explicit template, set by AssetComponentCompilerPass'),
            ]);

        $services->set(DebugSdcCommand::class)
            ->args([service(ComponentInspector::class)])
            ->tag('console.command');
    }

    if (class_exists(MakerBundle::class)) {
        $services->set(MakeSdcComponent::class)
            ->args([
                '%ux_sdc.ux_components_dir%',
                '%ux_sdc.component_namespace%',
                // Only --action needs Twig. Built eagerly, a Twig setup that cannot be
                // created (a configured path that does not exist) would take the whole
                // maker down with it.
                service_closure('twig'),
                '%ux_sdc.routing.enabled%',
            ])
            ->tag('maker.command');
    }
};

<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\StimulusBundle\AssetMapper\ControllersMapGenerator;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component\Stimulus\Page\PageAction;
use Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component\Stimulus\Plain\Plain;
use Tito10047\UX\Sdc\Tests\Integration\Fixtures\OutsideNamespace\OutsideComponent;
use Twig\Environment;

/**
 * The `controller` template variable, set by the render listeners for every
 * component in component_namespace. The fixture templates print it, or
 * "(undefined)", inside the root element of the component.
 */
class StimulusIntegrationTest extends IntegrationTestCase
{
    /**
     * Each value selects a different render listener; both set the variable.
     *
     * @return iterable<string, array{bool}>
     */
    public static function provideMetadataCache(): iterable
    {
        yield 'metadata resolved on render' => [false];
        yield 'metadata cached at build time' => [true];
    }

    #[DataProvider('provideMetadataCache')]
    public function testStimulusTraitReceivesNamespace(bool $metadataCache): void
    {
        self::bootKernel(['configs' => ['metadata_cache' => $metadataCache]]);

        // The fixture template prints {{ controller }}: the class name relative to
        // the namespace the listener sets on PostMountEvent. Without it the trait
        // throws; with a wrong one the name keeps a namespace prefix.
        $this->assertSame('StimulusComponent', $this->render('stimulus_component'));
    }

    /**
     * @return iterable<string, array{bool, string, string}>
     */
    public static function provideComponentsWithoutTrait(): iterable
    {
        foreach (self::provideMetadataCache() as $pipeline => [$metadataCache]) {
            yield $pipeline.', embedding another' => [$metadataCache, 'stimulus_host', 'Stimulus--Host|Stimulus--Plain--Plain'];
            yield $pipeline.', in a sub-namespace' => [$metadataCache, 'stimulus_plain', 'Stimulus--Plain--Plain'];
            yield $pipeline.', underscores' => [$metadataCache, 'stimulus_date_picker', 'Stimulus--Date-Picker--Date-Picker'];
        }
    }

    #[DataProvider('provideComponentsWithoutTrait')]
    public function testComponentWithoutTraitOrInterfaceGetsController(bool $metadataCache, string $component, string $expected): void
    {
        self::bootKernel(['configs' => ['metadata_cache' => $metadataCache]]);

        $this->assertSame($expected, $this->render($component));
    }

    /**
     * `{{ controller }}` is meant for stimulus_controller() and for
     * `data-controller` written by hand, so it has to be the very name
     * StimulusBundle registers for the `_controller.js` next to the class.
     */
    public function testControllerIsTheNameStimulusBundleRegisters(): void
    {
        if (!class_exists(ControllersMapGenerator::class)) {
            self::markTestSkipped('symfony/stimulus-bundle is not installed.');
        }

        self::bootKernel();

        $generator = self::getContainer()->get('stimulus.asset_mapper.controllers_map_generator');
        $this->assertInstanceOf(ControllersMapGenerator::class, $generator);
        $registered = array_keys($generator->getControllersMap());

        foreach (['stimulus_plain', 'stimulus_date_picker'] as $component) {
            $this->assertContains($this->render($component), $registered);
        }
    }

    /**
     * @return iterable<string, array{bool, string, string}>
     */
    public static function provideComponentsWithOwnController(): iterable
    {
        foreach (self::provideMetadataCache() as $pipeline => [$metadataCache]) {
            yield $pipeline.', public property' => [$metadataCache, 'stimulus_own_property', 'own-property'];
            yield $pipeline.', #[ExposeInTemplate] method' => [$metadataCache, 'stimulus_own_method', 'own-method'];
            yield $pipeline.', #[ExposeInTemplate] private property' => [$metadataCache, 'stimulus_own_private_property', 'own-private-property'];
        }
    }

    #[DataProvider('provideComponentsWithOwnController')]
    public function testControllerExposedByTheComponentIsKept(bool $metadataCache, string $component, string $expected): void
    {
        self::bootKernel(['configs' => ['metadata_cache' => $metadataCache]]);

        $this->assertSame($expected, $this->render($component));
    }

    /**
     * An embedded component receives the context of the template around it,
     * `controller` included. Its own name has to replace that one.
     */
    #[DataProvider('provideMetadataCache')]
    public function testEmbeddedComponentDoesNotInheritController(bool $metadataCache): void
    {
        self::bootKernel(['configs' => ['metadata_cache' => $metadataCache]]);

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        $this->assertSame('Stimulus--Plain--Plain', trim(strip_tags($twig->render('stimulus_embedded_in_page.html.twig'))));
    }

    #[DataProvider('provideMetadataCache')]
    public function testComponentOutsideNamespaceGetsNothing(bool $metadataCache): void
    {
        self::bootKernel([
            'configs' => ['metadata_cache' => $metadataCache],
            'services' => [OutsideComponent::class],
        ]);

        $this->assertSame('(undefined)', $this->render('stimulus_outside'));
    }

    #[DataProvider('provideMetadataCache')]
    public function testWithoutNamespaceNoComponentGetsController(bool $metadataCache): void
    {
        self::bootKernel([
            'configs' => ['metadata_cache' => $metadataCache, 'component_namespace' => null],
            'services' => [Plain::class],
            // Required by TwigComponent 2.13+, prepended by the bundle only for component_namespace.
            'twig_component' => ['defaults' => ['App\\Twig\\Components\\' => 'components/']],
        ]);

        $this->assertSame('(undefined)', $this->render('stimulus_plain'));
    }

    /**
     * A LiveComponent is mounted for its first render and hydrated, not
     * mounted, for every request of its own; the variable is set in both.
     */
    #[DataProvider('provideMetadataCache')]
    public function testLiveComponentGetsController(bool $metadataCache): void
    {
        if (!trait_exists(InteractsWithLiveComponents::class)) {
            self::markTestSkipped('symfony/ux-live-component is not installed.');
        }

        $testCase = new class ('test') extends IntegrationTestCase {
            use InteractsWithLiveComponents;

            public function test(): void
            {
            }

            /**
             * @return array{string, string}
             */
            public function renderMountedAndHydrated(bool $metadataCache): array
            {
                self::bootKernel(['configs' => ['metadata_cache' => $metadataCache]]);

                /** @var ComponentRendererInterface $renderer */
                $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

                return [
                    trim(strip_tags($renderer->createAndRender('stimulus_live_plain', ['id' => 'stimulus-live-plain']))),
                    trim(strip_tags((string) $this->createLiveComponent('stimulus_live_plain')->render())),
                ];
            }
        };

        $this->assertSame(['Stimulus--LivePlain', 'Stimulus--LivePlain'], $testCase->renderMountedAndHydrated($metadataCache));
    }

    /**
     * A page controller using the Stimulus trait gets the namespace on
     * kernel.controller, with or without LiveComponent installed.
     */
    #[DataProvider('provideMetadataCache')]
    public function testControllerImplementingInterfaceReceivesNamespace(bool $metadataCache): void
    {
        self::bootKernel(['configs' => ['metadata_cache' => $metadataCache]]);

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $controller = new PageAction();

        $dispatcher->dispatch(
            new ControllerEvent(self::$kernel, $controller, new Request(), HttpKernelInterface::MAIN_REQUEST),
            KernelEvents::CONTROLLER,
        );

        $this->assertSame('Stimulus--Page--PageAction', $controller->getController());
    }

    private function render(string $component): string
    {
        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        return trim(strip_tags($renderer->createAndRender($component)));
    }
}

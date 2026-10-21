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
use Symfony\UX\TwigComponent\ComponentRendererInterface;

class StimulusIntegrationTest extends IntegrationTestCase
{
    /**
     * Each value selects a different render listener, and both of them hand the
     * component namespace to the Stimulus trait.
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

        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        // The fixture template prints {{ controller }}: the class name relative to
        // the namespace the listener sets on PostMountEvent. Without it the trait
        // throws; with a wrong one the name keeps a namespace prefix.
        $html = $renderer->createAndRender('stimulus_component');

        $this->assertSame('StimulusComponent', trim($html));
    }
}

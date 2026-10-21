<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Tito10047\UX\Sdc\Service\ComponentMetadataResolver;
use Tito10047\UX\Sdc\Tests\TestLogger;
use Tito10047\UX\Sdc\Tests\Unit\Service\Fixtures\Alert\Alert;
use Tito10047\UX\Sdc\Tests\Unit\Service\Fixtures\SdcAlert\SdcAlert;

final class ComponentMetadataResolverTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/Fixtures';

    /**
     * A directory that exists but does not contain the fixture.
     */
    private const UNRELATED_ROOT = __DIR__ . '/../../Integration/Fixtures/templates';

    private const EXPLICIT_ASSET = ['path' => 'vendor/explicit.css', 'type' => '', 'priority' => 3, 'attributes' => [], 'source' => '#[Asset]'];

    public function testResolvesFilesNextToTheClassRelativeToTheirRoots(): void
    {
        $logger = new TestLogger();
        $metadata = (new ComponentMetadataResolver([self::FIXTURES], [self::FIXTURES], true, $logger, true))->resolve(Alert::class);

        $this->assertSame([
            self::EXPLICIT_ASSET,
            ['path' => 'Alert/Alert.css', 'type' => 'css', 'priority' => 0, 'attributes' => [], 'source' => 'discovered'],
            ['path' => 'Alert/Alert.js', 'type' => 'js', 'priority' => 0, 'attributes' => [], 'source' => 'discovered'],
        ], $metadata->assets);
        $this->assertSame('Alert/Alert.html.twig', $metadata->template);
        $this->assertSame([], $metadata->problems);
        $this->assertSame([], $logger->records);
    }

    /**
     * A file outside every root has no name to be loaded by: it is left out
     * instead of being guessed (it used to become "Alert.css", a 404).
     */
    public function testLeavesOutFilesOutsideEveryRootAndWarnsInDebug(): void
    {
        $logger = new TestLogger();
        $metadata = (new ComponentMetadataResolver([self::UNRELATED_ROOT], [self::UNRELATED_ROOT], true, $logger, true))->resolve(Alert::class);

        $this->assertSame([self::EXPLICIT_ASSET], $metadata->assets, 'Explicit assets are not affected.');
        $this->assertNull($metadata->template);

        $warnings = $logger->recordsOf(LogLevel::WARNING);
        $this->assertCount(3, $warnings);
        $this->assertSame($logger->records, $warnings);

        $files = array_map(static fn (array $record): mixed => $record['context']['file'], $warnings);
        $this->assertSame([
            realpath(self::FIXTURES . '/Alert/Alert.css'),
            realpath(self::FIXTURES . '/Alert/Alert.js'),
            realpath(self::FIXTURES . '/Alert/Alert.html.twig'),
        ], $files);

        foreach ($warnings as $warning) {
            $this->assertSame(Alert::class, $warning['context']['component']);
            $this->assertSame(realpath(self::UNRELATED_ROOT), $warning['context']['roots']);
            $this->assertStringContainsString('"{component}"', $warning['message']);
            $this->assertStringContainsString('ux_sdc.ux_components_dir', $warning['message']);
        }

        $this->assertStringContainsString('outside every AssetMapper path', $warnings[0]['message']);
        $this->assertStringContainsString('#[Asset]', $warnings[0]['message']);
        $this->assertStringContainsString('outside every Twig path', $warnings[2]['message']);
        $this->assertStringContainsString('"template" argument', $warnings[2]['message']);

        // The log and debug:sdc read the same problems.
        $this->assertCount(3, $metadata->problems);
        foreach ($metadata->problems as $i => $problem) {
            $this->assertSame('Component "{component}": ' . $problem['message'], $warnings[$i]['message']);
            $this->assertSame(['component' => Alert::class] + $problem['context'], $warnings[$i]['context']);
        }
        $this->assertSame(
            '"' . realpath(self::FIXTURES . '/Alert/Alert.css') . '" next to the class is outside every AssetMapper path (' . realpath(self::UNRELATED_ROOT) . '), so it is not loaded with the component. Move the component into the directory configured as "ux_sdc.ux_components_dir", add its directory to "framework.asset_mapper.paths", or reference the file by its AssetMapper logical path with #[Asset].',
            $metadata->getProblemMessages()[0],
        );
    }

    public function testNamesWhereEachAssetComesFrom(): void
    {
        $metadata = (new ComponentMetadataResolver([], [self::FIXTURES], true))->resolve(SdcAlert::class);

        $this->assertSame([
            ['css/sdc.css', '#[AsSdcComponent]'],
            ['js/sdc.js', '#[AsSdcComponent]'],
            ['SdcAlert/SdcAlert.css', 'discovered'],
        ], array_map(static fn (array $asset): array => [$asset['path'], $asset['source']], $metadata->assets));
    }

    /**
     * A template under a namespaced Twig path is only loadable by its
     * namespaced name.
     */
    public function testNamesTheTemplateWithTheNamespaceOfItsTwigPath(): void
    {
        $metadata = (new ComponentMetadataResolver([self::FIXTURES => 'ui'], [self::FIXTURES], true))->resolve(Alert::class);

        $this->assertSame('@ui/Alert/Alert.html.twig', $metadata->template);
    }

    /**
     * `ux_components_dir` is registered both in the main namespace and as
     * @component: the name without a namespace is kept, whatever the order.
     */
    public function testPrefersTheMainNamespace(): void
    {
        $roots = [self::FIXTURES . '/' => 'component', self::FIXTURES => null];

        $this->assertSame('Alert/Alert.html.twig', (new ComponentMetadataResolver($roots, [], true))->resolve(Alert::class)->template);
        $this->assertSame('Alert/Alert.html.twig', (new ComponentMetadataResolver(array_reverse($roots, true), [], true))->resolve(Alert::class)->template);
    }

    /**
     * A logical path depends on the AssetMapper paths alone, prefixed with
     * their namespace; the Twig namespace plays no part in it.
     */
    public function testNamesAssetsByTheirAssetMapperPathAndNamespace(): void
    {
        $metadata = (new ComponentMetadataResolver([self::FIXTURES => 'ui'], [self::FIXTURES => 'lib/ui'], true))->resolve(Alert::class);

        $this->assertSame(['vendor/explicit.css', 'lib/ui/Alert/Alert.css', 'lib/ui/Alert/Alert.js'], array_column($metadata->assets, 'path'));
        $this->assertSame('@ui/Alert/Alert.html.twig', $metadata->template);
    }

    /**
     * As AssetMapper names a file: by the first of its paths that contains it.
     */
    public function testNamesAssetsByTheFirstAssetMapperPathContainingThem(): void
    {
        $metadata = (new ComponentMetadataResolver([], [self::FIXTURES . '/Alert' => 'alert', self::FIXTURES => ''], true))->resolve(Alert::class);

        $this->assertSame(['vendor/explicit.css', 'alert/Alert.css', 'alert/Alert.js'], array_column($metadata->assets, 'path'));
    }

    /**
     * A Twig path that is not an AssetMapper path, such as `templates/`: the
     * template is found, a stylesheet or script could not be served.
     */
    public function testLeavesOutAssetsUnderATwigPathThatIsNoAssetMapperPath(): void
    {
        $logger = new TestLogger();
        $metadata = (new ComponentMetadataResolver([self::FIXTURES], [self::UNRELATED_ROOT => ''], true, $logger, true))->resolve(Alert::class);

        $this->assertSame([self::EXPLICIT_ASSET], $metadata->assets);
        $this->assertSame('Alert/Alert.html.twig', $metadata->template);

        $this->assertCount(2, $logger->records);
        foreach ($logger->records as $record) {
            $this->assertStringContainsString('outside every AssetMapper path', $record['message']);
            $this->assertSame(realpath(self::UNRELATED_ROOT), $record['context']['roots']);
        }
    }

    /**
     * A template named in the registration of the component wins over the one
     * next to its class, which is then not even looked up (nor reported).
     */
    public function testLeavesTheTemplateToAComponentRegisteredWithOne(): void
    {
        $logger = new TestLogger();
        $resolver = new ComponentMetadataResolver([self::UNRELATED_ROOT], [self::FIXTURES], true, $logger, true, [Alert::class]);
        $metadata = $resolver->resolve(Alert::class);

        $this->assertNull($metadata->template);
        $this->assertSame(['vendor/explicit.css', 'Alert/Alert.css', 'Alert/Alert.js'], array_column($metadata->assets, 'path'), 'Assets are still discovered.');
        $this->assertSame([], $logger->records);
    }

    public function testWarnsOncePerClass(): void
    {
        $logger = new TestLogger();
        $resolver = new ComponentMetadataResolver([self::UNRELATED_ROOT], [self::UNRELATED_ROOT], true, $logger, true);

        $resolver->resolve(Alert::class);
        $resolver->resolve(Alert::class);

        $this->assertCount(3, $logger->records);
    }

    /**
     * Not logged, but still reported in the metadata, for debug:sdc.
     */
    public function testDoesNotWarnOutsideDebug(): void
    {
        $logger = new TestLogger();
        $metadata = (new ComponentMetadataResolver([self::UNRELATED_ROOT], [self::UNRELATED_ROOT], true, $logger, false))->resolve(Alert::class);

        $this->assertSame([self::EXPLICIT_ASSET], $metadata->assets);
        $this->assertNull($metadata->template);
        $this->assertCount(3, $metadata->problems);
        $this->assertSame([], $logger->records);
    }

    public function testNamesTheMissingRootsWhenThereAreNone(): void
    {
        $logger = new TestLogger();
        (new ComponentMetadataResolver([], [], true, $logger, true))->resolve(Alert::class);

        $this->assertSame('none configured', $logger->records[0]['context']['roots']);
    }

    public function testWithoutAutoDiscoveryOnlyExplicitAssetsAreResolved(): void
    {
        $logger = new TestLogger();
        $metadata = (new ComponentMetadataResolver([self::UNRELATED_ROOT], [self::UNRELATED_ROOT], false, $logger, true))->resolve(Alert::class);

        $this->assertSame([self::EXPLICIT_ASSET], $metadata->assets);
        $this->assertNull($metadata->template);
        $this->assertSame([], $logger->records);
    }

    public function testUnknownClassHasNoMetadata(): void
    {
        $metadata = (new ComponentMetadataResolver([self::FIXTURES], [self::FIXTURES], true))->resolve('App\\DoesNotExist');

        $this->assertTrue($metadata->isEmpty());
    }
    /**
     * An anonymous component is resolved from its template: the stylesheet and
     * script named after it, next to it.
     */
    public function testResolvesAnAnonymousComponentFromItsTemplate(): void
    {
        $logger = new TestLogger();
        $metadata = (new ComponentMetadataResolver([self::FIXTURES], [self::FIXTURES], true, $logger, true))->resolveAnonymous('components/Notice.html.twig');

        $this->assertSame([
            ['path' => 'components/Notice.css', 'type' => 'css', 'priority' => 0, 'attributes' => [], 'source' => 'discovered'],
            ['path' => 'components/Notice.js', 'type' => 'js', 'priority' => 0, 'attributes' => [], 'source' => 'discovered'],
        ], $metadata->assets);
        $this->assertNull($metadata->template, 'Twig Components renders the template it found.');
        $this->assertSame([], $metadata->problems);
        $this->assertSame([], $logger->records);
    }

    /**
     * The template is looked up in the Twig paths of its namespace; the assets
     * are named after their AssetMapper path, whatever the Twig namespace.
     */
    public function testResolvesAnAnonymousComponentOfANamespacedTwigPath(): void
    {
        $metadata = (new ComponentMetadataResolver([self::FIXTURES . '/Acme' => 'Acme'], [self::FIXTURES => 'lib'], true))->resolveAnonymous('@Acme/components/Button.html.twig');

        $this->assertSame(['lib/Acme/components/Button.css'], array_column($metadata->assets, 'path'));
    }

    public function testLeavesOutFilesNextToAnAnonymousTemplateOutsideEveryAssetMapperPath(): void
    {
        $logger = new TestLogger();
        $resolver = new ComponentMetadataResolver([self::FIXTURES], [self::UNRELATED_ROOT], true, $logger, true);
        $metadata = $resolver->resolveAnonymous('components/Notice.html.twig');

        $this->assertSame([], $metadata->assets);
        $this->assertCount(2, $metadata->problems);

        $warnings = $logger->recordsOf(LogLevel::WARNING);
        $this->assertCount(2, $warnings);
        $this->assertSame('Anonymous component "{component}": "{file}" next to the template is outside every AssetMapper path ({roots}), so it is not loaded with the component. Move the component into the directory configured as "ux_sdc.ux_components_dir", or add its directory to "framework.asset_mapper.paths".', $warnings[0]['message']);
        $this->assertSame('components/Notice.html.twig', $warnings[0]['context']['component']);
        $this->assertSame(realpath(self::FIXTURES . '/components/Notice.css'), $warnings[0]['context']['file']);
        $this->assertSame(realpath(self::FIXTURES . '/components/Notice.js'), $warnings[1]['context']['file']);

        // Once per template.
        $this->assertSame($metadata, $resolver->resolveAnonymous('components/Notice.html.twig'));
        $this->assertCount(2, $logger->records);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function anonymousTemplatesWithoutAssets(): iterable
    {
        yield 'no file next to the template' => ['components/Panel/index.html.twig'];
        yield 'not in any Twig path' => ['components/Missing.html.twig'];
        yield 'unknown namespace' => ['@Missing/components/Notice.html.twig'];
        yield 'not a .html.twig template' => ['components/Notice.css'];
    }

    #[DataProvider('anonymousTemplatesWithoutAssets')]
    public function testAnonymousComponentWithoutFilesNextToItHasNoMetadata(string $template): void
    {
        $logger = new TestLogger();
        $metadata = (new ComponentMetadataResolver([self::FIXTURES], [self::FIXTURES], true, $logger, true))->resolveAnonymous($template);

        $this->assertTrue($metadata->isEmpty());
        $this->assertSame([], $logger->records);
    }

    public function testWithoutAutoDiscoveryAnAnonymousComponentHasNoMetadata(): void
    {
        $metadata = (new ComponentMetadataResolver([self::FIXTURES], [self::FIXTURES], false))->resolveAnonymous('components/Notice.html.twig');

        $this->assertTrue($metadata->isEmpty());
    }

    /**
     * As the Twig filesystem loader finds a template: in the paths of its
     * namespace, in order, the name normalised.
     *
     * @param array<int|string, string|null> $twigRoots
     */
    #[DataProvider('templateLocations')]
    public function testLocatesATemplateTheWayTwigDoes(array $twigRoots, string $template, ?string $expected): void
    {
        $resolver = new ComponentMetadataResolver($twigRoots, [], true);

        $this->assertSame(null === $expected ? null : realpath($expected), $resolver->locateTemplate($template));
    }

    /**
     * @return iterable<string, array{array<int|string, string|null>, string, string|null}>
     */
    public static function templateLocations(): iterable
    {
        $notice = self::FIXTURES . '/components/Notice.html.twig';

        yield 'main namespace' => [[self::FIXTURES], 'components/Notice.html.twig', $notice];
        yield 'first path holding it' => [[self::UNRELATED_ROOT, self::FIXTURES], 'components/Notice.html.twig', $notice];
        yield 'leading and doubled slashes' => [[self::FIXTURES], '/components//Notice.html.twig', $notice];
        yield 'backslashes' => [[self::FIXTURES], 'components\\Notice.html.twig', $notice];
        yield 'namespace' => [[self::FIXTURES => 'ui'], '@ui/components/Notice.html.twig', $notice];
        yield 'main namespace is not a named one' => [[self::FIXTURES => 'ui'], 'components/Notice.html.twig', null];
        yield 'named namespace is not the main one' => [[self::FIXTURES], '@ui/components/Notice.html.twig', null];
        yield 'namespace without a path' => [[self::FIXTURES => 'ui'], '@ui', null];
        yield 'missing' => [[self::FIXTURES], 'components/Missing.html.twig', null];
    }
}

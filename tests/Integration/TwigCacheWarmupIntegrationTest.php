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
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerAggregate;
use Tito10047\UX\Sdc\DataCollector\SdcDataCollector;
use Tito10047\UX\Sdc\SdcBundle;
use Twig\Environment;

/**
 * TwigBundle registers the templates/ directory of every bundle as a Twig
 * namespace, and its TemplateCacheWarmer compiles every file in it on
 * `cache:clear` / `cache:warmup` — every file, not only *.twig, unless the
 * application sets `twig.file_name_pattern` (the TwigBundle recipe does since
 * 6.4, older and hand-written configurations do not). Whatever the bundle keeps
 * there therefore has to be a valid Twig template; the maker's PHP skeletons
 * used to live there and filled the log of every application with Twig
 * deprecations.
 */
final class TwigCacheWarmupIntegrationTest extends IntegrationTestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideKernels(): iterable
    {
        yield 'prod' => [['environment' => 'prod', 'debug' => false]];
        yield 'dev with the web profiler' => [[
            'environment' => 'dev',
            'debug' => true,
            'framework' => ['profiler' => ['enabled' => true, 'collect' => true]],
            'bundles' => [new WebProfilerBundle()],
        ]];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('provideKernels')]
    public function testWarmingTheCacheRaisesNoDeprecationFromTheBundleTemplates(array $options): void
    {
        self::bootKernel($options);

        $cacheWarmer = self::getContainer()->get('cache_warmer');
        $this->assertInstanceOf(CacheWarmerAggregate::class, $cacheWarmer);
        // What `cache:warmup` and `cache:clear` do; booting a kernel skips the optional warmers, Twig's among them.
        $cacheWarmer->enableOptionalWarmers();

        $deprecations = [];
        set_error_handler(static function (int $type, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, \E_USER_DEPRECATED | \E_DEPRECATED);
        try {
            $cacheWarmer->warmUp(self::$kernel->getCacheDir(), self::$kernel->getBuildDir());
        } finally {
            restore_error_handler();
        }

        // Other bundles and the fixtures of the application are not this bundle's concern.
        $fromBundle = array_values(array_filter(
            $deprecations,
            static fn (string $message): bool => 1 === preg_match('~@!?Sdc/~', $message),
        ));
        $this->assertSame([], $fromBundle);
    }

    /**
     * The warmer swallows Twig errors, so a template that does not compile
     * would go unnoticed there: every file of the namespace is loaded here.
     */
    public function testEveryFileOfTheBundleTwigNamespaceIsAValidTemplate(): void
    {
        self::bootKernel([
            'environment' => 'dev',
            'debug' => true,
            'framework' => ['profiler' => ['enabled' => true, 'collect' => true]],
            'bundles' => [new WebProfilerBundle()],
        ]);
        $twig = self::getContainer()->get('twig');
        $this->assertInstanceOf(Environment::class, $twig);

        $names = [];
        foreach (Finder::create()->files()->in((new SdcBundle())->getPath().'/templates')->sortByName() as $file) {
            $names[] = '@Sdc/'.str_replace('\\', '/', $file->getRelativePathname());
        }

        $this->assertContains(SdcDataCollector::TEMPLATE, $names);
        foreach ($names as $name) {
            $this->assertStringNotContainsString('.php', $name, 'PHP files do not belong in the Twig namespace of the bundle.');
            $twig->load($name);
        }
    }
}

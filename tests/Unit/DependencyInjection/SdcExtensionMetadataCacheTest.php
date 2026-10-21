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
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\MergeExtensionConfigurationPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tito10047\UX\Sdc\CompilerPass\AssetComponentCompilerPass;
use Tito10047\UX\Sdc\DependencyInjection\SdcExtension;
use Tito10047\UX\Sdc\EventListener\ComponentRenderListener;
use Tito10047\UX\Sdc\EventListener\DevComponentRenderListener;

/**
 * `ux_sdc.metadata_cache` is resolved once, in the extension, into the build
 * parameter the compiler pass reads and the choice of the render listener.
 */
class SdcExtensionMetadataCacheTest extends TestCase
{
    private const DEBUG_VARIABLE = 'UX_SDC_TEST_DEBUG';

    protected function tearDown(): void
    {
        unset($_SERVER[self::DEBUG_VARIABLE], $_ENV[self::DEBUG_VARIABLE]);
    }

    /**
     * @return iterable<string, array{bool|string|null, string, bool, bool}>
     */
    public static function provideOptions(): iterable
    {
        // [metadata_cache (null: not configured), kernel.environment, kernel.debug, cache expected]
        yield 'default, debug' => [null, 'dev', true, false];
        yield 'default, no debug' => [null, 'prod', false, true];
        yield 'auto, debug' => ['auto', 'dev', true, false];
        yield 'auto, no debug' => ['auto', 'prod', false, true];
        yield 'auto ignores an environment named dev' => ['auto', 'dev', false, true];
        yield 'auto ignores an environment named prod' => ['auto', 'prod', true, false];
        yield 'auto in a custom environment with debug' => ['auto', 'staging', true, false];
        yield 'true wins over debug' => [true, 'dev', true, true];
        yield 'true without debug' => [true, 'prod', false, true];
        yield 'false wins over no debug' => [false, 'prod', false, false];
        yield 'false with debug' => [false, 'dev', true, false];
    }

    #[DataProvider('provideOptions')]
    public function testOptionSelectsThePipeline(bool|string|null $metadataCache, string $environment, bool $debug, bool $cacheExpected): void
    {
        $container = $this->createContainer($debug, $environment);

        $this->load($container, $metadataCache);

        $this->assertPipeline($container, $cacheExpected);
    }

    /**
     * A container built without a kernel is a production build to everything else.
     */
    public function testAutoWithoutKernelDebugUsesTheCache(): void
    {
        $container = $this->createContainer(null);

        $this->load($container, 'auto');

        $this->assertPipeline($container, true);
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function provideDebugFromEnvironmentVariable(): iterable
    {
        yield 'bool processor, on' => ['%env(bool:'.self::DEBUG_VARIABLE.')%', '1', false];
        yield 'bool processor, off' => ['%env(bool:'.self::DEBUG_VARIABLE.')%', '0', true];
        yield 'raw string, on' => ['%env('.self::DEBUG_VARIABLE.')%', 'true', false];
        yield 'raw string, off' => ['%env('.self::DEBUG_VARIABLE.')%', 'off', true];
    }

    /**
     * The listeners are chosen at build time, so the variable is read then.
     */
    #[DataProvider('provideDebugFromEnvironmentVariable')]
    public function testAutoReadsKernelDebugFromAnEnvironmentVariable(string $parameter, string $value, bool $cacheExpected): void
    {
        $_SERVER[self::DEBUG_VARIABLE] = $_ENV[self::DEBUG_VARIABLE] = $value;
        $container = $this->createContainer($parameter);

        $this->load($container, 'auto');

        $this->assertPipeline($container, $cacheExpected);
    }

    public function testAutoRejectsAKernelDebugThatIsNotABoolean(): void
    {
        $container = $this->createContainer('sometimes');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Set "ux_sdc.metadata_cache" to true or false.');

        $this->load($container, 'auto');
    }

    public function testAnExplicitValueDoesNotLookAtKernelDebug(): void
    {
        $container = $this->createContainer('sometimes');

        $this->load($container, false);

        $this->assertPipeline($container, false);
    }

    /**
     * The value picks services at build time; a variable read later could not
     * change them. MergeExtensionConfigurationPass is what hands the extension an
     * environment variable placeholder, as in a real kernel.
     */
    public function testOptionCannotComeFromAnEnvironmentVariable(): void
    {
        $container = $this->createContainer(true);
        $container->registerExtension(new SdcExtension());
        $container->loadFromExtension('ux_sdc', ['metadata_cache' => '%env(bool:'.self::DEBUG_VARIABLE.')%']);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('cannot come from an environment variable');

        (new MergeExtensionConfigurationPass())->process($container);
    }

    private function createContainer(bool|string|null $debug, string $environment = 'prod'): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', realpath(__DIR__.'/../../..'));
        $container->setParameter('kernel.environment', $environment);

        if (null !== $debug) {
            $container->setParameter('kernel.debug', $debug);
        }

        return $container;
    }

    private function load(ContainerBuilder $container, bool|string|null $metadataCache): void
    {
        $config = null === $metadataCache ? [] : ['metadata_cache' => $metadataCache];

        (new SdcExtension())->load([$config], $container);
    }

    private function assertPipeline(ContainerBuilder $container, bool $cacheExpected): void
    {
        $this->assertSame($cacheExpected, $container->getParameter(AssetComponentCompilerPass::METADATA_CACHE_PARAMETER));
        $this->assertSame($cacheExpected, $container->hasDefinition(ComponentRenderListener::class));
        $this->assertSame(!$cacheExpected, $container->hasDefinition(DevComponentRenderListener::class));
    }
}

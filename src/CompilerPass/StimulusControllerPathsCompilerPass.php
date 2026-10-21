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

use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\LogicException;

/**
 * Registers the component directory as a Stimulus controller path.
 *
 * The directory is appended to the controller paths StimulusBundle has already
 * resolved from the application configuration, instead of being prepended to
 * the "stimulus.controller_paths" configuration. A prepended value is a value
 * like any other, so it suppressed StimulusBundle's default
 * (%kernel.project_dir%/assets/controllers) and silently unregistered every
 * controller of an application that had no config/packages/stimulus.yaml.
 *
 * The controllers map generator is the only consumer of those paths: it finds
 * the controllers in them, and the loader compiler asks it for the same list
 * to track the directories as file dependencies. Its constructor has been
 * (AssetMapperInterface, UxPackageReader, array $controllerPaths, string,
 * ?AutoImportLocator) from StimulusBundle 2.13 to 3.x; the argument is still
 * looked up by name, so that a future change fails loudly here rather than
 * handing the component directory to a different argument.
 */
final class StimulusControllerPathsCompilerPass implements CompilerPassInterface
{
    /**
     * A build parameter (leading dot): it is not dumped into the compiled container.
     */
    public const ENABLED_PARAMETER = '.ux_sdc.stimulus.enabled';

    private const COMPONENTS_DIR_PARAMETER = 'ux_sdc.ux_components_dir';
    private const CONTROLLERS_MAP_GENERATOR = 'stimulus.asset_mapper.controllers_map_generator';
    private const CONTROLLER_PATHS_ARGUMENT = 'controllerPaths';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::ENABLED_PARAMETER) || true !== $container->getParameter(self::ENABLED_PARAMETER)) {
            return;
        }

        // Absent without StimulusBundle, and removed by StimulusBundle itself
        // when AssetMapper is not available.
        if (!$container->hasDefinition(self::CONTROLLERS_MAP_GENERATOR)) {
            return;
        }

        $componentsDir = $container->getParameter(self::COMPONENTS_DIR_PARAMETER);
        if (!\is_string($componentsDir)) {
            return;
        }

        $resolvedComponentsDir = $this->resolvePath($container, $componentsDir);

        // The generator hands its paths to Finder::in(), which throws on a
        // missing directory, so one that does not exist yet would break every
        // controller of the application. The container is rebuilt once it is
        // created.
        $container->addResource(new FileExistenceResource($resolvedComponentsDir));
        if (!is_dir($resolvedComponentsDir)) {
            return;
        }

        $definition = $container->getDefinition(self::CONTROLLERS_MAP_GENERATOR);
        $argument = $this->controllerPathsArgument($container, $definition);

        $controllerPaths = $definition->getArgument($argument);
        if (!\is_array($controllerPaths)) {
            throw $this->incompatibleGenerator(\sprintf('its "$%s" argument is not an array', self::CONTROLLER_PATHS_ARGUMENT));
        }

        foreach ($controllerPaths as $controllerPath) {
            if (\is_string($controllerPath) && $this->resolvePath($container, $controllerPath) === $resolvedComponentsDir) {
                return;
            }
        }

        // The configured value, not the resolved one: it is resolved together
        // with the other paths, exactly as if the application had listed it.
        $controllerPaths[] = $componentsDir;
        $definition->replaceArgument($argument, $controllerPaths);
    }

    /**
     * The key under which the definition holds the controller paths: their
     * constructor position, as StimulusBundle sets it, or the parameter name.
     */
    private function controllerPathsArgument(ContainerBuilder $container, Definition $definition): int|string
    {
        $class = $container->getParameterBag()->resolveValue($definition->getClass());
        $reflection = \is_string($class) ? $container->getReflectionClass($class, false) : null;
        $constructor = $reflection?->getConstructor();
        if (null === $constructor) {
            throw $this->incompatibleGenerator(\sprintf('its class "%s" cannot be inspected', get_debug_type($class)));
        }

        foreach ($constructor->getParameters() as $parameter) {
            if (self::CONTROLLER_PATHS_ARGUMENT !== $parameter->getName()) {
                continue;
            }

            $arguments = $definition->getArguments();
            foreach (['$'.self::CONTROLLER_PATHS_ARGUMENT, $parameter->getPosition()] as $key) {
                if (\array_key_exists($key, $arguments)) {
                    return $key;
                }
            }

            break;
        }

        throw $this->incompatibleGenerator(\sprintf('"%s" has no "$%s" argument', $constructor->class, self::CONTROLLER_PATHS_ARGUMENT));
    }

    /**
     * An absolute path, comparable with another one: parameters and
     * environment variables resolved, symlinks and "../" resolved when the
     * path exists, forward slashes, no trailing slash.
     */
    private function resolvePath(ContainerBuilder $container, string $path): string
    {
        $resolved = $container->resolveEnvPlaceholders($container->getParameterBag()->resolveValue($path), true);
        if (!\is_string($resolved)) {
            return $path;
        }

        $realPath = realpath($resolved);

        return rtrim(str_replace('\\', '/', false !== $realPath ? $realPath : $resolved), '/');
    }

    private function incompatibleGenerator(string $reason): LogicException
    {
        return new LogicException(\sprintf(
            'UX SDC cannot register the component directory as a Stimulus controller path: the "%s" service of this StimulusBundle version is not supported (%s). Set "ux_sdc.stimulus.enabled: false" and add the directory to "stimulus.controller_paths" yourself.',
            self::CONTROLLERS_MAP_GENERATOR,
            $reason,
        ));
    }
}

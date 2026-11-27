<?php

/*
 * This file is part of the UX SDC Bundle
 *
 * (c) Jozef Môstka <https://github.com/tito10047/ux-sdc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tito10047\UX\Sdc\Icons\Twig;

use Tito10047\UX\Sdc\Icons\ComponentIconName;
use Twig\Environment;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Node;
use Twig\NodeVisitor\NodeVisitorInterface;

/**
 * Points bare icon names in a component's templates at that component.
 *
 * While Twig compiles a template that lives in a component directory, every
 * `ux_icon('arrow')` and `<twig:ux:icon name="arrow" />` (which the component
 * pre-lexer has already turned into `component('ux:icon', {name: 'arrow'})`)
 * gets its constant name rewritten to `sdc:<component>:arrow`. It is done once,
 * at compile time: the compiled template holds the final name, and rendering
 * pays nothing for it.
 *
 * Left alone: names with a prefix (`mdi:home`), configured aliases, names that
 * are not constants (`ux_icon(iconName)`), and every template outside the
 * components directory.
 *
 * The rewrite is lexical: an icon written in Alert.html.twig belongs to Alert,
 * even inside the content block it hands to another component.
 */
final class ComponentIconNodeVisitor implements NodeVisitorInterface
{
    /** @var array<string, string|null> template path → directory inside the components dir */
    private array $directories = [];

    private ?string $root = null;

    /**
     * @param list<string> $aliases icon aliases configured in ux_icons.aliases
     */
    public function __construct(
        private readonly string $componentsDir,
        private readonly array $aliases = [],
    ) {
    }

    public function enterNode(Node $node, Environment $env): Node
    {
        if (!$node instanceof FunctionExpression || !$node->hasNode('arguments')) {
            return $node;
        }

        $nameNode = match (strtolower($node->getAttribute('name'))) {
            'ux_icon' => $this->argument($node->getNode('arguments'), 0, 'name'),
            'component' => $this->iconComponentName($node->getNode('arguments')),
            default => null,
        };

        if (null === $nameNode) {
            return $node;
        }

        $icon = $nameNode->getAttribute('value');
        if (!\is_string($icon) || str_contains($icon, ':') || \in_array($icon, $this->aliases, true)) {
            return $node;
        }

        if (null === $directory = $this->directoryOf($node)) {
            return $node;
        }

        if (null !== $name = ComponentIconName::forDirectory($this->root(), $directory, $icon)) {
            $nameNode->setAttribute('value', $name);
        }

        return $node;
    }

    public function leaveNode(Node $node, Environment $env): ?Node
    {
        return $node;
    }

    public function getPriority(): int
    {
        return 0;
    }

    /**
     * The constant `name` of `component('ux:icon', {name: '…'})`.
     */
    private function iconComponentName(Node $arguments): ?ConstantExpression
    {
        $component = $this->argument($arguments, 0, 'name');
        if (null === $component || !\is_string($component->getAttribute('value')) || 'ux:icon' !== strtolower($component->getAttribute('value'))) {
            return null;
        }

        $props = $this->argumentNode($arguments, 1, 'props');
        if (!$props instanceof ArrayExpression) {
            return null;
        }

        foreach ($props->getKeyValuePairs() as $pair) {
            if ($pair['key'] instanceof ConstantExpression && 'name' === $pair['key']->getAttribute('value')) {
                return $pair['value'] instanceof ConstantExpression ? $pair['value'] : null;
            }
        }

        return null;
    }

    private function argument(Node $arguments, int $position, string $name): ?ConstantExpression
    {
        $node = $this->argumentNode($arguments, $position, $name);

        return $node instanceof ConstantExpression ? $node : null;
    }

    private function argumentNode(Node $arguments, int $position, string $name): ?Node
    {
        foreach ([$position, $name] as $key) {
            if ($arguments->hasNode((string) $key)) {
                return $arguments->getNode((string) $key);
            }
        }

        return null;
    }

    private function directoryOf(Node $node): ?string
    {
        $path = $node->getSourceContext()?->getPath();
        if (!$path) {
            return null;
        }

        if (\array_key_exists($path, $this->directories)) {
            return $this->directories[$path];
        }

        $directory = \dirname(realpath($path) ?: $path);
        $root = $this->root();

        $inside = '' !== $root && ($directory === $root || str_starts_with($directory, $root . DIRECTORY_SEPARATOR));

        return $this->directories[$path] = $inside ? $directory : null;
    }

    private function root(): string
    {
        return $this->root ??= rtrim(realpath($this->componentsDir) ?: '', DIRECTORY_SEPARATOR);
    }
}

<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\DeclaredDependencies;

use ReflectionExtension;
use RuntimeException;

/**
 * PHP's own surface, as the process running this test carries it.
 *
 * This exists so that "the runtime could not resolve that name" is a fact this
 * group can state and test, rather than a branch that silently drops the name.
 * `function_exists()` answers two different questions with one `false` — "no
 * such thing anywhere" and "this build lacks the extension that provides it" —
 * and the second answer is exactly the defect
 * {@see ShippedCodeRunsOnlyOnDeclaredExtensionsTest} exists to catch. Asking a
 * surface object instead keeps both answers distinguishable.
 *
 * The map is built by walking the loaded extensions and taking what each one
 * declares, rather than by listing names here. Measured on this tree: every
 * internal function `get_defined_functions()` reports is covered, so the walk
 * is the same population from the other side.
 *
 * {@see self::without()} is what makes a lean runtime testable on a full one.
 * It hides exactly the names one real extension declares — read out of that
 * extension, never typed out — so a test can ask what this control would say
 * on a PHP built without mbstring, on a PHP that has mbstring.
 */
final class PhpSurface
{
    /**
     * @param array<string, string> $functions lowercase function name => extension
     * @param array<string, string> $classes lowercase class-like name => extension
     * @param array<string, string> $constants constant name => extension
     * @param array<string, true> $loaded lowercase extension name => true
     * @param array<string, true> $hidden lowercase name this surface pretends not to carry
     */
    private function __construct(
        private readonly array $functions,
        private readonly array $classes,
        private readonly array $constants,
        private readonly array $loaded,
        private readonly array $hidden,
    ) {}

    public static function ofThisProcess(): self
    {
        $functions = [];
        $classes = [];
        $constants = [];
        $loaded = [];

        foreach (self::loadedExtensionNames() as $name) {
            $extension = new ReflectionExtension($name);
            $owner = $extension->getName();
            $loaded[strtolower($owner)] = true;

            foreach (array_keys($extension->getFunctions()) as $function) {
                $functions[strtolower((string) $function)] ??= $owner;
            }

            foreach ($extension->getClassNames() as $class) {
                $classes[strtolower($class)] ??= $owner;
            }

            foreach (array_keys($extension->getConstants()) as $constant) {
                $constants[(string) $constant] ??= $owner;
            }
        }

        return new self($functions, $classes, $constants, $loaded, []);
    }

    /**
     * The same surface, minus everything one loaded extension provides.
     *
     * The extension has to be loaded here, because what it provides is read
     * out of it. A simulation of an extension nobody can enumerate would be a
     * list maintained by hand, which is the failure this group is avoiding.
     */
    public function without(string $extension): self
    {
        if (!$this->loads($extension)) {
            throw new RuntimeException(\sprintf(
                'Cannot simulate a PHP without %s: this PHP does not load it, so there is nothing to read its surface from.',
                $extension,
            ));
        }

        $reflection = new ReflectionExtension($extension);
        $hidden = $this->hidden;

        foreach (array_keys($reflection->getFunctions()) as $function) {
            $hidden[strtolower((string) $function)] = true;
        }

        foreach ($reflection->getClassNames() as $class) {
            $hidden[strtolower($class)] = true;
        }

        foreach (array_keys($reflection->getConstants()) as $constant) {
            $hidden[strtolower((string) $constant)] = true;
        }

        $loaded = $this->loaded;
        unset($loaded[strtolower($reflection->getName())]);

        return new self($this->functions, $this->classes, $this->constants, $loaded, $hidden);
    }

    public function loads(string $extension): bool
    {
        return isset($this->loaded[strtolower($extension)]);
    }

    /**
     * @return list<string> lowercase, sorted
     */
    public function loadedExtensions(): array
    {
        $names = array_keys($this->loaded);
        sort($names);

        return $names;
    }

    public function functionExtension(string $name): ?string
    {
        return $this->hides($name) ? null : ($this->functions[strtolower($name)] ?? null);
    }

    public function classExtension(string $name): ?string
    {
        return $this->hides($name) ? null : ($this->classes[strtolower($name)] ?? null);
    }

    public function constantExtension(string $name): ?string
    {
        return $this->hides($name) ? null : ($this->constants[$name] ?? null);
    }

    /**
     * Whether anything at all in this process answers to the name — including
     * a function or class that arrived from a Composer package or from the
     * tree itself, which belongs to no extension and is nobody's `ext-`
     * declaration.
     */
    public function knowsAsFunction(string $name): bool
    {
        return !$this->hides($name) && \function_exists($name);
    }

    public function knowsAsClass(string $name): bool
    {
        return !$this->hides($name)
            && (class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name));
    }

    public function knowsAsConstant(string $name): bool
    {
        return !$this->hides($name) && \defined($name);
    }

    private function hides(string $name): bool
    {
        return isset($this->hidden[strtolower($name)]);
    }

    /**
     * @return list<string>
     */
    private static function loadedExtensionNames(): array
    {
        return [...get_loaded_extensions(), ...get_loaded_extensions(true)];
    }
}

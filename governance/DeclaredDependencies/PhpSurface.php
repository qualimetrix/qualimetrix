<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\DeclaredDependencies;

use ReflectionClass;
use ReflectionExtension;
use ReflectionFunction;
use RuntimeException;

/**
 * What PHP's own surface carries in the process running this test, and which
 * extension each name came from.
 *
 * This exists so that "no extension owns that name" is a fact a caller can act
 * on, instead of a `false` that means two things. `function_exists()` answers
 * `false` both for a name that is not a function and for a real call into an
 * extension this build lacks, and telling those apart is the whole job of
 * {@see ShippedCodeRunsOnlyOnDeclaredExtensionsTest}.
 *
 * This class does not do that telling apart. It reports what it can see --
 * which extension owns a name, and whether anything in the process answers to
 * it at all -- and {@see ShippedCodeRunsOnlyOnDeclaredExtensionsTest::judge()}
 * turns those into a verdict. Keeping the judgement out of here is deliberate:
 * the verdict depends on what `composer.json` declares, which is not a
 * property of the runtime.
 *
 * The map is built by walking the loaded extensions and taking what each one
 * declares, rather than by listing names here. Every internal function
 * `get_defined_functions()` reports is covered by that walk, so it is the same
 * population approached from the other side.
 *
 * Extension names are normalized to the spelling Composer uses for a platform
 * package: lowercase, spaces as hyphens. PHP calls one extension
 * `Zend OPcache` and Composer calls it `ext-zend-opcache`, so a raw
 * `strtolower()` would produce `ext-zend opcache` and never match a correct
 * declaration.
 */
final class PhpSurface
{
    /**
     * @param array<string, string> $functions lowercase function name => normalized extension
     * @param array<string, string> $classes lowercase class-like name => normalized extension
     * @param array<string, string> $constants constant name => normalized extension
     * @param array<string, true> $loaded normalized extension name => true
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

        // Module extensions only. A Zend extension that registers no module
        // has no `ReflectionExtension` to read, and asking for one throws and
        // takes the whole group with it. OPcache, the one that matters here,
        // registers a module and is already in this list.
        foreach (get_loaded_extensions() as $name) {
            $extension = new ReflectionExtension($name);
            $owner = self::normalize($extension->getName());
            $loaded[$owner] = true;

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
     * The same surface as a build that was compiled without one extension and
     * carries no polyfill standing in for it.
     *
     * Both halves of that sentence matter. Hiding the names makes every
     * question about them answer the way a build without the extension would,
     * including `knowsAsFunction()` -- which is right when nothing else
     * defines the name, and is why this simulates the no-polyfill case
     * specifically. A build whose vendor tree polyfills the extension answers
     * differently, and {@see ShippedCodeRunsOnlyOnDeclaredExtensionsTest}
     * refuses that case on its own evidence rather than through this.
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

        $reflection = new ReflectionExtension(self::loadedNameOf($extension));
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
        unset($loaded[self::normalize($reflection->getName())]);

        return new self($this->functions, $this->classes, $this->constants, $loaded, $hidden);
    }

    public function loads(string $extension): bool
    {
        return isset($this->loaded[self::normalize($extension)]);
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
     * Whether anything at all in this process answers to the name, including a
     * function or class that arrived from a Composer package or from the tree
     * itself. Such a name belongs to no extension and is nobody's `ext-`
     * declaration, which is a fact the caller has to act on rather than ignore:
     * a polyfill answering here is what a missing extension looks like from
     * inside a process that has one.
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

    /**
     * The file that defines a name, when the name is userland. An internal
     * name has no file, and neither has a constant, so this answers null for
     * both and the caller says so rather than guessing.
     */
    public function definingFile(string $name): ?string
    {
        if (\function_exists($name)) {
            $file = (new ReflectionFunction($name))->getFileName();

            return $file === false ? null : $file;
        }

        if (class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name)) {
            $file = (new ReflectionClass($name))->getFileName();

            return $file === false ? null : $file;
        }

        return null;
    }

    private function hides(string $name): bool
    {
        return isset($this->hidden[strtolower($name)]);
    }

    /**
     * The spelling PHP itself uses for an extension `loads()` accepts under
     * its Composer spelling. Without this, `without('zend-opcache')` passes
     * the `loads()` check and then asks reflection for a name PHP does not
     * know.
     */
    private static function loadedNameOf(string $extension): string
    {
        foreach (get_loaded_extensions() as $name) {
            if (self::normalize($name) === self::normalize($extension)) {
                return $name;
            }
        }

        throw new RuntimeException('No loaded extension answers to ' . $extension . '.');
    }

    private static function normalize(string $extension): string
    {
        return str_replace(' ', '-', strtolower($extension));
    }
}

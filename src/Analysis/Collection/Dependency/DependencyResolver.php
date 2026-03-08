<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Collection\Dependency;

use PhpParser\Node\Name;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Stmt\UseUse;

/**
 * Resolves short class names to fully qualified names (FQN).
 *
 * Uses information from 'use' statements to map short names to their
 * fully qualified equivalents. Handles:
 * - Simple use: use Foo\Bar;
 * - Aliased use: use Foo\Bar as Baz;
 * - Group use: use Foo\{Bar, Baz};
 */
final class DependencyResolver
{
    /**
     * Map of short name (or alias) => FQN.
     *
     * @var array<string, string>
     */
    private array $imports = [];

    /**
     * Current namespace.
     */
    private ?string $namespace = null;

    /**
     * Resets the resolver state.
     */
    public function reset(): void
    {
        $this->imports = [];
        $this->namespace = null;
    }

    /**
     * Sets the current namespace.
     */
    public function setNamespace(?string $namespace): void
    {
        $this->namespace = $namespace;
    }

    /**
     * Returns the current namespace.
     */
    public function getNamespace(): ?string
    {
        return $this->namespace;
    }

    /**
     * Processes a use statement, adding imports to the resolver.
     *
     * @param Use_ $use The use statement node
     */
    public function addUseStatement(Use_ $use): void
    {
        // Only process class imports (not function or const)
        if ($use->type !== Use_::TYPE_NORMAL && $use->type !== Use_::TYPE_UNKNOWN) {
            return;
        }

        foreach ($use->uses as $useUse) {
            $this->addImport($useUse);
        }
    }

    /**
     * Processes a group use statement.
     *
     * Example: use Foo\{Bar, Baz as Qux};
     */
    public function addGroupUseStatement(GroupUse $groupUse): void
    {
        // Only process class imports
        if ($groupUse->type !== Use_::TYPE_NORMAL && $groupUse->type !== Use_::TYPE_UNKNOWN) {
            return;
        }

        $prefix = $groupUse->prefix->toString();

        foreach ($groupUse->uses as $useUse) {
            // Skip if individual use is not a class import
            if ($useUse->type !== Use_::TYPE_NORMAL && $useUse->type !== Use_::TYPE_UNKNOWN) {
                continue;
            }

            $name = $useUse->name->toString();
            $alias = $useUse->alias?->toString() ?? $this->getShortName($name);
            $fqn = $prefix . '\\' . $name;

            $this->imports[$alias] = $fqn;
        }
    }

    /**
     * Resolves a name to its fully qualified form.
     *
     * @param Name $name The name to resolve
     *
     * @return string The fully qualified name (without leading backslash)
     */
    public function resolve(Name $name): string
    {
        // Already fully qualified (starts with \)
        if ($name->isFullyQualified()) {
            return $name->toString();
        }

        // Relative name (namespace\Foo). php-parser strips the `namespace` keyword
        // from Name parts, so toString() returns just "Foo" — concatenation is correct.
        if ($name->isRelative()) {
            return $this->namespace !== null
                ? $this->namespace . '\\' . $name->toString()
                : $name->toString();
        }

        // Qualified name (Foo\Bar) - check first part in imports
        if ($name->isQualified()) {
            $parts = $name->getParts();
            $firstPart = $parts[0];

            if (isset($this->imports[$firstPart])) {
                // Replace first part with imported FQN
                $parts[0] = $this->imports[$firstPart];

                return implode('\\', $parts);
            }

            // Not imported - resolve in current namespace
            return $this->resolveInCurrentNamespace($name->toString());
        }

        // Unqualified name (Foo) - check in imports
        $shortName = $name->toString();
        if (isset($this->imports[$shortName])) {
            return $this->imports[$shortName];
        }

        // Not imported - resolve in current namespace
        return $this->resolveInCurrentNamespace($shortName);
    }

    /**
     * Resolves a string class name to its FQN.
     *
     * @param string $name The class name (may be short, aliased, or FQN)
     *
     * @return string The fully qualified name
     */
    public function resolveString(string $name): string
    {
        // Already fully qualified (starts with \)
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        // Check in imports
        $parts = explode('\\', $name);
        $firstPart = $parts[0];

        if (isset($this->imports[$firstPart])) {
            if (\count($parts) === 1) {
                return $this->imports[$firstPart];
            }
            // Qualified: replace first part
            $parts[0] = $this->imports[$firstPart];

            return implode('\\', $parts);
        }

        // Not imported - resolve in current namespace
        return $this->resolveInCurrentNamespace($name);
    }

    /**
     * Returns current imports.
     *
     * @return array<string, string> alias => FQN
     */
    public function getImports(): array
    {
        return $this->imports;
    }

    /**
     * Adds a single import from a UseUse node.
     */
    private function addImport(UseUse $useUse): void
    {
        $name = $useUse->name->toString();
        $alias = $useUse->alias?->toString() ?? $this->getShortName($name);

        $this->imports[$alias] = $name;
    }

    /**
     * Gets the short name (last part) from a qualified name.
     */
    private function getShortName(string $name): string
    {
        $pos = strrpos($name, '\\');

        return $pos !== false ? substr($name, $pos + 1) : $name;
    }

    /**
     * Resolves a name in the current namespace.
     */
    private function resolveInCurrentNamespace(string $name): string
    {
        if ($this->namespace === null || $this->namespace === '') {
            return $name;
        }

        return $this->namespace . '\\' . $name;
    }
}

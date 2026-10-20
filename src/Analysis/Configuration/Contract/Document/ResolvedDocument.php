<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\MergePolicy;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;

/**
 * The configuration after every layer is recognised, shaped and merged: each
 * leaf carries the layer that won it, each merged node its contributors, and
 * the diagnostics the merge raised travel along.
 */
final readonly class ResolvedDocument
{
    /**
     * @param array<string, ResolvedValueInterface> $roots canonical root key => value; a root no layer wrote is absent
     * @param list<ConfigurationDiagnostic> $diagnostics
     */
    public function __construct(
        private NodeSchema $schema,
        private array $roots,
        private array $diagnostics = [],
    ) {}

    public static function empty(): self
    {
        return new self(NodeSchema::map([]), []);
    }

    /**
     * The node at `$path` — canonical keys for schema maps, names as written
     * for named maps, decimal indices for list items — or null when no layer
     * wrote it. A path the schema does not declare is a programming error.
     */
    public function get(string $root, string ...$path): ?ResolvedValueInterface
    {
        $this->assertDeclared([$root, ...array_values($path)]);
        $node = $this->roots[$root] ?? null;

        foreach ($path as $segment) {
            $node = match (true) {
                $node instanceof ResolvedMapInterface => $node->get($segment),
                $node instanceof ResolvedListInterface && ctype_digit($segment) => $node->items()[(int) $segment] ?? null,
                default => null,
            };
        }

        return $node;
    }

    /** @param non-empty-list<string> $path */
    private function assertDeclared(array $path): void
    {
        $schema = $this->schema;
        foreach ($path as $index => $segment) {
            if ($schema->policy === MergePolicy::ByName && $index === \count($path) - 1) {
                self::terminalNamedEntry($schema, $segment, $path);
                return;
            }
            $schema = self::declaredChild($schema, $segment, $path);
        }
    }

    /** @param non-empty-list<string> $path */
    private static function declaredChild(NodeSchema $schema, string $segment, array $path): NodeSchema
    {
        return match ($schema->policy) {
            MergePolicy::DeepMerge => $schema->map->keys->fields()[$segment] ?? self::undeclared($path),
            MergePolicy::ByName => self::descendNamedEntry($schema, $segment, $path),
            MergePolicy::Replace, MergePolicy::Accumulate => self::listElement($schema, $segment, $path),
            default => self::undeclared($path),
        };
    }

    /** @param non-empty-list<string> $path */
    private static function listElement(NodeSchema $schema, string $index, array $path): NodeSchema
    {
        if (preg_match('/^(0|[1-9][0-9]*)$/D', $index) !== 1) {
            self::undeclared($path);
        }
        return ($schema->collection ?? throw new LogicException(\sprintf('A %s node has no element schema.', $schema->policy->value)))->element;
    }

    /** @param non-empty-list<string> $path */
    private static function terminalNamedEntry(NodeSchema $schema, string $name, array $path): void
    {
        if ($schema->map->names?->isFixed() === true && !\in_array($name, $schema->map->names->fixedNames(), true)) {
            self::undeclared($path);
        }
    }

    /** @param non-empty-list<string> $path */
    private static function descendNamedEntry(NodeSchema $schema, string $name, array $path): NodeSchema
    {
        self::terminalNamedEntry($schema, $name, $path);
        $entry = $schema->map->entryForName($name);
        return $entry ?? self::undeclared($path);
    }

    /** @param non-empty-list<string> $path */
    private static function undeclared(array $path): never
    {
        throw new LogicException(\sprintf('The configuration schema does not declare the resolved path "%s".', implode('.', $path)));
    }

    /** @return array<string, ResolvedValueInterface> */
    public function roots(): array
    {
        return $this->roots;
    }

    /** @return list<ConfigurationDiagnostic> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }
}

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
        foreach ($path as $segment) {
            $schema = match ($schema->policy) {
                MergePolicy::DeepMerge => $schema->fields()[$segment] ?? self::undeclared($path),
                MergePolicy::ByName => self::namedEntry($schema, $segment, $path),
                MergePolicy::Replace, MergePolicy::Accumulate => preg_match('/^(0|[1-9][0-9]*)$/D', $segment) === 1
                    ? $schema->element()
                    : self::undeclared($path),
                default => self::undeclared($path),
            };
        }
    }

    /** @param non-empty-list<string> $path */
    private static function namedEntry(NodeSchema $schema, string $name, array $path): NodeSchema
    {
        $names = $schema->names();
        if ($names?->isFixed() === true && !\in_array($name, $names->fixedNames(), true)) {
            self::undeclared($path);
        }

        return $schema->element();
    }

    /** @param non-empty-list<string> $path */
    private static function undeclared(array $path): never
    {
        throw new LogicException(\sprintf('The configuration schema does not declare the resolved path "%s".', Provenance::display($path)));
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

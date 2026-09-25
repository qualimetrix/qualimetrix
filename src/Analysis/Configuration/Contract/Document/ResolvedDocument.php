<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document;

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
        private array $roots,
        private array $diagnostics = [],
    ) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * The node at `$path` — canonical keys for schema maps, names as written
     * for named maps, decimal indices for list items — or null when no layer
     * wrote it.
     */
    public function get(string $root, string ...$path): ?ResolvedValueInterface
    {
        $node = $this->roots[$root] ?? null;

        foreach ($path as $segment) {
            $node = match (true) {
                $node instanceof ResolvedMap => $node->get($segment),
                $node instanceof ResolvedList && ctype_digit($segment) => $node->items()[(int) $segment] ?? null,
                default => null,
            };
        }

        return $node;
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

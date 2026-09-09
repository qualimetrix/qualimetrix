<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\GraphProjection\Contract;

/**
 * Describes the graph representation requested by a delivery adapter.
 *
 * `$format` and `$direction` are typed by their owning enums
 * ({@see GraphExportFormat}, {@see GraphDirection}) so a projector's `match`
 * over `$format` is exhaustive by construction and a third value cannot slip
 * past the command's own `tryFrom()` refusal
 * (`docs/internal/plans/configuration-refusal/01-refusal-verdicts.md` §5.1).
 * A caller holding a raw string resolves it through the owning enum's own
 * `from()`/`tryFrom()` before reaching this constructor — accepting the bare
 * string here as well would be a compatibility shim CLAUDE.md's backward
 * compatibility policy rules out.
 *
 * @param array<string>|null $includeNamespaces
 * @param array<string> $excludeNamespaces
 */
final readonly class GraphProjectionRequest
{
    /**
     * @param array<string>|null $includeNamespaces
     * @param array<string> $excludeNamespaces
     */
    public function __construct(
        public GraphExportFormat $format = GraphExportFormat::Dot,
        public GraphDirection $direction = GraphDirection::LR,
        public bool $groupByNamespace = true,
        public ?array $includeNamespaces = null,
        public array $excludeNamespaces = [],
    ) {}
}

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
 * The constructor still accepts the bare string a caller outside this round's
 * command already validated elsewhere — `GraphExportFormat::from()` throws
 * `ValueError` on anything unrecognised, which is a defect in the caller, not
 * a refusal this round owns.
 *
 * @param array<string>|null $includeNamespaces
 * @param array<string> $excludeNamespaces
 */
final readonly class GraphProjectionRequest
{
    public GraphExportFormat $format;

    public GraphDirection $direction;

    /**
     * @param array<string>|null $includeNamespaces
     * @param array<string> $excludeNamespaces
     */
    public function __construct(
        GraphExportFormat|string $format = GraphExportFormat::Dot,
        GraphDirection|string $direction = GraphDirection::LR,
        public bool $groupByNamespace = true,
        public ?array $includeNamespaces = null,
        public array $excludeNamespaces = [],
    ) {
        $this->format = \is_string($format) ? GraphExportFormat::from($format) : $format;
        $this->direction = \is_string($direction) ? GraphDirection::from($direction) : $direction;
    }
}

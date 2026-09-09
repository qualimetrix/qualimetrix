<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\GraphProjection\Contract;

/**
 * The document shape `graph:export --format` renders.
 *
 * The single owner of this two-word vocabulary: {@see GraphProjectionRequest::$format}
 * and {@see \Qualimetrix\Reporting\GraphProjection\DependencyGraphProjector::project()}'s
 * dispatch both read it, so a third format cannot be added to one without the
 * other noticing (`docs/internal/plans/configuration-refusal/01-refusal-verdicts.md`
 * §5.1).
 */
enum GraphExportFormat: string
{
    case Dot = 'dot';
    case Json = 'json';
}

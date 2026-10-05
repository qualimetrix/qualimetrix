<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\Ceiling;

/** Why a recorded group cannot be compared with this invocation. */
enum IncomparabilityReason: string
{
    case AnalysisIncomplete = 'analysis-incomplete';
    case ProducerNotMeasured = 'producer-not-measured';
    case OutsideCoverage = 'outside-coverage';
    case PathsDiffer = 'paths-differ';
    case ExclusionsDiffer = 'exclusions-differ';
    case MetadataUnknown = 'metadata-unknown';
    case MagnitudeUnavailable = 'magnitude-unavailable';
}

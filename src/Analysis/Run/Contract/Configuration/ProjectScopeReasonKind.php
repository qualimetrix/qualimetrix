<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Configuration;

enum ProjectScopeReasonKind: string
{
    case ManifestIssue = 'manifest-issue';
    case NoDeclaredCode = 'no-declared-code';
    case IncompleteUniverse = 'incomplete-universe';
    case PrunedTarget = 'pruned-target';
    case MissingTarget = 'missing-target';
    case OmittedComposerRoot = 'omitted-composer-root';
    case Exclude = 'exclude';
    case Generated = 'generated';
    case ExplicitFiles = 'explicit-files';
    case UnlistableOutsidePaths = 'unlistable-outside-paths';
}

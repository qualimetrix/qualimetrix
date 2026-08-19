<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerViolation;

/**
 * Selects what to do with analysed class-like declarations that match no
 * declared layer — the gate behind `architecture.unassigned-class`.
 *
 * Deliberately a separate type from
 * {@see \Qualimetrix\Analysis\Policy\Architecture\Configuration\CoverageMode}
 * even though both spell `ignore|warn|error`. The two answer different
 * questions and must be settable independently: `coverage` also counts the
 * *ends of dependency edges*, which include classes outside `paths:` that the
 * project cannot classify at all, while this mode counts only declarations the
 * run actually analysed. Sharing one enum would invite sharing one value.
 */
enum UnassignedClassMode: string
{
    case Ignore = 'ignore';

    case Warn = 'warn';

    case Error = 'error';
}

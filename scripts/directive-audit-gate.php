<?php

declare(strict_types=1);

/**
 * `composer directives:audit` — the CI step, as a wrapper.
 *
 * The judgement lives in {@see \QmxDirectiveAudit\Gate}, not here, because a
 * script that runs on include cannot be exercised by the test suite: the
 * controls bench plants a breakage in the floor and asks which case notices,
 * and it can only ask that of code a test can call.
 *
 * Loaded by an explicit require list rather than through Composer: these
 * namespaces have no PSR-4 entry, the same way `scripts/finding-gate.php`
 * loads its own parts.
 */

require __DIR__ . '/finding-gate/Process.php';

foreach ([
    'AuditReportError',
    'MeasuredEffects',
    'AuditedVerdict',
    'VerdictReport',
    'EnumeratedSite',
    'SiteEnumeration',
    'Population',
    'Gate',
] as $part) {
    require __DIR__ . '/directive-audit/' . $part . '.php';
}

exit(\QmxDirectiveAudit\Gate::main());

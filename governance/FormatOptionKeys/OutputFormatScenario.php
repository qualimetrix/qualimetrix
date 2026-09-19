<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\FormatOptionKeys;

/**
 * One run of the product the guard is allowed to read a schema from.
 *
 * A scenario is not just a command line. The observed key set is a property of
 * `fixture x config x flags`, so what the fixture *contains* is part of the
 * scenario too: without a project-level finding there is no SARIF result
 * lacking `locations`, and without a class outside every namespace there is no
 * `<global>` group key. `$contentRequirements` names those obligations so the
 * reachability guard can fail when a fixture is quietly impoverished — a
 * poorer fixture must not be the easier one to pass.
 *
 * `$expectedExit` is per scenario rather than a threshold. The only run that
 * witnesses the documented incomplete-coverage projections exits 4 by design,
 * so a blanket "refuse anything at or above 3" would reject the sole witness
 * of five published statements.
 */
final class OutputFormatScenario
{
    /**
     * @param list<string> $args what the run adds beyond the common flags
     * @param array<string, int|bool> $expectedCoverage fields of the report's `coverage` object that must hold
     * @param list<string> $contentRequirements prose obligations on the fixture, for the reachability guard
     */
    public function __construct(
        public string $name,
        public string $directory,
        public array $args,
        public int $expectedExit,
        public array $expectedCoverage,
        public array $contentRequirements,
    ) {}

    public function path(): string
    {
        return __DIR__ . '/Fixtures/OutputFormats/' . $this->directory;
    }
}

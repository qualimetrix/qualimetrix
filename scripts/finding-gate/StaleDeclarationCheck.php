<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * Every row of a declaration form that nothing in the run consumed.
 *
 * A row is consumed only when the check that owns its form credits it, so a
 * form whose check does not exist yet fails every row it carries: a
 * declaration no check reads cannot be accepted as one. Skipped while deriving,
 * for the reason the declared delta's staleness is: a derive run absorbs what
 * it measures instead of judging it, so nothing is credited.
 */
final class StaleDeclarationCheck
{
    private bool $deriving = false;

    public function __construct(
        private readonly GateReport $report,
        private readonly Declarations $declarations,
    ) {}

    public function startDeriving(): void
    {
        $this->deriving = true;
    }

    public function checkStaleDeclarations(): void
    {
        if ($this->deriving) {
            return;
        }

        foreach ($this->declarations->records->stale() as $stale) {
            $this->report->fail(FailureClass::RECORD_STALE, $stale['scope'], $stale['detail'] . self::consumedBy('no record of the run'));
        }

        foreach ($this->declarations->values->stale() as $stale) {
            $this->report->fail(FailureClass::VALUE_STALE, $stale['scope'], $stale['detail'] . self::consumedBy('no value that moved'));
        }

        foreach ($this->declarations->fields->stale() as $stale) {
            $this->report->fail(
                FailureClass::FIELD_DECLARATION_STALE,
                $stale['scope'],
                $stale['detail'] . self::consumedBy('no difference between the two sides\' compared fields'),
            );
        }

        foreach ($this->declarations->outcomes->stale() as $stale) {
            $this->report->fail(
                FailureClass::OUTCOME_DECLARATION_STALE,
                $stale['scope'],
                $stale['detail'] . self::consumedBy('no case outcome the run observed'),
            );
        }

        foreach ($this->declarations->surfaces->stale() as $stale) {
            $this->report->fail(
                FailureClass::SURFACE_DECLARATION_STALE,
                $stale['scope'],
                $stale['detail'] . self::consumedBy('no surface the run captured'),
            );
        }

        foreach ($this->declarations->structuralMaps->stale() as $stale) {
            $this->report->fail(
                FailureClass::STRUCTURAL_MAP_STALE,
                $stale['scope'],
                $stale['detail'] . self::consumedBy('no key of any case input'),
            );
        }
    }

    private static function consumedBy(string $what): string
    {
        return \sprintf(
            ' is declared and matched %s. A declaration of a change that did not happen fails until it is corrected or'
            . ' removed.',
            $what,
        );
    }
}

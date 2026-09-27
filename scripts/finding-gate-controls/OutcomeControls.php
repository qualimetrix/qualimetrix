<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\FailureClass;

/** Corpus metadata must fail before any case can be mistaken for an empty population. */
final class OutcomeControls
{
    public static function invalidCaseMetadata(): Control
    {
        return Control::red(
            'invalid-case-metadata',
            'a case loses the required description and introduces an unknown metadata key',
            Mutation::edit(
                'finding-gate/cases/health/case.json',
                ['"description":' => '"unknownDescription":'],
                'the case metadata no longer describes a valid corpus member',
            ),
            [new Expectation(FailureClass::CORPUS_INVALID, 'corpus')],
        );
    }
}

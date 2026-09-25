<?php

declare(strict_types=1);

use QmxFindingGate\FailureClass;

// What S01b/P5 adds to the gate: case outcomes and case inputs (G8, G10). The keys are Wiring::KEYS.

return [
    'pending' => [
        FailureClass::CORPUS_INVALID => [
            'pending: S01b/P5',
            'Declared and raised nowhere: a corpus defect is a GateError with exit 3 today. S01b/P5 gives case inputs their refusal, and this class its producer.',
        ],
        FailureClass::CANDIDATE_INPUT_REFUSED => [
            'pending: S01b/P5',
            'Today the candidate refusing a case input stops the channel probe with a GateError after the comparison; S01b/P5 turns it into this class.',
        ],
        FailureClass::CASE_OUTCOME_MISMATCH => [
            'pending: S01b/P5',
            'Raised by the outcome checks S01b/P5 registers: the expected exit of an incomplete or refused case, and the output of a declared refusal.',
        ],
    ],
];

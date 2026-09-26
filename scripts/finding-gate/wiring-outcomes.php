<?php

declare(strict_types=1);

use QmxFindingGate\FailureClass;

return [
    'pending' => [
        FailureClass::CORPUS_INVALID => [
            'pending: outcomes',
            'Corpus defects currently stop the run with GateError and exit 3 instead of producing a corpus failure.',
        ],
        FailureClass::CANDIDATE_INPUT_REFUSED => [
            'pending: outcomes',
            'Candidate input refusal currently stops the channel probe with GateError instead of producing an input failure.',
        ],
        FailureClass::CASE_OUTCOME_MISMATCH => [
            'pending: outcomes',
            'Expected exits and declared refusal output have no registered case outcome check yet.',
        ],
    ],
];

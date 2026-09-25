<?php

declare(strict_types=1);

use QmxFindingGate\FailureClass;

// What S01b/P2 adds to the gate: records and value shifts on every surface (G1, G2, G3, G4, G6, G7). The keys are Wiring::KEYS.

return [
    'pending' => [
        FailureClass::RECORD_PROJECTION_MISMATCH => [
            'pending: S01b/P2',
            'Raised by the per-side record consistency check of S01b/P2 (claude-seams-09): every readable surface projects a record as that side json record carries it.',
        ],
        FailureClass::RECORD_UNDECLARED => [
            'pending: S01b/P2',
            'Raised by the record comparison S01b/P2 writes: today a record on one side only surfaces as a finding-count or surface mismatch.',
        ],
        FailureClass::TOP_ISSUES_MISMATCH => [
            'pending: S01b/P2',
            'Raised by the topIssues rule of G2, which S01b/P2 writes; today the ranking is compared as bytes.',
        ],
        FailureClass::VALUE_MISMATCH => [
            'pending: S01b/P2',
            'Raised when the measured shifts under a declared-values intent are not its derived table; S01b/P2 writes that comparison.',
        ],
    ],
];

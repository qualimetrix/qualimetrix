<?php

declare(strict_types=1);

use QmxFindingGate\FailureClass;

// What S01b/P4 adds to the gate: what a run captures and how it is normalized (G9, G11). The keys are Wiring::KEYS.

return [
    'pending' => [
        FailureClass::SURFACE_WITHDRAWAL_MISMATCH => [
            'pending: S01b/P4',
            'Raised when a withdrawn surface is still produced by the candidate or refused with other output than its declared refusal; S01b/P4 captures withdrawn surfaces.',
        ],
    ],
];

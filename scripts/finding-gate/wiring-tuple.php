<?php

declare(strict_types=1);

use QmxFindingGate\FailureClass;

// What S01b/P3 adds to the gate: the equivalence tuple per side (G5). The keys are Wiring::KEYS.

return [
    'pending' => [
        FailureClass::FIELD_VALUES_MISMATCH => [
            'pending: S01b/P3',
            'Raised when the values of an added field are not its derived table; S01b/P3 writes that comparison.',
        ],
    ],
];

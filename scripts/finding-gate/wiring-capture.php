<?php

declare(strict_types=1);

use QmxFindingGate\FailureClass;

return [
    'pending' => [
        FailureClass::SURFACE_WITHDRAWAL_MISMATCH => [
            'pending: capture',
            'Withdrawn surface capture does not yet verify that the candidate refuses the surface with its declared output.',
        ],
    ],
];

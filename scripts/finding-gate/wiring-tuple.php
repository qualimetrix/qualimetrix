<?php

declare(strict_types=1);

use QmxFindingGate\FailureClass;

return [
    'pending' => [
        FailureClass::FIELD_VALUES_MISMATCH => [
            'pending: tuple',
            'Added field measurements are not yet compared with their derived value table.',
        ],
    ],
];

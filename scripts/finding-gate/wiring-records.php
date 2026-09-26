<?php

declare(strict_types=1);

use QmxFindingGate\FailureClass;

return [
    'pending' => [
        FailureClass::RECORD_PROJECTION_MISMATCH => [
            'pending: records',
            'Record projection consistency has no registered producer yet; readable artifacts must agree with the authoritative records of the same side.',
        ],
        FailureClass::RECORD_UNDECLARED => [
            'pending: records',
            'Exact record comparison has no registered producer yet; a record present on only one side currently appears as a count or surface mismatch.',
        ],
        FailureClass::TOP_ISSUES_MISMATCH => [
            'pending: records',
            'Top issue membership and ordering have no registered record check yet; the ranking is currently compared as bytes.',
        ],
        FailureClass::VALUE_MISMATCH => [
            'pending: records',
            'Measured value shifts are not yet checked against the derived values declared by their intents.',
        ],
    ],
];

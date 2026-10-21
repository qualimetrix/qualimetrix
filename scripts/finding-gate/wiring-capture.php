<?php

declare(strict_types=1);

return [
    'classes' => ['CaptureCheck', 'NormalizationCheck'],
    'surfaceStages' => ['CaptureCheck'],
    'runChecks' => ['CaptureCheck', 'NormalizationCheck'],
];

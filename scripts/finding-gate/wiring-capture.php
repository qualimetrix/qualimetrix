<?php

declare(strict_types=1);

return [
    'classes' => ['CaptureCheck', 'SelfTestCapture', 'NormalizationCheck'],
    'selfTest' => ['SelfTestCapture::declarations', 'SelfTestCapture::derivation', 'SelfTestCapture::population', 'SelfTestCapture::outputDestination'],
    'witnesses' => ['SelfTestCapture::witnesses'],
    'surfaceStages' => ['CaptureCheck'],
    'runChecks' => ['CaptureCheck', 'NormalizationCheck'],
    'derivations' => ['CaptureCheck'],
];

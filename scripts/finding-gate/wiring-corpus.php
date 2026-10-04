<?php

declare(strict_types=1);

return [
    'controlClasses' => ['CorpusCaseControls'],
    // Longest-copy geometry is covered by
    // DuplicateCopyIdentityTest::itReportsEveryCopyOnceTheLongestCodeCoverageReachesMinLines.
    'controls' => [
        'CorpusCaseControls::configPrecedence',
        'CorpusCaseControls::thresholdRaising',
        'CorpusCaseControls::directivePlacement',
        'CorpusCaseControls::warningClockRow',
        'CorpusCaseControls::parallelFiles',
    ],
];

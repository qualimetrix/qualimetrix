<?php

declare(strict_types=1);

return [
    'classes' => ['ReportViews', 'ReportRecords', 'MetricsRecords', 'ValueCheck', 'ValueStage', 'ValueDerivation', 'RankingSchema', 'RankingOrder', 'RankingCheck', 'RecordCheck', 'RecordStage', 'RecordDerivation'],
    'caseChecks' => ['RankingCheck', 'RecordCheck'],
    'surfaceStages' => ['RecordStage', 'ValueStage'],
    'runChecks' => ['RecordCheck', 'ValueCheck'],
    'derivations' => ['RecordDerivation', 'ValueDerivation'],
];

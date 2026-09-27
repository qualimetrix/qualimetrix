<?php

declare(strict_types=1);

return [
    'classes' => ['ReportViews', 'ReportRecords', 'MetricsRecords', 'ProseRecords', 'ValueCheck', 'ValueStage', 'ValueDerivation', 'RankingSchema', 'RankingOrder', 'RankingCheck', 'RecordCheck', 'RecordStage', 'RecordDerivation', 'SelfTestRecords'],
    'controlClasses' => ['RecordControls', 'RankingControls'],
    'controls' => ['RecordControls::idleSelector', 'RankingControls::inputOrder'],
    'selfTest' => ['SelfTestRecords::publicComparison'],
    'witnesses' => ['SelfTestRecords::witnesses'],
    'caseChecks' => ['RankingCheck', 'RecordCheck'],
    'surfaceStages' => ['RecordStage', 'ValueStage'],
    'runChecks' => ['RecordCheck', 'ValueCheck'],
    'derivations' => ['RecordDerivation', 'ValueDerivation'],
];

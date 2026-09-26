<?php

declare(strict_types=1);

return [
    'classes' => ['ReportViews', 'ReportRecords', 'MetricsRecords', 'ProseRecords', 'ValueCheck', 'ValueStage', 'ValueDerivation', 'RecordCheck', 'RecordStage', 'RecordDerivation', 'SelfTestRecords'],
    'controlClasses' => ['RecordControls', 'ValueControls'],
    'controls' => ['RecordControls::idleSelector', 'ValueControls::idleIntent'],
    'selfTest' => ['SelfTestRecords::publicComparison'],
    'witnesses' => ['SelfTestRecords::witnesses'],
    'caseChecks' => ['RecordCheck'],
    'surfaceStages' => ['RecordStage', 'ValueStage'],
    'runChecks' => ['RecordCheck', 'ValueCheck'],
    'derivations' => ['RecordDerivation', 'ValueDerivation'],
];

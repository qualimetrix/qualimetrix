<?php

declare(strict_types=1);

return [
    'classes' => ['CorpusInvalid', 'CaseOutcomeCheck', 'SelfTestOutcomes'],
    'caseChecks' => ['CaseOutcomeCheck'],
    'surfaceStages' => ['CaseOutcomeCheck'],
    'runChecks' => ['CaseOutcomeCheck'],
    'derivations' => ['CaseOutcomeCheck'],
    'selfTest' => ['SelfTestOutcomes::outcomes', 'SelfTestOutcomes::outputPublication'],
    'witnesses' => ['SelfTestOutcomes::witnesses'],
    'controlClasses' => ['OutcomeControls'],
    'controls' => ['OutcomeControls::invalidCaseMetadata'],
];

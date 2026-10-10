<?php

declare(strict_types=1);

return [
    'classes' => ['CorpusInvalid', 'CaseOutcomeCheck', 'SelfTestOutcomes'],
    'caseChecks' => ['CaseOutcomeCheck'],
    'runChecks' => ['CaseOutcomeCheck'],
    'selfTest' => ['SelfTestOutcomes::outputPublication'],
    'witnesses' => ['SelfTestOutcomes::witnesses'],
];

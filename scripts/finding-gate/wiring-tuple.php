<?php

declare(strict_types=1);

return [
    'classes' => ['FieldValuesCheck'],
    'controlClasses' => ['TupleControls'],
    'controls' => ['TupleControls::publisherDrift', 'TupleControls::addedMemberWithoutIntent'],
    'runChecks' => ['FieldValuesCheck'],
    'derivations' => ['FieldValuesCheck'],
    'witnesses' => ['SelfTestFindingShape::fieldValuesWitnesses'],
];

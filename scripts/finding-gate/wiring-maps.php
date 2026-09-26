<?php

declare(strict_types=1);

return [
    'classes' => ['ComposerInputMap', 'PhpInputMap', 'YamlInputMap', 'ReportEnumerationMap', 'AggregationRenames'],
    'controlClasses' => ['MapControls'],
    'controls' => ['MapControls::enumerationDeclared', 'MapControls::enumerationWithoutRow', 'MapControls::enumerationIdle'],
];

<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\FailureClass;

/** Commands whose process outcome is part of their publication. */
final class CaptureControls
{
    public static function neutralGraphOutcome(): Control
    {
        return Control::red(
            'neutral-graph-outcome',
            'an empty directory changes graph:export from its explicit exit-1 outcome to success',
            Mutation::edit('src/Infrastructure/Console/Command/GraphExportCommand.php', ['return self::FAILURE;' => 'return self::SUCCESS;'], 'the empty graph invocation claims successful population'),
            [new Expectation(FailureClass::SURFACE_MISMATCH, 'tree|exit:graph:export'), new Expectation(FailureClass::SURFACE_MISMATCH, 'candidate / tree|graph:export')],
        );
    }
}

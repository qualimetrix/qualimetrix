<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\CommandLine;

/**
 * The finding-gate's negative controls, as a re-runnable harness.
 *
 * A gate is only evidence if it is known to go red. Each control below plants
 * one breakage in a scratch clone of this working tree, runs *that clone's own*
 * gate against the same reference, and asserts both that the gate failed and
 * which failure class it produced. Ш5 rewrites the comparator itself
 * (`Violation` -> `Finding`), so a transcript of a past run proves nothing
 * about the rewritten gate: this is a command, not a report.
 *
 * A control that goes red for an unexpected reason is a failed control: only
 * the classes a control declares count as behaving as declared, and the
 * positive control's exit code is not negotiable. A gate that fails
 * intermittently on an unmutated tree fails every control here, which is the
 * honest verdict — the gate owns `nondeterminism-undeclared` for exactly that.
 */

require __DIR__ . '/finding-gate/CommandLine.php';
require __DIR__ . '/finding-gate/FailureClass.php';

// The harness reads the step's declared surfaces through the gate's own loader
// rather than parsing declared-delta.tsv a second time, so the loader and what
// it needs come along. Measured the hard way: without these, every control
// crashed on a missing class and the whole table read "NOT AS DECLARED",
// including the positive one.
require __DIR__ . '/finding-gate/GateError.php';
require __DIR__ . '/finding-gate/Fs.php';
require __DIR__ . '/finding-gate/Tsv.php';
require __DIR__ . '/finding-gate/DeclaredDelta.php';

foreach (['Shell', 'Scratch', 'Mutation', 'Expectation', 'Control', 'Outcome', 'Controls', 'Harness'] as $part) {
    require __DIR__ . '/finding-gate-controls/' . $part . '.php';
}

exit(Harness::main(CommandLine::arguments()));

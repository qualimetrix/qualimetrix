<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * A signal asked the run to stop, raised at a decision point rather than where
 * the signal landed.
 *
 * A {@see GateError} subclass for the same reason {@see BudgetExceeded} is one:
 * {@see Process::run()} catches `GateError` to terminate the child it is
 * waiting on, and an interrupt that walked past that catch would leave a
 * `bin/qmx` running with nobody reading it.
 *
 * Never construct it to mean "the user pressed Ctrl-C" anywhere else. The exit
 * code is read from {@see Interruption::signal()}, not from catching this,
 * because {@see CaseScheduler::run()} rethrows a termination failure from its
 * `finally` and would replace it.
 */
final class Interrupted extends GateError {}

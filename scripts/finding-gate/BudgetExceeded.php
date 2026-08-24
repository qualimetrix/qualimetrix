<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The anchor search of {@see ExactDiff} would cost more than its stated budget.
 *
 * Its own class rather than a {@see GateError} so the two are told apart where
 * it matters: a `GateError` means the gate cannot run on this input, while this
 * means the input outgrew a constant somebody chose. It is a {@see GateError}
 * subclass all the same, because for a caller that only wants to know whether
 * the run can proceed the answer is the same, and because the gate's own
 * top-level handler must not have to learn a second exception type to stay
 * fail-closed.
 */
final class BudgetExceeded extends GateError {}

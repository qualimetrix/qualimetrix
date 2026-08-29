<?php

declare(strict_types=1);

namespace QmxFindingGate;

use RuntimeException;

/**
 * A condition that stops the gate itself, as opposed to a finding about the
 * trees.
 *
 * Not final: {@see BudgetExceeded} extends it so a caller that only needs to
 * know "can this run proceed" keeps one type to catch, while a caller that
 * wants to distinguish "bad input" from "an artifact outgrew a constant" can.
 */
class GateError extends RuntimeException {}

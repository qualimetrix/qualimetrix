<?php

declare(strict_types=1);

namespace QmxFindingGate;

use RuntimeException;

/** A condition that stops the gate itself, as opposed to a finding about the trees. */
final class GateError extends RuntimeException {}

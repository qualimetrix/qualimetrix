<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use RuntimeException;

/**
 * A baseline file whose envelope cannot be trusted.
 *
 * Raised when the file is missing, unreadable, invalid JSON, or speaks a
 * version this build cannot convert. There is no partial answer: nothing in
 * the file can be trusted to mean what it says, so the load fails rather than
 * guesses. Distinct from the per-entry {@see InertBaselineEntry}, which never
 * throws.
 */
final class BaselineLoadException extends RuntimeException {}

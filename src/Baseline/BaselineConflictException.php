<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

use RuntimeException;

/**
 * The baseline file changed between the moment it was read and the moment a
 * modified copy was about to replace it.
 *
 * Raised from inside {@see BaselineWriter}'s critical section, which is what
 * makes it a compare-and-swap failure rather than a guess: the check and the
 * rename happen under one lock, so a conflict reported here is a conflict
 * that actually occurred, and a write that succeeds cannot have overwritten
 * anyone.
 */
final class BaselineConflictException extends RuntimeException {}

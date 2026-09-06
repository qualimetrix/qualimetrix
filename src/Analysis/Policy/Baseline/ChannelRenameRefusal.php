<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use RuntimeException;

/**
 * A channel carry the product understood and declined to perform.
 *
 * Distinct from a file it could not read at all, which is the user's
 * environment rather than the content they authored, and which the command
 * answers with a different exit code. Everything raised here left the
 * baseline file byte-identical: the carry decides before it writes.
 */
final class ChannelRenameRefusal extends RuntimeException {}

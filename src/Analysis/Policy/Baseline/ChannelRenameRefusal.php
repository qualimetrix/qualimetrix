<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use RuntimeException;

/**
 * A channel carry the product understood and declined to perform.
 *
 * Distinct from a file it could not read at all, which is the user's
 * environment rather than the content they authored: that case is caught as
 * an unrelated exception class with its own message, never raised here. The
 * two share one exit code, matching the other four baseline commands, which
 * do not distinguish them either. Everything raised here left the baseline
 * file byte-identical: the carry decides before it writes.
 */
final class ChannelRenameRefusal extends RuntimeException {}

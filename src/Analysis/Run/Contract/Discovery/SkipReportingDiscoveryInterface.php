<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Discovery;

/**
 * Discovery that can name what it refused, beside what it yielded.
 *
 * Separate from {@see FileDiscoveryInterface} on purpose: a skip is an
 * optional statement, and folding it into `discover()` would force every
 * implementation — including the narrow ones that only ever return a list —
 * to answer a question it has no way of answering.
 *
 * `discover()` is a generator, so the list is complete only once the iteration
 * it belongs to has finished. Each `discover()` call resets it.
 */
interface SkipReportingDiscoveryInterface
{
    /** @return list<SkippedEntry> */
    public function skippedEntries(): array;
}

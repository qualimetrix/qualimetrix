<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

use Qualimetrix\Analysis\Finding\Contract\ProjectScope\RemovedCodeNamespaces;
use Qualimetrix\Core\Path\AbsolutePath;

/**
 * The namespaces declared by the code one discovery removed from the run, and
 * the budget spent to learn them.
 *
 * Without a budget a project excluding a large tree would read and tokenize
 * all of it, and list every entry in it. Reads count files, bytes count their
 * total size, entries count what the descent into removed directories
 * inspects. The survey closes when any of them is spent or when something
 * removed cannot be read: its namespaces are then unknown, which withholds
 * every claim they would have allowed, and nothing more is read for them.
 */
final class RemovedNamespaceSurvey
{
    public const int READS = 2000;

    public const int BYTES = 32 * 1024 * 1024;

    public const int ENTRIES = 20000;

    private int $readsLeft;

    private int $bytesLeft;

    private int $entriesLeft;

    private RemovedCodeNamespaces $namespaces;

    public function __construct(int $reads = self::READS, int $bytes = self::BYTES, int $entries = self::ENTRIES)
    {
        $this->readsLeft = $reads;
        $this->bytesLeft = $bytes;
        $this->entriesLeft = $entries;
        $this->namespaces = RemovedCodeNamespaces::none();
    }

    /** Whether reading on can still change the namespaces; a closed survey has none to offer. */
    public function isOpen(): bool
    {
        return $this->namespaces->isKnown();
    }

    /** Adds the namespaces of one removed PHP file; a spent budget closes the survey instead. */
    public function read(AbsolutePath|string $file): void
    {
        if (!$this->isOpen()) {
            return;
        }
        if ($this->readsLeft-- <= 0) {
            $this->close();

            return;
        }
        $declared = RemovedNamespaceScan::declaredIn($file, $this->bytesLeft);
        $this->namespaces = $declared === null ? RemovedCodeNamespaces::unknown() : $this->namespaces->merge(RemovedCodeNamespaces::known($declared));
    }

    /** Counts one entry a removed directory's descent inspects. */
    public function visit(): void
    {
        if ($this->entriesLeft-- <= 0) {
            $this->close();
        }
    }

    /** Something removed cannot be surveyed, so its namespaces are unknown. */
    public function close(): void
    {
        $this->namespaces = RemovedCodeNamespaces::unknown();
    }

    public function namespaces(): RemovedCodeNamespaces
    {
        return $this->namespaces;
    }
}

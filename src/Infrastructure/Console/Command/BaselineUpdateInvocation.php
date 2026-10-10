<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Policy\Baseline\BaselineUpdater;
use Qualimetrix\Analysis\Policy\Baseline\BaselineUpdateResult;
use Qualimetrix\Analysis\Policy\Baseline\Contract\BaselineDocument;
use Qualimetrix\Analysis\Policy\Baseline\RunRuleCoverage;

/** One selected baseline update action against a preflighted document. */
final readonly class BaselineUpdateInvocation
{
    private const string TIGHTEN = 'tighten';
    private const string ACCEPT_NEW = 'accept-new';
    private const string RECORD_EXCLUSIONS = 'record-exclusions';

    /** @param list<FindingChannel> $channels */
    private function __construct(
        public BaselineDocument $document,
        private string $action,
        private array $channels = [],
    ) {}

    public static function tighten(BaselineDocument $document): self
    {
        return new self($document, self::TIGHTEN);
    }

    /** @param list<FindingChannel> $channels */
    public static function acceptNew(BaselineDocument $document, array $channels): self
    {
        return new self($document, self::ACCEPT_NEW, $channels);
    }

    public static function recordExclusions(BaselineDocument $document): self
    {
        return new self($document, self::RECORD_EXCLUSIONS);
    }

    public function recordsExclusions(): bool
    {
        return $this->action === self::RECORD_EXCLUSIONS;
    }

    public function update(BaselineUpdater $updater, LoadedBaselineRun $measured, RunRuleCoverage $ruleCoverage): BaselineUpdateResult
    {
        $context = $measured->context;
        $gaps = $ruleCoverage->classify(array_map(static fn($entry) => $entry->identity, $measured->baseline->entries));

        return match ($this->action) {
            self::ACCEPT_NEW => $updater->acceptNew($measured->baseline, $context->findings(), $this->channels, $context->coverage, $ruleCoverage),
            self::RECORD_EXCLUSIONS => $updater->recordExclusions($measured->baseline, $context->findings(), $context->coverage, $gaps),
            default => $updater->update($measured->baseline, $context->findings(), $context->coverage, $gaps),
        };
    }
}

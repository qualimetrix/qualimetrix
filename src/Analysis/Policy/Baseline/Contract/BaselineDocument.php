<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\Contract;

use DateTimeImmutable;
use Qualimetrix\Core\FileTarget\ResolvedTarget;

/** The judged file bytes and target held between preflight and configured use. */
final readonly class BaselineDocument
{
    /** @param list<string> $scope */
    public function __construct(
        public string $path,
        public string $contentHash,
        public int $version,
        public DateTimeImmutable $generated,
        public array $scope,
        public RecordedExclusions $exclusions,
        public ResolvedTarget $target,
        private string $bytes,
    ) {}

    public function bytes(): string
    {
        return $this->bytes;
    }
}

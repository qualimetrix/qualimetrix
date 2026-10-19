<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\Contract;

use DateTimeImmutable;
use Qualimetrix\Core\FileTarget\Resolution\ResolvedTarget;

/** The judged file bytes and target held between preflight and configured use. */
final readonly class BaselineDocument
{
    /** @param list<string> $scope
     * @qmx-threshold code-smell.long-parameter-list 9 -- Authored bytes, content identity and judged target must remain one snapshot so publication cannot compare a different baseline document.
      * @qmx-threshold code-smell.constructor-overinjection 9 -- Authored bytes, content identity and judged target must remain one snapshot so publication cannot compare a different baseline document.
     */
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

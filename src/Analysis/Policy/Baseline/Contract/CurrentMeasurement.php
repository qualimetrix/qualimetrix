<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\Contract;

/** The current evidence for an explained identity, independent of its acceptance. */
final readonly class CurrentMeasurement
{
    public const string REPORTED = 'reported';
    public const string NOTHING_REPORTED = 'nothing-reported';
    public const string NOT_MEASURED = 'not-measured';
    public const string OUTSIDE_COVERAGE = 'outside-coverage';
    public const string NOT_COMPARED = 'not-compared';
    public const string LEVEL_NOT_REPORTED = 'level-not-reported';

    /**
     * @param ?list<float> $magnitudes Null for occurrence, unknown shape or an incomplete magnitude group.
     * @param list<string> $declaredLevels
     */
    public function __construct(
        public string $state,
        public ?string $shape,
        public int $count,
        public ?array $magnitudes,
        public int $membersWithoutMagnitude,
        public ?string $reason = null,
        public array $declaredLevels = [],
        public ?string $subjectLevel = null,
    ) {}
}

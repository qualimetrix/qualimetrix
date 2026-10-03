<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * Published artifacts and private evidence travel separately through workers.
 *
 * @phpstan-type ProcessCapture array{stdout:string,stderr:string,exit:int}
 * @phpstan-type RankingCapture array{ranked:ProcessCapture,physical:ProcessCapture|null}
 */
final readonly class CaptureResult
{
    /** @var array<string,string> */
    public array $artifacts;

    /** @var array<string,RankingCapture> */
    public array $rankings;

    /** @var array<string,array<string,bool>> */
    public array $baselineEligibility;

    /**
     * @param array<array-key,mixed> $artifacts
     * @param array<array-key,mixed> $rankings
     * @param array<array-key,mixed> $baselineEligibility
     */
    public function __construct(array $artifacts, array $rankings, array $baselineEligibility = [])
    {
        $publications = [];
        foreach ($artifacts as $key => $value) {
            if (!\is_string($key) || $key === '' || !\is_string($value)) {
                throw new GateError('A capture result requires named string artifacts.');
            }
            $publications[$key] = $value;
        }
        $evidence = [];
        foreach ($rankings as $key => $value) {
            if (!\is_string($key) || $key === '' || !\is_array($value) || \count($value) !== 2
                || !\array_key_exists('ranked', $value) || !\array_key_exists('physical', $value)) {
                throw new GateError('A ranking capture requires a source invocation, ranked result and physical slot.');
            }
            $evidence[$key] = [
                'ranked' => self::processCapture($value['ranked']),
                'physical' => $value['physical'] === null ? null : self::processCapture($value['physical']),
            ];
        }
        $this->artifacts = $publications;
        $this->rankings = $evidence;
        $eligibility = new BaselineEligibility();
        $eligibility->supply('candidate', $baselineEligibility);
        $this->baselineEligibility = $baselineEligibility;
    }

    public function merge(self $other): self
    {
        $artifacts = array_intersect_key($this->artifacts, $other->artifacts);
        $rankings = array_intersect_key($this->rankings, $other->rankings);
        $eligibility = array_intersect_key($this->baselineEligibility, $other->baselineEligibility);
        if ($artifacts !== [] || $rankings !== [] || $eligibility !== []) {
            throw new GateError('Capture results overlap: ' . implode(', ', [...array_keys($artifacts), ...array_keys($rankings), ...array_keys($eligibility)]));
        }
        return new self($this->artifacts + $other->artifacts, $this->rankings + $other->rankings, $this->baselineEligibility + $other->baselineEligibility);
    }

    /** @return ProcessCapture */
    private static function processCapture(mixed $value): array
    {
        if (!\is_array($value) || \count($value) !== 3
            || !\is_string($value['stdout'] ?? null) || !\is_string($value['stderr'] ?? null)
            || !\is_int($value['exit'] ?? null)) {
            throw new GateError('A process capture requires exactly string stdout, string stderr and integer exit.');
        }
        return ['stdout' => $value['stdout'], 'stderr' => $value['stderr'], 'exit' => $value['exit']];
    }
}

<?php

declare(strict_types=1);

namespace QmxFindingGate;

/** @phpstan-import-type RankingCapture from CaptureResult */
final class RankingCaptures
{
    /** @var array<string,array<string,RankingCapture>> */
    private array $sides = [];

    /** @param array<array-key,mixed> $captures */
    public function supply(string $side, array $captures): void
    {
        self::assertSide($side);
        if (\array_key_exists($side, $this->sides)) {
            throw new GateError('Internal ranking captures were supplied twice for ' . $side . '.');
        }
        $this->sides[$side] = (new CaptureResult([], $captures))->rankings;
    }

    /** @return RankingCapture */
    public function of(string $side, string $sourceInvocation): array
    {
        self::assertSide($side);
        if (!isset($this->sides[$side][$sourceInvocation])) {
            throw new GateError('Missing internal ranking capture for ' . $side . ' / ' . $sourceInvocation . '.');
        }
        return $this->sides[$side][$sourceInvocation];
    }

    private static function assertSide(string $side): void
    {
        if (!\in_array($side, ['candidate', 'reference'], true)) {
            throw new GateError('Unknown internal ranking capture side: ' . $side);
        }
    }
}

<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Population;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Core\Symbol\ClassType;
use Qualimetrix\Core\Symbol\SymbolType;

final readonly class GateInput
{
    private function __construct(
        public string $variant,
        public string $source,
        public ?MetricBag $bag = null,
        public int|float|null $boundary = null,
        public ?bool $option = null,
        public ?string $selector = null,
        public int|float|bool|null $flag = null,
        public ClassType|SymbolType|null $kind = null,
        public ?bool $bound = null,
        public int|float|null $number = null,
    ) {
        if ($source === '' || ($boundary !== null && !is_finite((float) $boundary))
            || ($number !== null && !is_finite((float) $number))) {
            throw new LogicException('A population input requires a source and finite operands.');
        }
    }

    public static function metrics(string $source, MetricBag $bag, int|float|null $boundary = null, ?bool $option = null, ?string $selector = null): self
    {
        return new self('metrics', $source, $bag, $boundary, $option, $selector);
    }

    public static function flag(string $source, int|float|bool|null $flag, ?bool $option = null): self
    {
        return new self('flag', $source, option: $option, flag: $flag);
    }

    public static function kind(string $source, ClassType|SymbolType $kind): self
    {
        return new self('kind', $source, kind: $kind);
    }

    public static function boundName(string $source, bool $matches, ?bool $option = null): self
    {
        return new self('bound-name', $source, option: $option, bound: $matches);
    }

    public static function ruleNumber(string $source, int|float $number, int|float|null $boundary = null): self
    {
        return new self('rule-number', $source, boundary: $boundary, number: $number);
    }

    public static function context(string $source, ?bool $judged): self
    {
        return new self('context', $source, bound: $judged);
    }

    public function requireVariant(string $variant, string $source): void
    {
        if ($this->variant !== $variant || $this->source !== $source) {
            throw new LogicException('Population input variant or declaring source does not match.');
        }
    }

    public function active(?bool $when): bool
    {
        if ($when === null) {
            return true;
        }
        if ($this->option === null) {
            throw new LogicException('A conditional population input requires its effective option.');
        }
        return $this->option === $when;
    }

    public function effectiveBoundary(int|float|string $boundary): int|float
    {
        if (\is_string($boundary)) {
            if ($boundary !== $this->source || $this->boundary === null) {
                throw new LogicException('Population boundary does not bind its declared option.');
            }
            return $this->boundary;
        }
        if (!is_finite((float) $boundary)) {
            throw new LogicException('Population boundary must be finite.');
        }
        return $boundary;
    }
}

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
        public MetricBag|ClassType|SymbolType|int|float|bool|null $value,
        public int|float|null $boundary = null,
        public ?bool $option = null,
        public ?string $selector = null,
    ) {
        if ($source === '' || ($boundary !== null && !is_finite((float) $boundary))
            || ($variant === 'rule-number' && is_numeric($value) && !is_finite((float) $value))) {
            throw new LogicException('A population input requires a source and finite operands.');
        }
    }

    /** @qmx-ignore code-smell.boolean-argument -- The boolean carries a measured fact or effective option value, not a requested execution mode. */
    public static function metrics(string $source, MetricBag $bag, int|float|null $boundary = null, ?bool $option = null, ?string $selector = null): self
    {
        return new self('metrics', $source, $bag, $boundary, $option, $selector);
    }

    /** @qmx-ignore code-smell.boolean-argument -- The boolean carries a measured fact or effective option value, not a requested execution mode. */
    public static function flag(string $source, int|float|bool|null $flag, ?bool $option = null): self
    {
        return new self('flag', $source, $flag, option: $option);
    }

    public static function kind(string $source, ClassType|SymbolType $kind): self
    {
        return new self('kind', $source, $kind);
    }

    /** @qmx-ignore code-smell.boolean-argument -- The boolean carries a measured fact or effective option value, not a requested execution mode. */
    public static function boundName(string $source, bool $matches, ?bool $option = null): self
    {
        return new self('bound-name', $source, $matches, option: $option);
    }

    public static function ruleNumber(string $source, int|float $number, int|float|null $boundary = null): self
    {
        return new self('rule-number', $source, $number, boundary: $boundary);
    }

    /** @qmx-ignore code-smell.boolean-argument -- The boolean carries a measured fact or effective option value, not a requested execution mode. */
    public static function context(string $source, ?bool $judged): self
    {
        return new self('context', $source, $judged);
    }

    public function metricBag(): MetricBag
    {
        return $this->value instanceof MetricBag ? $this->value : throw new LogicException('Metrics input has no bag.');
    }

    public function scalar(): int|float|bool|null
    {
        return \is_scalar($this->value) || $this->value === null ? $this->value : throw new LogicException('Population input has no scalar operand.');
    }

    public function kindValue(): ClassType|SymbolType
    {
        return $this->value instanceof ClassType || $this->value instanceof SymbolType ? $this->value : throw new LogicException('Population input has no kind operand.');
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

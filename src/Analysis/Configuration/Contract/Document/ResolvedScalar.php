<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;

/** A scalar leaf and the one layer that won it. */
final readonly class ResolvedScalar implements ResolvedValueInterface
{
    public function __construct(
        public int|float|string|bool $value,
        public Provenance $provenance,
    ) {}

    public function plain(): int|float|string|bool
    {
        return $this->value;
    }

    public function contributors(): array
    {
        return [$this->provenance];
    }

    public function refusal(string $summary): ConfigurationRefusal
    {
        return Provenance::refusalOf([$this->provenance], $summary);
    }
}

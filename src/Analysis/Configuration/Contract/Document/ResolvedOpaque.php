<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;

/**
 * A subtree the engine does not read, kept per layer with its source, for an
 * owner that folds it itself.
 */
final readonly class ResolvedOpaque implements ResolvedValueInterface
{
    /** @param non-empty-list<array{provenance: Provenance, value: mixed}> $contributions lowest precedence first */
    public function __construct(private array $contributions) {}

    /** @return non-empty-list<array{provenance: Provenance, value: mixed}> */
    public function contributions(): array
    {
        return $this->contributions;
    }

    /** @return list<mixed> each layer's value, lowest precedence first */
    public function plain(): array
    {
        return array_column($this->contributions, 'value');
    }

    public function contributors(): array
    {
        return array_column($this->contributions, 'provenance');
    }

    public function refusal(string $summary): ConfigurationRefusal
    {
        return Provenance::refusalOf($this->contributors(), $summary);
    }
}

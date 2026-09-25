<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;

/**
 * An entry of a named map whose name was written with nothing under it — `~`,
 * `{}`, or a map of nothing but `~`. The name stands, judged like any other;
 * what it means to name an entry and give it no body is its owner's to say.
 *
 * It merges as "not written": a body any layer wrote stands over it.
 */
final readonly class ResolvedBareName implements ResolvedValueInterface
{
    /** @param non-empty-list<Provenance> $writers every layer that wrote the name alone, lowest precedence first */
    public function __construct(private array $writers) {}

    /** Nothing was written under the name. */
    public function plain(): null
    {
        return null;
    }

    public function contributors(): array
    {
        return $this->writers;
    }

    public function refusal(string $summary): ConfigurationRefusal
    {
        return Provenance::refusalOf($this->writers, $summary);
    }

    public function writtenAgainBy(self $upper): self
    {
        return new self([...$this->writers, ...$upper->writers]);
    }
}

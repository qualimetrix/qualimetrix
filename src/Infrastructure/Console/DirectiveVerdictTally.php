<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveEffect;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveVerdict;

/**
 * How many directives of each verdict one audit produced, in both projections.
 *
 * One counter and two renderings of it, because the pair has to answer for the
 * same vocabulary: the tally is built over `DirectiveEffect::cases()`, while
 * both summaries used to name four keys by hand beside it. A fifth verdict
 * would have been counted and then published nowhere — in the machine summary
 * and in the printed line at once, and silently in both.
 *
 * The machine projection prints the key and the text projection prints the
 * word, which is why the two renderings are not one: `Overrun` needs a phrase
 * a reader can act on and a value a script can match on, and those are not the
 * same string.
 */
final readonly class DirectiveVerdictTally
{
    /** @param array<string, int> $counts */
    private function __construct(
        private int $total,
        private array $counts,
    ) {}

    /** @param list<DirectiveVerdict> $verdicts */
    public static function of(array $verdicts): self
    {
        $counts = [];

        foreach (DirectiveEffect::cases() as $effect) {
            $counts[$effect->value] = 0;
        }

        $effects = array_map(static fn(DirectiveVerdict $verdict): DirectiveEffect => $verdict->effect, $verdicts);

        foreach ($effects as $effect) {
            ++$counts[$effect->value];
        }

        return new self(\count($verdicts), $counts);
    }

    /** @return array<string, int> */
    public function summary(): array
    {
        return ['total' => $this->total, ...$this->counts];
    }

    public function line(): string
    {
        $tallied = [];

        foreach (DirectiveEffect::cases() as $effect) {
            $tallied[] = \sprintf('%d %s', $this->counts[$effect->value], self::label($effect));
        }

        return \sprintf('%d directive(s): %s', $this->total, implode(', ', $tallied));
    }

    /** What a verdict is called in prose, which is not its key: `Overrun` says more than one word can. */
    private static function label(DirectiveEffect $effect): string
    {
        return match ($effect) {
            DirectiveEffect::Effective => 'effective',
            DirectiveEffect::Overrun => 'applied-boundary-only',
            DirectiveEffect::Inert => 'inert',
            DirectiveEffect::Unmeasured => 'unmeasured',
        };
    }
}

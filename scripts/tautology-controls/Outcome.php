<?php

declare(strict_types=1);

namespace QmxTautologyControls;

/**
 * What one control did, and whether that is what it promised.
 *
 * A refusal is kept apart from a red and from a green on purpose. "The
 * production fragment moved, so nothing was planted" and "the breakage landed
 * and nothing noticed" are the two answers a control harness must never
 * conflate: the first says the repair's subject moved and this bench stopped
 * measuring, the second says the repair has no teeth.
 */
final readonly class Outcome
{
    /**
     * @param list<string> $cases every case the suite ran
     * @param list<string> $red the cases that went red
     * @param list<string> $missing declared cases that stayed green
     * @param list<string> $unexpected red cases the control never declared
     */
    private function __construct(
        public Control $control,
        public array $cases,
        public array $red,
        public array $missing,
        public array $unexpected,
        public ?string $refusal,
    ) {}

    /**
     * @param list<string> $cases
     * @param list<string> $red
     */
    public static function of(Control $control, array $cases, array $red): self
    {
        $declared = $control->declared();

        return new self(
            $control,
            $cases,
            $red,
            array_values(array_diff($declared, $red)),
            array_values(array_diff($red, $declared)),
            null,
        );
    }

    public static function refused(Control $control, string $because): self
    {
        return new self($control, [], [], $control->declared(), [], $because);
    }

    /**
     * Declared cases the run never carried at all — a rename nobody followed.
     *
     * @return list<string>
     */
    public function stale(): array
    {
        return array_values(array_diff($this->control->declared(), $this->cases));
    }

    public function asDeclared(): bool
    {
        if ($this->refusal !== null) {
            return false;
        }

        if ($this->control->isPositive()) {
            return $this->red === [];
        }

        if ($this->missing !== [] || $this->unexpected !== []) {
            return false;
        }

        // Reddening everything says the suite noticed damage, not which repair
        // the damage denied.
        return \count($this->red) < \count($this->cases);
    }

    public function verdict(): string
    {
        return match (true) {
            $this->refusal !== null => 'REFUSED',
            $this->asDeclared() => 'as declared',
            $this->control->isPositive() => 'NOT GREEN',
            $this->stale() !== [] => 'DECLARED A CASE THAT NEVER RAN',
            $this->missing !== [] => 'MISSED ITS CASE',
            \count($this->red) >= \count($this->cases) => 'REDDENED EVERYTHING',
            default => 'REDDENED MORE THAN DECLARED',
        };
    }
}

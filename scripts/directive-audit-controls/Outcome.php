<?php

declare(strict_types=1);

namespace QmxDirectiveAuditControls;

/**
 * What one probe did, and whether that is what it promised.
 *
 * A refusal is kept apart from a red and from a green on purpose. "The mutation
 * no longer applies" and "the mutation applied and nothing noticed" are the two
 * answers a control harness must never conflate: the first says the product
 * moved and the control stopped controlling, the second says the suite has a
 * hole. Reading them as one number is how a bench keeps printing a table long
 * after it stopped measuring.
 */
final readonly class Outcome
{
    /**
     * @param list<string> $cases every case the suite ran
     * @param list<string> $red the cases that went red
     * @param list<string> $missing declared cases that stayed green
     */
    private function __construct(
        public Probe $probe,
        public array $cases,
        public array $red,
        public array $missing,
        public ?string $refusal,
    ) {}

    /**
     * @param list<string> $cases
     * @param list<string> $red
     */
    public static function of(Probe $probe, array $cases, array $red): self
    {
        $missing = [];

        foreach ($probe->reddens as $declared) {
            if (self::matches($red, $declared)) {
                continue;
            }

            $missing[] = $declared;
        }

        return new self($probe, $cases, $red, $missing, null);
    }

    public static function refused(Probe $probe, string $because): self
    {
        return new self($probe, [], [], $probe->reddens, $because);
    }

    public function asDeclared(): bool
    {
        if ($this->refusal !== null) {
            return false;
        }

        if ($this->probe->isPositive()) {
            return $this->red === [];
        }

        // Reddening everything is not evidence: it says the suite noticed
        // damage, not which claim the damage broke.
        return $this->missing === [] && \count($this->red) < \count($this->cases);
    }

    public function verdict(): string
    {
        return match (true) {
            $this->refusal !== null => 'REFUSED',
            $this->asDeclared() => 'as declared',
            $this->probe->isPositive() => 'NOT GREEN',
            $this->missing !== [] => 'MISSED ITS CASE',
            default => 'REDDENED EVERYTHING',
        };
    }

    /** @param list<string> $red */
    private static function matches(array $red, string $declared): bool
    {
        foreach ($red as $case) {
            if (str_contains($case, $declared)) {
                return true;
            }
        }

        return false;
    }
}

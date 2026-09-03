<?php

declare(strict_types=1);

namespace QmxDirectiveAuditControls;

/**
 * What one probe did, and whether that is what it promised.
 *
 * The comparison is an equality over exact case names, not a subset over
 * substrings. Both weaker readings were measured on this bench and both hid a
 * live defect: the subset left 179 reddened cases unchecked across a third of
 * the probes, and the substring credited a declaration naming a method with any
 * one of that method's data sets. A cascade a probe genuinely causes is
 * declared through {@see Probe::alsoReddens()} rather than tolerated.
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
     * @param list<string> $unexpected red cases the probe never declared
     */
    private function __construct(
        public Probe $probe,
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
    public static function of(Probe $probe, array $cases, array $red): self
    {
        $declared = $probe->declared();

        return new self(
            $probe,
            $cases,
            $red,
            array_values(array_diff($declared, $red)),
            array_values(array_diff($red, $declared)),
            null,
        );
    }

    public static function refused(Probe $probe, string $because): self
    {
        return new self($probe, [], [], $probe->declared(), [], $because);
    }

    public function asDeclared(): bool
    {
        if ($this->refusal !== null) {
            return false;
        }

        if ($this->probe->isPositive()) {
            return $this->red === [];
        }

        if ($this->missing !== [] || $this->unexpected !== []) {
            return false;
        }

        // Reddening everything is not evidence: it says the suite noticed
        // damage, not which claim the damage broke. A blanket probe is exempt
        // from this bound and from nothing else — it still owes the equality
        // above, case by case.
        return $this->probe->blanket || \count($this->red) < \count($this->cases);
    }

    public function verdict(): string
    {
        return match (true) {
            $this->refusal !== null => 'REFUSED',
            $this->asDeclared() => 'as declared',
            $this->probe->isPositive() => 'NOT GREEN',
            $this->missing !== [] => 'MISSED ITS CASE',
            !$this->probe->blanket && \count($this->red) >= \count($this->cases) => 'REDDENED EVERYTHING',
            default => 'REDDENED MORE THAN DECLARED',
        };
    }
}

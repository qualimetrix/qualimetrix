<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Contract;

final readonly class LayerAssignment
{
    /**
     * @param list<LayerAssignmentMatch> $matches
     * @param list<string> $undecidedLayers Names of the layers whose criteria
     *                                      this run could not answer for the
     *                                      subject, in declaration order. An
     *                                      empty {@see $matches} with a
     *                                      non-empty list here is not "no layer
     *                                      claims this class" — it is "the run
     *                                      could not tell", and a reader that
     *                                      prints the first for the second is
     *                                      reporting a conclusion nothing
     *                                      reached. A non-empty {@see $matches}
     *                                      alongside it means the assignment
     *                                      stands but an earlier-declared layer
     *                                      went unanswered.
     * @param list<string> $chainStopsAt Where the subject's inheritance
     *                                   chain stopped because the run did not
     *                                   read the declaration there: the
     *                                   boundary a reader would move to answer
     *                                   {@see $undecidedLayers}. Reported only
     *                                   beside a non-empty list there.
     */
    public function __construct(
        public array $matches,
        public bool $hasLayers,
        public array $undecidedLayers = [],
        public array $chainStopsAt = [],
    ) {}
}

<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Contract;

final readonly class LayerAssignment
{
    /**
     * @param list<LayerAssignmentMatch> $matches
     * @param list<string> $undecidedLayers Names of the layers whose criteria
     *                                      this run could not answer for the
     *                                      subject and that bear on its
     *                                      assignment, in declaration order:
     *                                      all of them for an unassigned
     *                                      subject, otherwise those declared
     *                                      before the first match the run
     *                                      established, since a later one could
     *                                      not have owned it. An
     *                                      empty {@see $matches} with a
     *                                      non-empty list here is not "no layer
     *                                      claims this class" — it is "the run
     *                                      could not tell", and a reader that
     *                                      prints the first for the second is
     *                                      reporting a conclusion nothing
     *                                      reached. A non-empty {@see $matches}
     *                                      alongside it means the assignment
     *                                      stands but can change: an earlier
     *                                      layer, or the assigned layer's own
     *                                      `exclude:`, went unanswered.
     * @param list<string> $chainStopsAt Where the subject's inheritance
     *                                   chain stopped because the run did not
     *                                   read the declaration there: the
     *                                   boundary a reader would move to answer
     *                                   {@see $undecidedLayers}. Reported only
     *                                   beside a non-empty list there.
     * @param list<string> $contenders The layers that could own the subject
     *                                 once {@see $undecidedLayers} are
     *                                 answered, in declaration order; empty
     *                                 whenever that list is.
     * @param string|null $firstEstablished The first match whose own
     *                                      `exclude:` the run answered. Every
     *                                      match after it in {@see $matches}
     *                                      loses the subject whatever the
     *                                      unanswered layers answer. It is not
     *                                      the assigned layer when an
     *                                      unanswered `exclude:` stands in front
     *                                      of it, and null when no match was
     *                                      established.
     * @param list<string> $reportedShadows The matches after
     *                                      {@see $firstEstablished} that
     *                                      `architecture.potential-shadow`
     *                                      reports: neither a layer broader
     *                                      than it nor one whose own `exclude:`
     *                                      went unanswered.
     */
    public function __construct(
        public array $matches,
        public bool $hasLayers,
        public array $undecidedLayers = [],
        public array $chainStopsAt = [],
        public array $contenders = [],
        public ?string $firstEstablished = null,
        public array $reportedShadows = [],
    ) {}
}

<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use RuntimeException;

/**
 * One control: a mutation, and the failure it is declared to cause.
 *
 * `$required` must all be present. `$tolerated` are the further failures the
 * mutation cannot avoid producing, and each one is pinned to a surface exactly
 * as a required expectation is. Class-only toleration was the hole: a control
 * tolerating `surface-mismatch` accepted it on *any* case, so a side effect two
 * cases away from the mutation was absorbed instead of surfacing. A toleration
 * therefore has to name where it lands, and the constructor refuses one that
 * does not.
 *
 * Naming the surface also forces the blast radius to be stated rather than
 * gestured at. A channel rename, for instance, does four things beyond the
 * surface diff it is declared for: the renamed channel's own case no longer
 * fires what its `channels` claim says, the corpus loses a declared channel and
 * gains an undeclared one, and the container stops agreeing with the tracked
 * declaration fixture. Every one of those is a different scope.
 *
 * Everything else the gate reports is unexpected, and an unexpected failure
 * means the control did not do what it claims, even though the gate went red.
 */
final class Control
{
    /**
     * @param list<Expectation> $required
     * @param list<Expectation> $tolerated
     */
    private function __construct(
        public readonly string $id,
        public readonly string $subject,
        public readonly Mutation $mutation,
        public readonly array $required,
        public readonly array $tolerated,
        public readonly bool $expectsGreen,
    ) {
        foreach ($tolerated as $expectation) {
            if ($expectation->scopeContains === null) {
                throw new RuntimeException(\sprintf(
                    'Control "%s" tolerates %s on any surface. An unpinned toleration absorbs the side effect it'
                    . ' was never meant to cover: name the surface the mutation reaches.',
                    $id,
                    $expectation->failureClass,
                ));
            }
        }
    }

    /**
     * @param list<Expectation> $required
     * @param list<Expectation> $tolerated
     */
    public static function red(string $id, string $subject, Mutation $mutation, array $required, array $tolerated = []): self
    {
        return new self($id, $subject, $mutation, $required, $tolerated, expectsGreen: false);
    }

    public static function green(string $id, string $subject): self
    {
        return new self($id, $subject, Mutation::none(), [], [], expectsGreen: true);
    }

    /**
     * Scope-matched only. A required class arriving on some *other* surface is
     * not covered by having been required somewhere: that shortcut is how a
     * side effect in another case got absorbed. If the mutation genuinely
     * reaches a second surface, that surface gets its own toleration.
     */
    public function tolerates(string $failureClass, string $scope): bool
    {
        foreach ($this->tolerated as $expectation) {
            if ($expectation->matches($failureClass, $scope)) {
                return true;
            }
        }

        return false;
    }

    public function expectationLabel(): string
    {
        if ($this->expectsGreen) {
            return 'green, exit 0';
        }

        $label = 'exit != 0 + ' . implode(' + ', array_map(
            static fn(Expectation $expectation): string => $expectation->label(),
            $this->required,
        ));

        if ($this->tolerated === []) {
            return $label;
        }

        // Printed so a reader can judge the control's breadth from the table
        // instead of reading the declaration.
        return $label . ' (tolerating ' . implode(', ', array_map(
            static fn(Expectation $expectation): string => $expectation->label(),
            $this->tolerated,
        )) . ')';
    }
}

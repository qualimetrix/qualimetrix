<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\FailureClass;
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
 *
 * One more shape is refused, and it is refused before a single clone is made:
 * an expectation pinned to a surface the repository declares a delta for. A
 * declared surface is compared against that exact diff and never for equality,
 * so `surface-mismatch` cannot arise there and a control asking for one is
 * asserting about a comparison that no longer happens. This is the failure the
 * step that first declared a delta walked into on two controls at once, and it
 * cost a full controls run to find out — twenty minutes to learn something a
 * substring comparison knows. {@see assertNotPinnedToDeclaredDelta}.
 *
 * And a toleration that lands nowhere is a failure of the control too. Pinning it
 * to a surface made the claim precise; it did not make it true. A toleration no
 * failure matched is an unmeasured blast radius that quietly widens what the
 * control accepts the day the product starts producing that class there — the
 * same defect `map-stale` fails for, judged the same way: by what the run
 * produced. {@see Outcome::idleTolerations}.
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
     * A control that plants a breakage and declares the gate stays GREEN anyway.
     *
     * Not a second positive control: what it asserts is that a change the step
     * *declares* is absorbed by the declaration and by nothing else. The mutation
     * still has to move something — {@see Mutation} refuses one that does not —
     * so a green run here means the declared row did the absorbing. And GREEN is
     * judged with the same unconditional rule the positive control uses, plus one
     * more: the run must have compared no surface against a declared delta, or
     * "the row absorbed it" would be indistinguishable from "a blob of hashes
     * absorbed it".
     */
    public static function greenWith(string $id, string $subject, Mutation $mutation): self
    {
        return new self($id, $subject, $mutation, [], [], expectsGreen: true);
    }

    /**
     * The classes that *are* statements about a declaration, and may therefore be
     * pinned to a declared surface.
     */
    private const DECLARATION_CLASSES = [
        FailureClass::DELTA_MISMATCH,
        FailureClass::DELTA_STALE,
        FailureClass::DELTA_OVERREACH,
        FailureClass::DELTA_TOO_LARGE,
    ];

    /**
     * Refuses a control whose expectation is pinned to a surface the repository
     * declares a delta for, unless the class is a statement about a declaration.
     *
     * Exact equality, not substring containment, and that is the whole
     * calibration. A pin naming one artifact of one case (`case:coupling|format:sarif`)
     * claims that artifact and nothing else; a broader pin (`case:coupling`)
     * spans twelve formats plus the baseline file, eleven of which are still
     * compared for equality, so the control keeps its subject and the declared
     * one among them is absorbed as declaration noise by
     * {@see Outcome::isDeclarationNoise()}. Refusing the broad pin too would
     * reject controls that are correct.
     *
     * A control that replaces the declaration index is exempt: the repository's
     * declarations are not in its scratch tree at all, so its own pins are
     * judged against the declaration it plants.
     *
     * @param list<string> $declaredSurfaces
     */
    public function assertNotPinnedToDeclaredDelta(array $declaredSurfaces, bool $declarationReplaced): void
    {
        if ($declarationReplaced) {
            return;
        }

        foreach ([...$this->required, ...$this->tolerated] as $expectation) {
            if ($expectation->scopeContains === null
                || !\in_array($expectation->scopeContains, $declaredSurfaces, true)
                || \in_array($expectation->failureClass, self::DECLARATION_CLASSES, true)
            ) {
                continue;
            }

            throw new RuntimeException(\sprintf(
                'Control "%s" expects %s, and this repository declares a delta for that exact surface. It is'
                . ' compared against the declared diff and never for equality, so what the run produces there is'
                . ' a delta class and this expectation can never be met. Move the mutation to a case that'
                . ' declares nothing rather than repinning onto a delta class: a delta class asserts about the'
                . ' declaration, not about what this control is for.',
                $this->id,
                $expectation->label(),
            ));
        }
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

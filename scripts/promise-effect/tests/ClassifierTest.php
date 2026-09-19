<?php

declare(strict_types=1);

namespace Qualimetrix\PromiseEffect\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\PromiseEffect\Classifier;
use Qualimetrix\PromiseEffect\Observation;
use Qualimetrix\PromiseEffect\Verdict;

/**
 * `Classifier::composition()` under `promised_survival=survives`: a slot the
 * sibling check already found un-lost is
 * not asked whether one whole side won — a triple's `both` legitimately mixes
 * values from up to three layers, so it is asked leaf by leaf instead, against
 * `low` (L1), `middle` (L2, a triple's third layer, probed alone) and `high`
 * (L3). See `Classifier::survives()` for the exact per-leaf rule.
 */
final class ClassifierTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $scripts = \dirname(__DIR__, 3) . '/scripts';

        require_once $scripts . '/promise-effect/InProcess.php';
        require_once $scripts . '/promise-effect/Classifier.php';
    }

    #[Test]
    public function itCallsAMergeComposedAsPromisedWhenEverySlotCarriesItsTopmostWritersValue(): void
    {
        // omitted: warning/error both at the constructor default.
        // low  (L1 alone) wrote BOTH slots.
        // middle (L2 alone) touches neither slot in this case.
        // high (L3 alone) wrote ONLY `error`, leaving `warning` exactly as an
        //      omitted key does.
        // both: `warning` still carries `low`'s own value (nobody above it
        //       rewrote that slot), `error` carries `high`'s (the higher of
        //       the writers of that slot).
        $omitted = self::obs('{"warning":10,"error":20}');
        $low = self::obs('{"warning":2,"error":3}');
        $middle = self::obs('{"warning":10,"error":20}');
        $high = self::obs('{"warning":10,"error":8624}');
        $both = self::obs('{"warning":2,"error":8624}');

        $judgement = Classifier::composition($omitted, $low, $high, $both, 'survives', middle: $middle);

        self::assertSame(Verdict::COMPOSED_AS_PROMISED, $judgement->verdict);
        self::assertFalse($judgement->defect);
    }

    #[Test]
    public function itCallsAMergeFrankensteinWhenALowOnlySlotCarriesNeitherSidesValue(): void
    {
        // Same shape as the passing case above, except `both`'s `warning`
        // slot carries neither `low`'s value (2) nor the omitted default
        // (10) nor anything `high` or `middle` wrote — a stale or fabricated
        // value on a slot the promise says must survive as `low` left it.
        $omitted = self::obs('{"warning":10,"error":20}');
        $low = self::obs('{"warning":2,"error":3}');
        $middle = self::obs('{"warning":10,"error":20}');
        $high = self::obs('{"warning":10,"error":8624}');
        $both = self::obs('{"warning":8623,"error":8624}');

        $judgement = Classifier::composition($omitted, $low, $high, $both, 'survives', middle: $middle);

        self::assertSame(Verdict::FRANKENSTEIN, $judgement->verdict);
        self::assertTrue($judgement->defect, 'a broken per-slot survival promise must stay a defect');
    }

    #[Test]
    public function itCallsAMergeFrankensteinWhenAHighOnlySlotCarriesAThirdStaleValue(): void
    {
        // `error` is written by `high` alone here (`low` and `middle` leave it
        // as the omitted key does); `both` carries neither `high`'s value (99)
        // nor the omitted default (20) — a third, stale value. It must not be
        // read as `lostSibling()`'s eviction case either, which is why it is
        // NOT the omitted value: that case is already covered before this
        // check runs, and this test would be meaningless if it silently hit
        // that branch instead of the one under test.
        $omitted = self::obs('{"warning":10,"error":20}');
        $low = self::obs('{"warning":2,"error":20}');
        $middle = self::obs('{"warning":10,"error":20}');
        $high = self::obs('{"warning":10,"error":99}');
        $both = self::obs('{"warning":2,"error":77}');

        $judgement = Classifier::composition($omitted, $low, $high, $both, 'survives', middle: $middle);

        self::assertSame(Verdict::FRANKENSTEIN, $judgement->verdict);
        self::assertTrue($judgement->defect);
    }

    #[Test]
    public function itCallsAnUntouchedSlotComposedAsPromisedWhenBothCarryTheOmittedValue(): void
    {
        // Neither side writes `error` at all; `both` must still read the
        // omitted default there for the merge to be COMPOSED_AS_PROMISED.
        $omitted = self::obs('{"warning":10,"error":20}');
        $low = self::obs('{"warning":2,"error":20}');
        $middle = self::obs('{"warning":10,"error":20}');
        $high = self::obs('{"warning":10,"error":20}');
        $both = self::obs('{"warning":2,"error":20}');

        $judgement = Classifier::composition($omitted, $low, $high, $both, 'survives', middle: $middle);

        self::assertSame(Verdict::COMPOSED_AS_PROMISED, $judgement->verdict);
        self::assertFalse($judgement->defect);
    }

    #[Test]
    public function itCallsTheRealComplexityCcnTripleFixtureComposedAsPromisedWhenTheMiddleLayersValueSurvives(): void
    {
        // The actual measured triple (`complexity.ccn`
        // `callable@warning`): L1 (`low`) writes the graduated pair explicitly, L2
        // (`middle`) writes `callable.threshold`, which the cure unfolds
        // WITHIN L2's own layer into both slots, and L3 (`high`) rewrites
        // only `error`. `warning` is written by `low` AND `middle` (not by
        // `high`), so the promise says `middle`'s value must survive —
        // 8623, not `low`'s stale 4211. `error` is written by `low` and
        // `high`; `high` wins.
        $omitted = self::obs('{"warning":10,"error":20}');
        $low = self::obs('{"warning":4211,"error":4212}');
        $middle = self::obs('{"warning":8623,"error":8623}');
        $high = self::obs('{"warning":10,"error":8624}');
        $both = self::obs('{"warning":8623,"error":8624}');

        $judgement = Classifier::composition($omitted, $low, $high, $both, 'survives', middle: $middle);

        self::assertSame(Verdict::COMPOSED_AS_PROMISED, $judgement->verdict);
        self::assertFalse($judgement->defect);
    }

    #[Test]
    public function itStaysADefectWhenBothDoesNotCarryTheMiddleLayersOwnValue(): void
    {
        // Same fixture as above, except `both`'s `warning` reverted to
        // `low`'s stale 4211 instead of carrying `middle`'s 8623 — the exact
        // defect this triple exists to catch, and it must still redden once
        // the promise is checked against the real middle-layer observation,
        // not merely against `low`/`high`.
        $omitted = self::obs('{"warning":10,"error":20}');
        $low = self::obs('{"warning":4211,"error":4212}');
        $middle = self::obs('{"warning":8623,"error":8623}');
        $high = self::obs('{"warning":10,"error":8624}');
        $both = self::obs('{"warning":4211,"error":8624}');

        $judgement = Classifier::composition($omitted, $low, $high, $both, 'survives', middle: $middle);

        self::assertSame(Verdict::FRANKENSTEIN, $judgement->verdict);
        self::assertTrue($judgement->defect, 'both reverting to the low layer instead of the middle layer must stay a defect');
    }

    #[Test]
    public function itRefusesToPassAsPromisedWhenNoMiddleObservationIsSupplied(): void
    {
        // A frozen snapshot taken before this observation existed carries no
        // `middle` side for a composition-triple row. The absence must not
        // read as a pass — see `composition()`'s own guard.
        $omitted = self::obs('{"warning":10,"error":20}');
        $low = self::obs('{"warning":2,"error":3}');
        $high = self::obs('{"warning":10,"error":8624}');
        $both = self::obs('{"warning":2,"error":8624}');

        $judgement = Classifier::composition($omitted, $low, $high, $both, 'survives');

        self::assertNotSame(Verdict::COMPOSED_AS_PROMISED, $judgement->verdict);
        self::assertTrue($judgement->defect, 'a missing middle-layer observation must not default to a pass');
    }

    // --- the sensitivity gate must ask about `middle`, not just `low`/`high` ---

    #[Test]
    public function itDetectsAMiddleLayersSurvivalEvenWhenLowAndHighRenderAlike(): void
    {
        // `low` and `high` write NOTHING here — both render identical to
        // `omitted`, which is exactly the shape the plain low/high gate used
        // to read as "nothing here could say which side won" and refuse as
        // NOT_OBSERVABLE. But `middle` differs from both, and the promise
        // under test (`survives`) is precisely about whether MIDDLE'S value
        // makes it into `both` — which is observable here, and must not be
        // waved through as unmeasured.
        $omitted = self::obs('{"warning":10,"error":20}');
        $low = self::obs('{"warning":10,"error":20}');
        $middle = self::obs('{"warning":8623,"error":8623}');
        $high = self::obs('{"warning":10,"error":20}');
        $both = self::obs('{"warning":8623,"error":8623}');

        $judgement = Classifier::composition($omitted, $low, $high, $both, 'survives', middle: $middle);

        self::assertSame(Verdict::COMPOSED_AS_PROMISED, $judgement->verdict);
        self::assertFalse($judgement->defect);
    }

    #[Test]
    public function itCatchesALostMiddleLayerValueEvenWhenLowAndHighRenderAlike(): void
    {
        // Same fixture as above, except `both` reverted to the omitted
        // default instead of carrying `middle`'s value — the defect the row
        // above proves is observable must actually redden, not disappear
        // behind the low/high agreement.
        $omitted = self::obs('{"warning":10,"error":20}');
        $low = self::obs('{"warning":10,"error":20}');
        $middle = self::obs('{"warning":8623,"error":8623}');
        $high = self::obs('{"warning":10,"error":20}');
        $both = self::obs('{"warning":10,"error":20}');

        $judgement = Classifier::composition($omitted, $low, $high, $both, 'survives', middle: $middle);

        self::assertSame(Verdict::FRANKENSTEIN, $judgement->verdict);
        self::assertTrue($judgement->defect, 'a lost middle-layer value must not hide behind low/high agreement');
    }

    #[Test]
    public function itStaysNotObservableWhenAllThreeLayersRenderAlike(): void
    {
        // The genuinely unmeasurable case this gate exists to catch: low,
        // middle and high all render identically, so nothing in the probe
        // could say whose value is in `both`, whatever it reads.
        $omitted = self::obs('{"warning":10,"error":20}');
        $low = self::obs('{"warning":10,"error":20}');
        $middle = self::obs('{"warning":10,"error":20}');
        $high = self::obs('{"warning":10,"error":20}');
        $both = self::obs('{"warning":10,"error":20}');

        $judgement = Classifier::composition($omitted, $low, $high, $both, 'survives', middle: $middle);

        self::assertSame(Verdict::NOT_OBSERVABLE, $judgement->verdict);
    }

    #[Test]
    public function itAllowsAnInertMiddleLayerToLeaveTheLowHighDisputeCheckedNormally(): void
    {
        // `middle` legitimately contributes nothing to this leaf combination
        // (rendering identically to `omitted`), while `low` and `high`
        // genuinely differ from each other. The promise still reduces to a
        // meaningful low/high check here, so this must NOT be refused as
        // unobservable merely because `middle` is inert — only the
        // low-equals-high-equals-middle case above is.
        $omitted = self::obs('{"warning":10,"error":20}');
        $low = self::obs('{"warning":2,"error":3}');
        $middle = self::obs('{"warning":10,"error":20}');
        $high = self::obs('{"warning":10,"error":8624}');
        $both = self::obs('{"warning":2,"error":8624}');

        $judgement = Classifier::composition($omitted, $low, $high, $both, 'survives', middle: $middle);

        self::assertSame(Verdict::COMPOSED_AS_PROMISED, $judgement->verdict);
    }

    #[Test]
    public function itReadsNotObservableWhenTheMiddleLayerAloneWasRefused(): void
    {
        $omitted = self::obs('{"warning":10,"error":20}');
        $low = self::obs('{"warning":2,"error":3}');
        $middle = new Observation(Observation::REFUSED_FRAMED, 'refused');
        $high = self::obs('{"warning":10,"error":8624}');
        $both = self::obs('{"warning":2,"error":8624}');

        $judgement = Classifier::composition($omitted, $low, $high, $both, 'survives', middle: $middle);

        self::assertSame(Verdict::NOT_OBSERVABLE, $judgement->verdict);
    }

    private static function obs(string $json): Observation
    {
        return new Observation(Observation::ACCEPTED, $json);
    }
}

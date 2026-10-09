<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit\Population;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Finding\Contract\Population\ContextGuard;
use Qualimetrix\Analysis\Finding\Contract\Population\FlagExcludes;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;
use Qualimetrix\Analysis\Finding\Contract\Population\KeyPresent;
use Qualimetrix\Analysis\Finding\Contract\Population\KeyThreshold;
use Qualimetrix\Analysis\Finding\Contract\Population\KindIn;
use Qualimetrix\Analysis\Finding\Contract\Population\NameMatches;
use Qualimetrix\Analysis\Finding\Contract\Population\RuleValueThreshold;
use Qualimetrix\Core\Symbol\ClassType;
use Qualimetrix\Core\Symbol\SymbolType;

#[CoversClass(KeyPresent::class)]
#[CoversClass(KeyThreshold::class)]
#[CoversClass(FlagExcludes::class)]
#[CoversClass(KindIn::class)]
#[CoversClass(NameMatches::class)]
#[CoversClass(RuleValueThreshold::class)]
#[CoversClass(ContextGuard::class)]
#[CoversClass(GateInput::class)]
final class PopulationPredicatesTest extends TestCase
{
    #[Test]
    public function itDistinguishesMissingFromMeasuredZeroAndAppliesEffectiveOption(): void
    {
        $presence = new KeyPresent('value', ['metric']);
        self::assertNotNull($presence->evaluate(GateInput::metrics('value', new MetricBag())));
        self::assertNull($presence->evaluate(GateInput::metrics('value', MetricBag::fromArray(['metric' => 0]))));
        $floor = new KeyThreshold('minimum', ['metric'], '>=', 'minimum', 'zero');
        self::assertNull($floor->evaluate(GateInput::metrics('minimum', new MetricBag(), 0)));
        self::assertNotNull($floor->evaluate(GateInput::metrics('minimum', new MetricBag(), 1)));
    }

    #[Test]
    public function itSelectsOnlyTheDeclaredMetricAlternative(): void
    {
        $gate = new KeyPresent('scope', ['all' => 'cbo', 'application' => 'cbo-app']);
        self::assertNotNull($gate->evaluate(GateInput::metrics('scope', MetricBag::fromArray(['cbo' => 5]), selector: 'application')));
        self::assertNull($gate->evaluate(GateInput::metrics('scope', MetricBag::fromArray(['cbo-app' => 0]), selector: 'application')));
        $this->expectException(LogicException::class);
        $gate->evaluate(GateInput::metrics('scope', new MetricBag(), selector: 'other'));
    }

    #[Test]
    public function itBypassesInactiveFlagsBeforeReadingTheirMetric(): void
    {
        $gate = new FlagExcludes('exclude', 'flag', 1, true);
        $invalid = MetricBag::fromArray(['flag' => \INF]);
        self::assertNull($gate->evaluate(GateInput::metrics('exclude', $invalid, option: false)));
        $this->expectException(LogicException::class);
        $gate->evaluate(GateInput::metrics('exclude', $invalid, option: true));
    }

    #[Test]
    public function itUsesInverseRawPromotedOptionActivation(): void
    {
        $gate = new FlagExcludes('flag-promoted-properties', null, true, false);
        self::assertNotNull($gate->evaluate(GateInput::flag('flag-promoted-properties', true, false)));
        self::assertNull($gate->evaluate(GateInput::flag('flag-promoted-properties', true, true)));
        self::assertNull($gate->evaluate(GateInput::flag('flag-promoted-properties', null, false)));
    }

    #[Test]
    public function itKeepsExactClassKindSeparateFromLogicalSymbolKind(): void
    {
        $gate = new KindIn('php-kind', [ClassType::Class_]);
        self::assertNull($gate->evaluate(GateInput::kind('php-kind', ClassType::Class_)));
        self::assertNotNull($gate->evaluate(GateInput::kind('php-kind', ClassType::Interface_)));
        $this->expectException(LogicException::class);
        $gate->evaluate(GateInput::kind('php-kind', SymbolType::Class_));
    }

    #[Test]
    public function itRequiresTheOwningBoundNameAndRuleCount(): void
    {
        self::assertNotNull((new NameMatches('constructor'))->evaluate(GateInput::boundName('constructor', false)));
        $count = new RuleValueThreshold('maximum-cycle', '<=', 'maximum-cycle', true);
        self::assertNull($count->evaluate(GateInput::ruleNumber('maximum-cycle', 3, 0)));
        self::assertNull($count->evaluate(GateInput::ruleNumber('maximum-cycle', 3, 3)));
        self::assertNotNull($count->evaluate(GateInput::ruleNumber('maximum-cycle', 4, 3)));
    }

    #[Test]
    public function itBypassesOnlyTheOppositeDeclaredSuppressionTag(): void
    {
        $gate = new ContextGuard('suppressionPathJudged', true);
        self::assertNull($gate->evaluate(GateInput::context('suppressionNamespaceJudged', null)));
        self::assertNotNull($gate->evaluate(GateInput::context('suppressionPathJudged', false)));
        $this->expectException(LogicException::class);
        (new ContextGuard('suppressionPathJudged'))->evaluate(GateInput::context('suppressionNamespaceJudged', true));
    }

    #[Test]
    public function itRefusesInvalidCountInsteadOfCallingItHealthy(): void
    {
        $this->expectException(LogicException::class);
        (new KeyThreshold('children', ['noc'], '>', 0, nonnegative: true))->evaluate(GateInput::metrics('children', MetricBag::fromArray(['noc' => -1])));
    }
}

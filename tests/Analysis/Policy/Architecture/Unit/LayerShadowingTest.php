<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ShadowExemption;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerMatch;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerShadowing;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MatchedCriterion;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MatchedCriterionKind;

#[CoversClass(LayerShadowing::class)]
final class LayerShadowingTest extends TestCase
{
    /** @return iterable<string, array{MatchedCriterionKind}> */
    public static function nonPatternKinds(): iterable
    {
        foreach (MatchedCriterionKind::cases() as $kind) {
            if ($kind !== MatchedCriterionKind::Pattern) {
                yield $kind->value => [$kind];
            }
        }
    }

    #[Test]
    #[DataProvider('nonPatternKinds')]
    public function itTreatsNonPatternPrecedenceAsAnExemption(MatchedCriterionKind $kind): void
    {
        $pattern = self::layer('first', MatchedCriterionKind::Pattern, 'App\\Legacy\\**');
        $nonPattern = self::layer('second', $kind, 'Repository');
        self::assertSame([], LayerShadowing::reportableShadows([$pattern, $nonPattern]));
        self::assertSame([], LayerShadowing::reportableShadows([$nonPattern, $pattern]));
    }

    private static function layer(string $name, MatchedCriterionKind $kind, string $value, bool $owns = true): LayerMatch
    {
        return new LayerMatch($name, [new MatchedCriterion($kind, $value)], $owns);
    }

    #[Test]
    public function itJudgesTheFiringPairInTheRequiredPrecedenceOrder(): void
    {
        $universal = self::layer('universal', MatchedCriterionKind::Pattern, '**');
        $residue = self::layer('residue', MatchedCriterionKind::Pattern, '**', false);
        $narrow = self::layer('narrow', MatchedCriterionKind::Pattern, 'App\\Legacy\\**');
        $suffix = self::layer('suffix', MatchedCriterionKind::Suffix, 'Repository');
        self::assertSame([], LayerShadowing::verdicts([]));
        self::assertSame([], LayerShadowing::verdicts([$universal]));
        self::assertSame(ShadowExemption::NarrowerDeclaredFirst, LayerShadowing::verdicts([$narrow, $universal])[0]->exemption);
        self::assertSame(ShadowExemption::ReceivesWhatItLeaves, LayerShadowing::verdicts([$residue, $universal])[0]->exemption);
        self::assertNull(LayerShadowing::verdicts([$universal, $suffix])[0]->exemption);
        self::assertNull(LayerShadowing::verdicts([$universal, $narrow])[0]->exemption);
        self::assertSame(ShadowExemption::NonPatternPrecedence, LayerShadowing::verdicts([$suffix, $universal])[0]->exemption);
        self::assertSame(ShadowExemption::NonPatternPrecedence, LayerShadowing::verdicts([$residue, $suffix])[0]->exemption);
        $verdicts = LayerShadowing::verdicts([$narrow, $suffix, $universal]);
        self::assertSame($narrow, $verdicts[0]->earlier);
        self::assertSame($suffix, $verdicts[0]->later);
        self::assertSame($narrow, $verdicts[1]->earlier);
    }
}

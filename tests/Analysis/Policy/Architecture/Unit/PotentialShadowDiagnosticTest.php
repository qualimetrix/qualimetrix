<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MatchedCriterion;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MatchedCriterionKind;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\Observation\ShadowedClass;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\PotentialShadowDiagnostic;

#[CoversClass(PotentialShadowDiagnostic::class)]
final class PotentialShadowDiagnosticTest extends TestCase
{
    #[Test]
    public function itEmitsNothingWithoutShadowEvidence(): void
    {
        self::assertSame([], PotentialShadowDiagnostic::forShadows([]));
    }

    /**
     * The evidence map arrives in walk order, which parallel collection does
     * not fix; the report must not inherit it.
     */
    #[Test]
    public function itOrdersPairsAndSamplesIndependentlyOfWalkOrder(): void
    {
        $findings = PotentialShadowDiagnostic::forShadows([
            'repos' => ['legacy' => [$this->shadow('App\Zeta'), $this->shadow('App\Alpha')]],
            'app' => ['repos' => [$this->shadow('App\Mid')]],
        ]);

        self::assertCount(2, $findings);
        self::assertStringStartsWith('Layer "app" (pattern "App\**") shadows layer "repos"', $findings[0]->message);
        self::assertStringContainsString('for 2 class(es) including App\Alpha, App\Zeta.', $findings[1]->message);
        self::assertSame('If layer "legacy" should own these classes, declare it BEFORE "repos" (declaration order, first match wins). Otherwise tighten the patterns so the layers no longer overlap.', $findings[1]->recommendation);
        self::assertSame(LayerPolicyPreparationInterface::POTENTIAL_SHADOW_DIAGNOSTIC_NAME, $findings[0]->ruleName);
        self::assertSame(Severity::Error, $findings[0]->severity);
    }

    #[Test]
    public function itCapsTheSampleAndCountsTheRest(): void
    {
        $entries = array_map(fn(int $i): ShadowedClass => $this->shadow('App\C' . $i), range(1, 7));

        $findings = PotentialShadowDiagnostic::forShadows(['app' => ['repos' => $entries]]);

        self::assertStringContainsString('for 7 class(es) including App\C1, App\C2, App\C3, App\C4, App\C5 ...and 2 more.', $findings[0]->message);
    }

    private function shadow(string $fqn): ShadowedClass
    {
        return new ShadowedClass(
            $fqn,
            new MatchedCriterion(MatchedCriterionKind::Pattern, 'App\**'),
            new MatchedCriterion(MatchedCriterionKind::Pattern, 'App\Repository\**'),
        );
    }
}

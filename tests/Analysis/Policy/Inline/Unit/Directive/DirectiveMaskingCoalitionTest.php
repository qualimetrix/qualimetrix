<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Unit\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Inline\Directive\Audit\AuthoredDirectiveGroup;
use Qualimetrix\Analysis\Policy\Inline\Directive\Audit\DirectiveMaskingCoalition;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Which of several neighbours hides a directive, asked of the coalition
 * directly.
 *
 * **These cases exist because the tree cannot supply them.** `src/` holds no
 * file carrying two `@qmx-threshold` of one rule over one subject, and
 * directives in different files never share a subject, so `hiddenBy()` — the
 * branch that separates one candidate masker from its fellows — is never
 * executed by a self-analysis run. A byte-for-byte comparison of
 * `qmx directives src/` is therefore green under any breakage of it.
 *
 * Every case here has **two or more maskers** and asserts the neighbour that
 * was measured to hide, never the one that comes first. Naming by position is
 * exactly what a broken candidate/fellow separation degrades into, so an
 * assertion on the first masker's line would pass either way.
 */
#[CoversClass(DirectiveMaskingCoalition::class)]
final class DirectiveMaskingCoalitionTest extends TestCase
{
    private const string FILE = 'src/Sample.php';

    private const string RULE = 'coupling.cbo';

    /**
     * The measured value a scripted run judges by default, and the boundary in
     * force when no directive raised one.
     */
    private const int VALUE = 25;

    private const int DEFAULT_WARNING = 20;

    /**
     * Two neighbours cover the subject; only the second raises the boundary
     * far enough to silence the finding on its own, and it is written last.
     */
    #[Test]
    public function itNamesTheMeasuredMaskerRatherThanTheFirstOfTwo(): void
    {
        $groups = [
            self::group(5, warning: 21, scope: ControlScope::Class_),
            self::group(9, warning: 30),
            self::group(13, warning: 30, scope: ControlScope::Hook),
        ];

        $maskedBy = self::coalition($groups)->maskedBy($groups, 1, null);

        self::assertSame(13, $maskedBy?->line);
        self::assertSame(self::FILE, $maskedBy->site()->file->value());
        self::assertSame('threshold', $maskedBy->site()->form);
    }

    /**
     * The same claim with three neighbours, only the last of which hides:
     * a separation that drops one fellow instead of the candidate would still
     * answer the two-masker case by luck of ordering.
     */
    #[Test]
    public function itNamesTheMeasuredMaskerRatherThanTheFirstOfThree(): void
    {
        $groups = [
            self::group(5, warning: 21, scope: ControlScope::Class_),
            self::group(7, warning: 22, scope: ControlScope::Property),
            self::group(9, warning: 30),
            self::group(13, warning: 30, scope: ControlScope::Hook),
        ];

        $maskedBy = self::coalition($groups)->maskedBy($groups, 2, null);

        self::assertSame(13, $maskedBy?->line);
    }

    /**
     * Two neighbours, neither of which hides it alone: put back on its own,
     * each still lets the directive's removal change the boundary. The hiding
     * is joint, there is no one directive to name, and the report says so by
     * naming the first — the one case where the answer is positional.
     */
    #[Test]
    public function itNamesTheFirstOfTwoWhenNeitherHidesItAlone(): void
    {
        $groups = [
            self::group(5, warning: 22, scope: ControlScope::Class_),
            self::group(9, warning: 30),
            self::group(13, warning: 22, scope: ControlScope::Hook),
        ];

        self::assertSame(5, self::coalition($groups)->maskedBy($groups, 1, null)?->line);
    }

    /**
     * Overlap is not masking. The neighbours cover the subject, but the rule
     * reports nothing on it at any boundary, so removing the whole coalition
     * changes nothing and no directive is hiding another.
     */
    #[Test]
    public function itAnswersNoMaskerWhenTheCoalitionChangesNothing(): void
    {
        $groups = [
            self::group(5, warning: 30, scope: ControlScope::Class_),
            self::group(9, warning: 30),
            self::group(13, warning: 30, scope: ControlScope::Hook),
        ];

        self::assertNull(self::coalition($groups, value: 5)->maskedBy($groups, 1, null));
    }

    /**
     * The control for the cases above: with two neighbours that really do hide
     * it, the answer is not "none". Without it, an implementation returning
     * null unconditionally would satisfy the fallback case.
     */
    #[Test]
    public function itFindsAMaskerAtAllWhenTwoNeighboursCoverTheSubject(): void
    {
        $groups = [
            self::group(5, warning: 30, scope: ControlScope::Class_),
            self::group(9, warning: 30),
            self::group(13, warning: 30, scope: ControlScope::Hook),
        ];

        self::assertNotNull(self::coalition($groups)->maskedBy($groups, 1, null));
    }

    /**
     * A directive whose neighbours address another rule has no coalition, and
     * the overlap test must say so before any counterfactual is run.
     */
    #[Test]
    public function itHasNoCoalitionWhenTheNeighboursAddressAnotherRule(): void
    {
        $groups = [
            self::group(5, warning: 30, scope: ControlScope::Class_, rule: 'complexity.cyclomatic'),
            self::group(9, warning: 30),
            self::group(13, warning: 30, scope: ControlScope::Hook, rule: 'complexity.cyclomatic'),
        ];

        self::assertNull(self::coalition($groups)->maskedBy($groups, 1, null));
    }

    /**
     * The counterfactual the coalition is given: the same measured value judged
     * against the highest boundary any *surviving* directive of the rule raised.
     *
     * A scripted world rather than the real rule layer, because what is under
     * test is which runs the coalition asks for, not what a rule does with a
     * boundary. Specificity is deliberately not modelled — the coalition never
     * looks at it, and a double that did would be asserting the audit's job.
     *
     * @param list<AuthoredDirectiveGroup> $all
     */
    private static function coalition(array $all, int $value = self::VALUE): DirectiveMaskingCoalition
    {
        return new DirectiveMaskingCoalition(
            /**
             * @param list<AuthoredDirectiveGroup> $removed
             *
             * @return list<Finding>
             */
            static function (array $removed, ?string $restrictToProducer) use ($all, $value): array {
                self::assertNull($restrictToProducer, 'these cases sweep the full rule layer');

                $boundary = self::DEFAULT_WARNING;

                foreach ($all as $group) {
                    if (\in_array($group, $removed, true) || $group->rule !== self::RULE) {
                        continue;
                    }

                    $boundary = max($boundary, $group->bindings[0]->warning);
                }

                return $value > $boundary ? [self::finding($value, $boundary)] : [];
            },
        );
    }

    private static function finding(int|float $value, int|float $boundary): Finding
    {
        $subject = self::subject();

        return new Finding(
            location: new Location(RelativePath::fromString(self::FILE), 1),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: self::RULE,
            code: 'CBO',
            message: 'coupling above the boundary',
            severity: Severity::Warning,
            metricValue: $value,
            threshold: $boundary,
        );
    }

    private static function group(
        int $line,
        int|float $warning,
        ControlScope $scope = ControlScope::Callable,
        string $rule = self::RULE,
    ): AuthoredDirectiveGroup {
        return AuthoredDirectiveGroup::of(self::FILE, $line, $rule, [
            new ThresholdOverride($rule, $warning, $warning + 10, $line, self::subject(), $scope),
        ]);
    }

    /**
     * One subject for every directive here: a coalition is directives covering
     * the same subject, so a case that varied it would be testing the overlap
     * test's negative branch instead.
     */
    private static function subject(): MetricSubject
    {
        return MetricSubject::declaration(DeclarationPath::of(
            SymbolPath::forMethod('App', 'Widget', 'render'),
            RelativePath::fromString(self::FILE),
            DeclarationOrdinal::fromRank(0),
        ));
    }
}

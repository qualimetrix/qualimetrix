<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Health\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricDefaults;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricExpression;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthDecompositionCatalog;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\MetricHintCatalog;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Health\Support\FormulaKneeReader;

/**
 * What the report says a health score was made of, against what makes it.
 *
 * Both halves of the report's claim are read from the formulas here rather
 * than restated: which inputs a score has, and the number each input is asked
 * to stay on the good side of. Both had drifted silently — the advertised MI
 * floor said 65 where the formula stopped penalising at 5, and a project
 * coupling score printed the namespace formula's Ce inputs — because the only
 * thing tying catalog to formula was that someone had typed them to match.
 */
#[CoversClass(HealthDecompositionCatalog::class)]
#[CoversClass(MetricHintCatalog::class)]
final class HealthDecompositionAgreesWithFormulasTest extends TestCase
{
    /**
     * Inputs whose formula term applies no knee: a plain product or a square
     * root penalises from the first unit and names no threshold. Their
     * advertised value is editorial, and the assertion for them is the
     * absence itself — give one of these a knee and this list turns red.
     *
     * @var list<string>
     */
    private const array WITHOUT_FORMULA_KNEE = [
        'cohesion.tcc',
        'cohesion.tcc.avg',
        'coupling.distance',
        'coupling.distance.avg',
        'design.type-coverage.all',
    ];

    /**
     * Keys whose knee is not the same at every level.
     *
     * One advertised value per metric key can only describe one level, and the
     * level it describes is `project`: the decomposition lines that carry an
     * advertised value are built once, from project metrics, by
     * HealthSummaryBuilder::buildDecomposition(). The class and namespace
     * lists are shipped to the HTML report, which renders a value and a range
     * hint but no target. Listed rather than skipped so that a level moving
     * onto or off the project's number is visible in the diff.
     *
     * @var array<string, array<string, float>> key => level => knee
     */
    private const array KNEE_DIFFERS_BY_LEVEL = [
        'complexity.ccn.max' => ['class' => 10.0, 'namespace' => 20.0, 'project' => 20.0],
        'coupling.ce' => ['class' => 5.0, 'namespace' => 50.0],
        'maintainability.mi.min' => ['class' => 65.0, 'namespace' => 5.0, 'project' => 5.0],
    ];

    private HealthDecompositionCatalog $catalog;
    private MetricHintCatalog $hints;
    private FormulaKneeReader $knees;
    private ComputedMetricExpression $expression;

    protected function setUp(): void
    {
        $this->catalog = new HealthDecompositionCatalog();
        $this->hints = new MetricHintCatalog();
        $this->knees = new FormulaKneeReader();
        $this->expression = new ComputedMetricExpression();
    }

    #[Test]
    public function itShowsOnlyInputsTheFormulaAtThatLevelReads(): void
    {
        $checked = 0;

        foreach (self::levels() as [$dimension, $level, $formula]) {
            $formulaKeys = $this->expression->keysOf($formula);

            foreach ($this->catalog->inputsFor($dimension, $level) as $input) {
                foreach ($input['sources'] as $source) {
                    self::assertContains(
                        $source,
                        $formulaKeys,
                        \sprintf(
                            '%s at %s shows "%s", sourced from "%s", which its formula does not read',
                            $dimension,
                            $level->value,
                            $input['key'],
                            $source,
                        ),
                    );
                    $checked++;
                }
            }
        }

        self::assertGreaterThan(20, $checked, 'the enumeration collapsed; it is proving nothing');
    }

    #[Test]
    public function itAdvertisesTheThresholdTheFormulaApplies(): void
    {
        $checked = 0;

        foreach (self::levels() as [$dimension, $level, $formula]) {
            $inputs = $this->catalog->inputsFor($dimension, $level);

            foreach ($inputs as $input) {
                $knee = $this->knees->kneeFor($formula, $input['sources']);
                $key = $input['key'];

                if (\in_array($key, self::WITHOUT_FORMULA_KNEE, true)) {
                    self::assertNull($knee, \sprintf('"%s" is declared knee-less but its formula now applies one', $key));

                    continue;
                }

                self::assertNotNull($knee, \sprintf('%s at %s: no knee found for "%s"', $dimension, $level->value, $key));
                self::assertSame(
                    $input['direction'],
                    $knee['direction'],
                    \sprintf('"%s" is shown as %s-is-better; its formula says the opposite', $key, $input['direction']),
                );

                if (self::sharesItsKnee($input, $inputs)) {
                    // The knee sits on a weighted blend of several signals —
                    // `ce_packages * 3 + sqrt(ce) * 0.5 - 5` — so it is a
                    // threshold on the blend and no single input can claim it.
                    // What such a line advertises is editorial, and the formula
                    // contradicts nothing; the assertion above still fixes that
                    // the term exists and which way it points.
                    $checked++;

                    continue;
                }

                $declared = self::KNEE_DIFFERS_BY_LEVEL[$key][$level->value] ?? null;

                if ($declared !== null) {
                    self::assertSame($declared, $knee['knee'], \sprintf('"%s" at %s no longer has the declared knee', $key, $level->value));
                }

                if ($level !== SymbolLevel::Project) {
                    // Only the project level's lines carry an advertised
                    // target — see KNEE_DIFFERS_BY_LEVEL. One advertised value
                    // per metric key could not describe two levels anyway:
                    // `complexity.ccn.max` is penalised past 10 for a class and
                    // past 20 for the project.
                    $checked++;

                    continue;
                }

                // The hint's own direction is hand-typed as well, and it picks
                // which of the good/bad explanations is printed beside the
                // number this line advertises.
                self::assertSame(
                    $knee['direction'] . '_is_better',
                    $this->hints->getDirection($key),
                    \sprintf('"%s" is hinted as %s; its formula points the other way', $key, (string) $this->hints->getDirection($key)),
                );

                self::assertSame(
                    $knee['knee'],
                    $this->advertisedThreshold($key),
                    \sprintf(
                        '"%s" is advertised as "%s" while its %s formula stops at %s',
                        $key,
                        (string) $this->hints->getGoodValue($key),
                        $level->value,
                        (string) $knee['knee'],
                    ),
                );
                $checked++;
            }
        }

        self::assertGreaterThan(20, $checked, 'the enumeration collapsed; it is proving nothing');
    }

    #[Test]
    public function itKeepsEveryDeclaredDivergenceReal(): void
    {
        foreach (self::KNEE_DIFFERS_BY_LEVEL as $key => $byLevel) {
            self::assertGreaterThan(
                1,
                \count(array_unique($byLevel)),
                \sprintf('"%s" is declared level-divergent but every level now applies the same knee', $key),
            );
        }
    }

    /**
     * Whether another input at this level is backed by the very same term.
     *
     * Two lines with identical `sources` are two views of one blended penalty;
     * a denominator shared with nothing else is not that.
     *
     * @param array{key: string, sources: list<string>, label: string, direction: string} $input
     * @param list<array{key: string, sources: list<string>, label: string, direction: string}> $inputs
     */
    private static function sharesItsKnee(array $input, array $inputs): bool
    {
        $sharing = array_filter($inputs, static fn(array $other): bool => $other['sources'] === $input['sources']);

        return \count($sharing) > 1;
    }

    /**
     * The number the report tells a reader to stay on the good side of.
     *
     * Parsed here rather than by the product's own reader, which is private to
     * MetricHintCatalog. The two must accept the same spellings: a good value
     * this cannot read returns null and fails the comparison rather than
     * skipping it, so a spelling drift shows up as a failure, not as silence.
     */
    private function advertisedThreshold(string $key): ?float
    {
        $goodValue = $this->hints->getGoodValue($key);

        if ($goodValue === null) {
            return null;
        }

        return match (true) {
            str_starts_with($goodValue, 'below ') => (float) substr($goodValue, 6),
            str_starts_with($goodValue, 'above ') => (float) substr($goodValue, 6),
            str_contains($goodValue, ' or less') => (float) explode(' or less', $goodValue)[0],
            default => null,
        };
    }

    /**
     * Every (dimension, level) a health score is reported at, with the formula
     * that produces it — the project fallback resolved the way the definition
     * resolves it.
     *
     * @return list<array{0: string, 1: SymbolLevel, 2: string}>
     */
    private static function levels(): array
    {
        $levels = [];

        foreach (ComputedMetricDefaults::getDefaults() as $dimension => $definition) {
            foreach ($definition->levels as $level) {
                $formula = $definition->getFormulaForLevel($level);

                if ($formula !== null) {
                    $levels[] = [$dimension, $level, $formula];
                }
            }
        }

        return $levels;
    }
}

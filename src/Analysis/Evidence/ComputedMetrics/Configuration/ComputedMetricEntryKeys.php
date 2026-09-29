<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedListInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\Shorthand;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\HealthDimension;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * The declared vocabulary of one `computed_metrics:` entry: its keys, the
 * form of each value, and the `threshold` shorthand.
 *
 * The metric name itself is not a dictionary key: a user name is open,
 * described by a grammar ({@see \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition}),
 * judged by {@see ComputedMetricsSection} in the layer that wrote it. The
 * `health.*` half of the names is closed on six and is declared here.
 */
final class ComputedMetricEntryKeys
{
    public const string DESCRIPTION = 'description';
    public const string ENABLED = 'enabled';
    public const string ERROR = 'error';
    public const string FORMULA = 'formula';
    public const string FORMULAS = 'formulas';
    public const string INVERTED = 'inverted';
    public const string LEVELS = 'levels';
    public const string THRESHOLD = 'threshold';
    public const string WARNING = 'warning';

    /**
     * The levels this capability reports at: the keys `formulas:` accepts,
     * the levels `formula:` writes, and the words `levels:` may name.
     *
     * @var list<SymbolLevel>
     */
    public const array REPORTING_LEVELS = [SymbolLevel::Class_, SymbolLevel::Namespace_, SymbolLevel::Project];

    /**
     * `formula` is the formula of every level and `formulas.<level>` refines
     * one level beside it, whichever layers wrote them; `threshold` is
     * `warning` plus `error`, expanded in the layer that wrote it.
     */
    public static function entrySchema(): NodeSchema
    {
        $formulas = [];
        foreach (self::REPORTING_LEVELS as $level) {
            $formulas[$level->value] = NodeSchema::scalar(ScalarForm::String)->judgedInEachLayer(ComputedMetricValueForm::ofFormula(...));
        }

        return NodeSchema::map(
            [
                self::DESCRIPTION => NodeSchema::scalar(ScalarForm::String),
                self::ENABLED => NodeSchema::scalar(ScalarForm::Boolean),
                self::ERROR => NodeSchema::scalar(ScalarForm::Number),
                self::FORMULA => NodeSchema::scalar(ScalarForm::String)->judgedInEachLayer(ComputedMetricValueForm::ofFormula(...)),
                self::FORMULAS => NodeSchema::map($formulas),
                self::INVERTED => NodeSchema::scalar(ScalarForm::Boolean),
                self::LEVELS => NodeSchema::stringList()->judgedInEachLayer(self::ofLevels(...)),
                self::WARNING => NodeSchema::scalar(ScalarForm::Number),
            ],
            Shorthand::spreading(self::THRESHOLD, [self::WARNING, self::ERROR]),
        );
    }

    /** @param list<string> $path */
    private static function ofLevels(ResolvedValueInterface $value, array $path): void
    {
        \assert($value instanceof ResolvedListInterface);
        ComputedMetricValueForm::levels($value, $path[1], self::REPORTING_LEVELS);
    }

    /**
     * The six short `health.*` names a reserved-prefix entry may name, read
     * from {@see HealthDimension::cases()} rather than kept as a second
     * literal list that could drift from it.
     *
     * @return list<string>
     */
    public static function acceptedHealthNames(): array
    {
        $names = array_map(
            static fn(HealthDimension $dimension): string => $dimension->shortName(),
            HealthDimension::cases(),
        );
        sort($names);

        return $names;
    }
}

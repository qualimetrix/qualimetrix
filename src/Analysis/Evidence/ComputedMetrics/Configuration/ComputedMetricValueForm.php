<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedListInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Evaluation\ComputedMetricExpression;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Symfony\Component\ExpressionLanguage\SyntaxError;

/** Context-free level and expression forms, shared by authored and resolved values. */
final class ComputedMetricValueForm
{
    /**
     * @param list<SymbolLevel> $reportingLevels
     *
     * @return list<SymbolLevel>
     */
    public static function levels(ResolvedListInterface $value, string $name, array $reportingLevels): array
    {
        $levels = [];
        foreach ($value->items() as $item) {
            $level = SymbolLevel::tryFrom((string) $item->plain());
            if ($level === null || !\in_array($level, $reportingLevels, true)) {
                throw new LogicException('The configuration engine admits only a reporting level in a computed metric\'s levels.');
            }
            $levels[] = $level;
        }

        if (ComputedMetricDefinition::hasDuplicateLevel($levels)) {
            $value->refuse(ComputedMetricRefusalWording::duplicateLevel($name));
        }

        return $levels;
    }

    /** @param list<string> $path */
    public static function ofFormula(ResolvedValueInterface $value, array $path): void
    {
        $level = $path[\count($path) - 1];
        $refusal = self::formulaRefusal(
            new ComputedMetricExpression(),
            $path[1],
            \count($path) === 3 ? null : $level,
            (string) $value->plain(),
        );
        if ($refusal !== null) {
            $value->refuse($refusal);
        }
    }

    public static function formulaRefusal(ComputedMetricExpression $expression, string $name, ?string $level, string $formula): ?string
    {
        try {
            $expression->parse($formula);
        } catch (SyntaxError $error) {
            return ComputedMetricRefusalWording::invalidFormulaSyntax($name, $level, $error->getMessage(), $formula);
        }

        return $expression->everyAccessIsALiteralIndex($formula)
            ? null
            : ComputedMetricRefusalWording::everyAccessMustBeALiteralIndex($name, $formula);
    }
}

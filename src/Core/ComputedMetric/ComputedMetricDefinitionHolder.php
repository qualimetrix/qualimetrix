<?php

declare(strict_types=1);

namespace Qualimetrix\Core\ComputedMetric;

final class ComputedMetricDefinitionHolder
{
    /** @var list<ComputedMetricDefinition> */
    private static array $definitions = [];

    /**
     * @param list<ComputedMetricDefinition> $definitions
     */
    public static function setDefinitions(array $definitions): void
    {
        self::$definitions = $definitions;
    }

    /**
     * @return list<ComputedMetricDefinition>
     */
    public static function getDefinitions(): array
    {
        return self::$definitions;
    }

    public static function reset(): void
    {
        self::$definitions = [];
    }
}

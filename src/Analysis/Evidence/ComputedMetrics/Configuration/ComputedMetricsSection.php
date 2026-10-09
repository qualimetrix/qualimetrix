<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NameVocabulary;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\RefusedName;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SectionDeclaration;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * The `computed_metrics:` section: a map keyed by metric name, merged metric
 * by metric and, inside one metric, key by key. A lower layer's metric is
 * removed only by writing `enabled: false` over it.
 *
 * A name is judged in the layer that wrote it, whatever is written under it:
 * a `health.*` name is one of six, any other follows the name grammar.
 *
 * @qmx-threshold coupling.instability warning=0.85 -- Ca=2, Ce=11: the owned section schema
 * uses the native schema and refusal vocabulary; moving one edge only moves the low-Ca signal.
 */
final readonly class ComputedMetricsSection implements DocumentSectionSchemaInterface
{
    public const string KEY = 'computed_metrics';

    private const string HEALTH_PREFIX = 'health.';

    public function declaration(): SectionDeclaration
    {
        return new SectionDeclaration(self::KEY, NodeSchema::namedMap(
            ComputedMetricEntryKeys::entrySchema(),
            NameVocabulary::predicate(self::refuseName(...)),
        ));
    }

    /** The refused spot of a metric's entry, or of a key below it. */
    public static function position(string $metricName, string ...$below): RefusedPosition
    {
        $path = [self::KEY, $metricName, ...array_values($below)];

        return RefusedPosition::open($path, $path[\count($path) - 1]);
    }

    private static function refuseName(string $name): ?RefusedName
    {
        if (str_starts_with($name, self::HEALTH_PREFIX)) {
            $accepted = ComputedMetricEntryKeys::acceptedHealthNames();

            return \in_array(substr($name, \strlen(self::HEALTH_PREFIX)), $accepted, true)
                ? null
                : RefusedName::among(
                    ComputedMetricRefusalWording::unknownHealthDimension($name, $accepted),
                    array_map(static fn(string $short): string => self::HEALTH_PREFIX . $short, $accepted),
                );
        }

        if (!ComputedMetricDefinition::isValidName($name)) {
            return RefusedName::open(ComputedMetricRefusalWording::nameGrammar($name, ComputedMetricDefinition::NAME_TEMPLATE));
        }

        // A channel's level is a coordinate beside the channel name; a name
        // ending in a level word would put it back inside the name.
        $lastSegment = substr($name, (int) strrpos($name, '.') + 1);

        return SymbolLevel::tryFrom($lastSegment) === null
            ? null
            : RefusedName::open(ComputedMetricRefusalWording::nameEndsInALevelWord($name, $lastSegment, FindingChannel::LEVEL_SEPARATOR));
    }
}

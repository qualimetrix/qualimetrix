<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration;

use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * Refuses every key of one `computed_metrics:` entry that neither depth
 * answers for.
 *
 * The traversal owns the shape of the *containers* it walks into — `formulas:`
 * must be a map, or there is nothing inside it to walk — but not the shape of
 * leaf values (`formula`, `levels`, `threshold`, ...); those are
 * {@see \Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricOverrideReader}'s,
 * because the reader is what stays safe against every caller, this walk
 * included, while the traversal answers only for the names it iterates.
 */
final class ComputedMetricEntryKeyRecognition
{
    /**
     * @param array<string, mixed> $entry the entry as the resolver received it
     *
     * @throws ConfigurationRefusal on the first unrecognised key, or on
     *                              `formulas:` holding something other than a map
     */
    public static function refuseUnknownKeys(array $entry, string $metricName): void
    {
        $segments = ComputedMetricEntryKeys::nameSegments($metricName);
        $accepted = ComputedMetricEntryKeys::acceptedEntryKeys();

        foreach ($entry as $writtenKey => $value) {
            $key = (string) $writtenKey;
            $normalized = ConfigKeySpelling::normalize($key);

            if ($normalized === 'formulas') {
                self::refuseUnknownFormulaKeys($value, $segments, $metricName);

                continue;
            }

            if ($accepted->knows($normalized)) {
                continue;
            }

            throw ConfigurationRefusal::at(
                ConfigurationOrigin::of(ConfigurationSource::Resolved),
                RefusedPosition::closed([...$segments, $key], $key, $accepted->acceptedForDisplay()),
                ComputedMetricRefusalWording::notAnEntryKey($key, $metricName, $accepted->acceptedForDisplay()),
            );
        }
    }

    /**
     * `formulas: "1+1"` and `formulas: 5` are measured silent no-ops today:
     * there is nothing inside a scalar to walk, and the walk's two legal
     * outcomes are to refuse itself or to pass silently. Passing silently
     * reproduces the defect this walk exists to close, so it refuses.
     *
     * `null` is accepted, the same way an absent key is: an empty `formulas:`
     * block means what an omitted one means.
     *
     * @param list<string> $segments the entry's own segments, without "formulas"
     */
    private static function refuseUnknownFormulaKeys(mixed $value, array $segments, string $metricName): void
    {
        if ($value === null) {
            return;
        }

        if (!\is_array($value)) {
            throw ConfigurationRefusal::at(
                ConfigurationOrigin::of(ConfigurationSource::Resolved),
                RefusedPosition::open([...$segments, 'formulas'], 'formulas'),
                ComputedMetricShapeRefusalWording::formulasNotAMap($metricName, $value),
            );
        }

        $accepted = ComputedMetricEntryKeys::acceptedFormulaKeys();

        foreach ($value as $writtenKey => $_) {
            $key = (string) $writtenKey;
            $normalized = ConfigKeySpelling::normalize($key);

            if ($accepted->knows($normalized)) {
                continue;
            }

            throw ConfigurationRefusal::at(
                ConfigurationOrigin::of(ConfigurationSource::Resolved),
                RefusedPosition::closed([...$segments, 'formulas', $key], $key, $accepted->acceptedForDisplay()),
                self::formulaKeyWording($key, $metricName),
            );
        }
    }

    /**
     * The same real-level-word-vs-not-a-level-at-all distinction
     * {@see \Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricOverrideReader}'s
     * `mapLevel()` makes for `levels:` elements, extended to `formulas:` keys:
     * `callable` and `file` are real words in the level vocabulary this
     * capability simply does not report at, which is a different mistake from
     * writing a word that is not a level at all.
     */
    private static function formulaKeyWording(string $key, string $metricName): string
    {
        return SymbolLevel::tryFrom($key) !== null
            ? ComputedMetricRefusalWording::formulaKeyNotAReportingLevel(
                $key,
                $metricName,
                ComputedMetricEntryKeys::acceptedFormulaKeys()->acceptedForDisplay(),
            )
            : ComputedMetricRefusalWording::formulaKeyNotALevelAtAll($key, $metricName);
    }
}

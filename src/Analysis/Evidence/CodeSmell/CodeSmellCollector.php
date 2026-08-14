<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell;

use PhpParser\Node;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AbstractCollector;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use SplFileInfo;

/**
 * Collects code smell metrics for files.
 *
 * Detects various code smells and stores entries for each type.
 *
 * Entries (codeSmell.{type}):
 * - line: int — line number of the occurrence
 * - extra: string|null — auxiliary data (e.g. parameter/function name), type-specific
 * - promoted: bool|null — for `boolean_argument` only: whether the parameter is a
 *   promoted constructor property rather than a plain argument
 *
 * Types: goto, eval, exit, empty_catch, debug_code, error_suppression, count_in_loop, superglobals, boolean_argument
 */
final class CodeSmellCollector extends AbstractCollector
{
    private const NAME = 'code-smell';

    public const SMELL_TYPES = [
        'goto',
        'eval',
        'exit',
        'empty_catch',
        'debug_code',
        'error_suppression',
        'count_in_loop',
        'superglobals',
        'boolean_argument',
    ];

    public function __construct()
    {
        $this->visitor = new CodeSmellVisitor();
    }

    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        $metrics = [];

        foreach (self::SMELL_TYPES as $type) {
            $metrics[] = "codeSmell.{$type}";
        }

        return $metrics;
    }

    /**
     * @param Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        \assert($this->visitor instanceof CodeSmellVisitor);

        $bag = new MetricBag();

        foreach (self::SMELL_TYPES as $type) {
            $locations = $this->visitor->getLocationsByType($type);

            foreach ($locations as $location) {
                $entry = [
                    'line' => $location->line,
                    ...$this->visitor->getSubjectComponents($location),
                ];

                if ($location->extra !== null) {
                    $entry['extra'] = $location->extra;
                }

                if ($location->promoted !== null) {
                    $entry['promoted'] = $location->promoted;
                }

                $bag = $bag->withEntry("codeSmell.{$type}", $entry);
            }
        }

        return $bag;
    }
}

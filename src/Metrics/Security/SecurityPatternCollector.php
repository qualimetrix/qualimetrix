<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Security;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Metrics\AbstractCollector;
use PhpParser\Node;
use SplFileInfo;

/**
 * Collects security pattern metrics for files.
 *
 * Detects SQL injection, XSS, and command injection patterns.
 *
 * Metrics:
 * - security.{type}.count - total number of findings per type
 * - security.{type}.line.{i} - line number for each finding
 * - security.{type}.superglobal.{i} - superglobal index for each finding (0=_GET, 1=_POST, 2=_REQUEST, 3=_COOKIE)
 * Types: sql_injection, xss, command_injection
 */
final class SecurityPatternCollector extends AbstractCollector
{
    private const NAME = 'security-pattern';

    public const PATTERN_TYPES = [
        'sql_injection',
        'xss',
        'command_injection',
    ];

    /** @var array<string, int> Maps superglobal names to numeric indices for MetricBag storage */
    public const SUPERGLOBAL_INDEX = [
        '_GET' => 0,
        '_POST' => 1,
        '_REQUEST' => 2,
        '_COOKIE' => 3,
    ];

    public function __construct()
    {
        $this->visitor = new SecurityPatternVisitor();
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

        foreach (self::PATTERN_TYPES as $type) {
            $metrics[] = "security.{$type}.count";
        }

        return $metrics;
    }

    /**
     * @param Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        \assert($this->visitor instanceof SecurityPatternVisitor);

        $bag = new MetricBag();

        foreach (self::PATTERN_TYPES as $type) {
            $locations = $this->visitor->getLocationsByType($type);
            $count = \count($locations);

            $bag = $bag->with("security.{$type}.count", $count);

            foreach ($locations as $i => $location) {
                $bag = $bag->with("security.{$type}.line.{$i}", $location->line);
                $bag = $bag->with("security.{$type}.superglobal.{$i}", $this->extractSuperglobalIndex($location->context));
            }
        }

        return $bag;
    }

    /**
     * Extract the superglobal index from a context string.
     *
     * Context strings contain superglobal names like "$_GET", "$_POST", etc.
     * Returns the numeric index from SUPERGLOBAL_INDEX, or -1 if unknown.
     */
    private function extractSuperglobalIndex(string $context): int
    {
        foreach (self::SUPERGLOBAL_INDEX as $name => $index) {
            if (str_contains($context, $name)) {
                return $index;
            }
        }

        return -1;
    }
}

<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Security;

use PhpParser\Node;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Metrics\AbstractCollector;
use SplFileInfo;

/**
 * Collects security pattern metrics for files.
 *
 * Detects SQL injection, XSS, and command injection patterns.
 *
 * Entries (security.{type}):
 * - line: int — line number of the finding
 * - superglobal: string — superglobal name (e.g. '$_GET', '$_POST')
 *
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
            $metrics[] = "security.{$type}";
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

            foreach ($locations as $location) {
                $bag = $bag->withEntry("security.{$type}", [
                    'line' => $location->line,
                    'superglobal' => $this->extractSuperglobalName($location->context),
                ]);
            }
        }

        return $bag;
    }

    /**
     * Extract the superglobal name from a context string.
     *
     * Context strings contain superglobal references like "$_GET['id']".
     * Returns the bare superglobal name (e.g. '_GET'), or empty string if unknown.
     */
    private function extractSuperglobalName(string $context): string
    {
        foreach (['_GET', '_POST', '_REQUEST', '_COOKIE'] as $name) {
            if (str_contains($context, $name)) {
                return $name;
            }
        }

        return '';
    }
}

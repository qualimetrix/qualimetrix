<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Security;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Metrics\AbstractCollector;
use PhpParser\Node;
use SplFileInfo;

/**
 * Collects hardcoded credentials metrics for files.
 *
 * Detects hardcoded passwords, API keys, secrets, and other credentials.
 *
 * Metrics:
 * - security.hardcodedCredentials.count - total number of findings
 * - security.hardcodedCredentials.line.{i} - line number for each finding
 */
final class HardcodedCredentialsCollector extends AbstractCollector
{
    private const NAME = 'hardcoded-credentials';

    /** @var array<string, int> */
    public const PATTERN_CODES = [
        'variable' => 1,
        'array_key' => 2,
        'class_const' => 3,
        'define' => 4,
        'property' => 5,
        'parameter' => 6,
    ];

    public function __construct(
        SensitiveNameMatcher $matcher = new SensitiveNameMatcher(),
        int $minValueLength = 4,
    ) {
        $this->visitor = new HardcodedCredentialsVisitor($matcher, $minValueLength);
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
        return ['security.hardcodedCredentials.count'];
    }

    /**
     * @param Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        \assert($this->visitor instanceof HardcodedCredentialsVisitor);

        $locations = $this->visitor->getLocations();
        $bag = new MetricBag();
        $bag = $bag->with('security.hardcodedCredentials.count', \count($locations));

        foreach ($locations as $i => $location) {
            $bag = $bag->with("security.hardcodedCredentials.line.{$i}", $location->line);
            $bag = $bag->with("security.hardcodedCredentials.pattern.{$i}", self::encodePattern($location->pattern));
        }

        return $bag;
    }

    private static function encodePattern(string $pattern): int
    {
        return self::PATTERN_CODES[$pattern] ?? 0;
    }
}

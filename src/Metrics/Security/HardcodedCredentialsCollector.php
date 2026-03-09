<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Security;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricName;
use AiMessDetector\Metrics\AbstractCollector;
use PhpParser\Node;
use SplFileInfo;

/**
 * Collects hardcoded credentials metrics for files.
 *
 * Detects hardcoded passwords, API keys, secrets, and other credentials.
 *
 * Entries (security.hardcodedCredentials):
 * - line: int — line number of the finding
 * - pattern: string — detection pattern type (variable, array_key, etc.)
 */
final class HardcodedCredentialsCollector extends AbstractCollector
{
    private const NAME = 'hardcoded-credentials';

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
        return [MetricName::SECURITY_HARDCODED_CREDENTIALS];
    }

    /**
     * @param Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        \assert($this->visitor instanceof HardcodedCredentialsVisitor);

        $locations = $this->visitor->getLocations();
        $bag = new MetricBag();

        foreach ($locations as $location) {
            $bag = $bag->withEntry(MetricName::SECURITY_HARDCODED_CREDENTIALS, [
                'line' => $location->line,
                'pattern' => $location->pattern,
            ]);
        }

        return $bag;
    }
}

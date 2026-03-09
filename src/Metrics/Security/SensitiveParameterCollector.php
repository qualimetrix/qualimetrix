<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Security;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Metrics\AbstractCollector;
use PhpParser\Node;
use SplFileInfo;

/**
 * Collects sensitive parameter metrics for files.
 *
 * Detects parameters with sensitive names (password, secret, etc.)
 * that are missing the #[\SensitiveParameter] attribute.
 *
 * Metrics:
 * - security.sensitiveParameter.count - total number of findings
 * - security.sensitiveParameter.line.{i} - line number for each finding
 */
final class SensitiveParameterCollector extends AbstractCollector
{
    private const NAME = 'sensitive-parameter';

    public function __construct(
        SensitiveNameMatcher $matcher = new SensitiveNameMatcher(),
    ) {
        $this->visitor = new SensitiveParameterVisitor($matcher);
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
        return ['security.sensitiveParameter.count'];
    }

    /**
     * @param Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        \assert($this->visitor instanceof SensitiveParameterVisitor);

        $locations = $this->visitor->getLocations();
        $bag = new MetricBag();
        $bag = $bag->with('security.sensitiveParameter.count', \count($locations));

        foreach ($locations as $i => $location) {
            $bag = $bag->with("security.sensitiveParameter.line.{$i}", $location->line);
        }

        return $bag;
    }
}

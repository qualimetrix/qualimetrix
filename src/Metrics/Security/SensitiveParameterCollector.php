<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Security;

use PhpParser\Node;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Metrics\AbstractCollector;
use SplFileInfo;

/**
 * Collects sensitive parameter metrics for files.
 *
 * Detects parameters with sensitive names (password, secret, etc.)
 * that are missing the #[\SensitiveParameter] attribute.
 *
 * Entries (security.sensitiveParameter):
 * - line: int — line number of the finding
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
        return [MetricName::SECURITY_SENSITIVE_PARAMETER];
    }

    /**
     * @param Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        \assert($this->visitor instanceof SensitiveParameterVisitor);

        $locations = $this->visitor->getLocations();
        $bag = new MetricBag();

        foreach ($locations as $location) {
            $bag = $bag->withEntry(MetricName::SECURITY_SENSITIVE_PARAMETER, [
                'line' => $location->line,
            ]);
        }

        return $bag;
    }
}

<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Fixture;

use Qualimetrix\Core\Rule\RuleOptionsInterface;
use Qualimetrix\Core\Violation\Severity;

/**
 * Test fixture for RuleOptions with required parameters (no defaults).
 */
final readonly class TestRuleOptionsWithRequiredParams implements RuleOptionsInterface
{
    /**
     * @param list<mixed> $items
     */
    public function __construct(
        public bool $enabled,
        public int $threshold,
        public float $ratio,
        public string $name,
        public array $items,
        public ?string $optional,
    ) {}

    public static function fromArray(array $config): self
    {
        $items = $config['items'] ?? [];

        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            threshold: (int) ($config['threshold'] ?? 0),
            ratio: (float) ($config['ratio'] ?? 0.0),
            name: (string) ($config['name'] ?? ''),
            items: \is_array($items) ? array_values($items) : [],
            optional: $config['optional'] ?? null,
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        return null;
    }
}

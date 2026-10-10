<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Fixtures;

use Qualimetrix\Analysis\Finding\Contract\Rule\ResolvedRuleOptionValues;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;

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

    public static function fromResolved(ResolvedRuleOptionValues $config): self
    {
        $items = $config->strings('items');
        $optional = $config->node('optional') === null ? null : $config->text('optional', '');

        return new self(
            enabled: $config->boolean('enabled', true),
            threshold: $config->integer('threshold', 0),
            ratio: $config->number('ratio', 0.0),
            name: $config->text('name', ''),
            items: $items,
            optional: $optional,
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

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'enabled' => RuleOptionShape::boolean()->orNull(),
            'items' => RuleOptionShape::listOf(RuleOptionShape::text())->orNull(),
            'name' => RuleOptionShape::text()->orNull(),
            'optional' => RuleOptionShape::text()->orNull(),
            'ratio' => RuleOptionShape::number()->orNull(),
            'threshold' => RuleOptionShape::integer()->orNull(),
        ]);
    }
}

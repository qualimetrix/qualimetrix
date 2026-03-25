<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Coupling;

use InvalidArgumentException;
use Qualimetrix\Core\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Core\Rule\LevelOptionsInterface;
use Qualimetrix\Core\Rule\RuleLevel;
use Qualimetrix\Core\Violation\Severity;

/**
 * Options for CboRule (hierarchical).
 *
 * Supports class and namespace levels for CBO thresholds.
 */
final readonly class CboOptions implements HierarchicalRuleOptionsInterface
{
    public function __construct(
        public ClassCboOptions $class = new ClassCboOptions(),
        public NamespaceCboOptions $namespace = new NamespaceCboOptions(),
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        // Explicit top-level enabled: false disables all levels
        if (\array_key_exists('enabled', $config) && $config['enabled'] === false) {
            return new self(
                class: new ClassCboOptions(enabled: false),
                namespace: new NamespaceCboOptions(enabled: false),
            );
        }

        // Handle hierarchical format: {class: {...}, namespace: {...}}
        $classConfig = isset($config['class']) && \is_array($config['class'])
            ? $config['class']
            : [];
        $namespaceConfig = isset($config['namespace']) && \is_array($config['namespace'])
            ? $config['namespace']
            : [];

        return new self(
            class: ClassCboOptions::fromArray($classConfig),
            namespace: NamespaceCboOptions::fromArray($namespaceConfig),
        );
    }

    public function isEnabled(): bool
    {
        return $this->class->isEnabled() || $this->namespace->isEnabled();
    }

    public function getSeverity(int|float $value): ?Severity
    {
        return $this->class->getSeverity($value);
    }

    public function forLevel(RuleLevel $level): LevelOptionsInterface
    {
        return match ($level) {
            RuleLevel::Class_ => $this->class,
            RuleLevel::Namespace_ => $this->namespace,
            default => throw new InvalidArgumentException(
                \sprintf('Level %s is not supported by CboRule', $level->value),
            ),
        };
    }

    public function isLevelEnabled(RuleLevel $level): bool
    {
        return match ($level) {
            RuleLevel::Class_ => $this->class->isEnabled(),
            RuleLevel::Namespace_ => $this->namespace->isEnabled(),
            default => false,
        };
    }

    /**
     * @return list<RuleLevel>
     */
    public function getSupportedLevels(): array
    {
        return [RuleLevel::Class_, RuleLevel::Namespace_];
    }
}

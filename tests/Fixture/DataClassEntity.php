<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Fixture;

/**
 * RFC-008 Test Fixture: Data class entity with only getters/setters.
 *
 * Characteristics:
 * - isReadonly = false
 * - isPromotedPropertiesOnly = false
 * - isDataClass = true (only constructor + getters + setters)
 *
 * This class should be EXCLUDED from:
 * - WMC checks (excludeDataClasses = true)
 */
class DataClassEntity
{
    private string $id;
    private string $name;
    private int $value;
    private bool $active;

    public function __construct(
        string $id,
        string $name,
        int $value,
        bool $active = true,
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->value = $value;
        $this->active = $active;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getValue(): int
    {
        return $this->value;
    }

    public function setValue(int $value): void
    {
        $this->value = $value;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function hasValue(): bool
    {
        return $this->value > 0;
    }
}

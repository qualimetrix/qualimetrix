<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Fixture;

use DateTimeImmutable;

/**
 * RFC-008 Test Fixture: Readonly DTO with promoted properties.
 *
 * Characteristics:
 * - isReadonly = true
 * - isPromotedPropertiesOnly = true
 * - isDataClass = true (only constructor)
 *
 * This class should be EXCLUDED from:
 * - LCOM checks (excludeReadonly)
 * - PropertyCount checks (excludeReadonly, excludePromotedOnly)
 */
readonly class ReadonlyDto
{
    /**
     * @param array<int, string> $tags
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public int $age,
        public bool $active,
        public ?string $description,
        public float $balance,
        public array $tags,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?string $deletedAt,
        public string $status,
    ) {}
}

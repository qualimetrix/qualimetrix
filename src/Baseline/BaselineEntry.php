<?php

declare(strict_types=1);

namespace AiMessDetector\Baseline;

/**
 * Represents a single violation entry in baseline.
 *
 * Since version 2, the symbol path is stored as the key (not in the entry).
 */
final readonly class BaselineEntry
{
    public function __construct(
        public string $rule,
        public string $hash,
    ) {}

    /**
     * Creates entry from array representation (for deserialization).
     *
     * @param array{rule: string, hash: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            rule: $data['rule'],
            hash: $data['hash'],
        );
    }

    /**
     * Converts entry to array representation (for serialization).
     *
     * @return array{rule: string, hash: string}
     */
    public function toArray(): array
    {
        return [
            'rule' => $this->rule,
            'hash' => $this->hash,
        ];
    }
}

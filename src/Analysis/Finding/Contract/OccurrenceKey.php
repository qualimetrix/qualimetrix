<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use InvalidArgumentException;

/**
 * Stable semantic discriminator for findings sharing one channel and subject.
 */
final readonly class OccurrenceKey
{
    private function __construct(
        public string $value,
    ) {}

    /**
     * Creates an opaque SHA-256 key from named scalar evidence.
     *
     * @param array<string, bool|float|int|string> $scalarEvidence
     */
    public static function semantic(string $kind, array $scalarEvidence): self
    {
        if ($kind === '') {
            throw new InvalidArgumentException('Occurrence key kind must not be empty');
        }

        ksort($scalarEvidence);
        foreach ($scalarEvidence as $name => $value) {
            if ($name === '' || !\is_scalar($value)) {
                throw new InvalidArgumentException('Occurrence key evidence must use non-empty scalar names and scalar values');
            }
        }

        $payload = json_encode(
            ['kind' => $kind, 'evidence' => $scalarEvidence],
            \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION | \JSON_UNESCAPED_SLASHES,
        );

        return new self(hash('sha256', $payload));
    }
}

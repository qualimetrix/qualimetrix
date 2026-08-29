<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use InvalidArgumentException;

/**
 * Stable semantic discriminator for findings sharing one channel and subject.
 *
 * **The value is 16 hex characters, not the full digest.** The discrimination
 * domain is a single (subject, channel) pair — units of members, not the
 * whole corpus — so 64 bits of the underlying SHA-256 carry far more headroom
 * than that domain needs; {@see \Qualimetrix\Analysis\Policy\Baseline\EntrySelector}
 * makes the same argument at 48 bits for a domain that addresses the entire
 * identity. Shortening is a breaking change to every consumer that persists
 * this value across runs — the baseline file, and, through
 * {@see \Qualimetrix\Analysis\Finding\Contract\Finding::getFingerprint()},
 * GitLab and SARIF output — because a shorter value is a different value, not
 * a compressed version of the same one.
 */
final readonly class OccurrenceKey
{
    /** Hex characters kept from the underlying SHA-256; see the class docblock for the domain argument. */
    private const int LENGTH = 16;

    private function __construct(
        public string $value,
    ) {}

    /**
     * Creates an opaque key from named scalar evidence, truncated from a
     * SHA-256 digest of the same material — never computed from a shorter or
     * different hash.
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

        return new self(substr(hash('sha256', $payload), 0, self::LENGTH));
    }
}

<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * One surface with its opaque fingerprints replaced by the identities they hash,
 * plus the count that proves the replacement matched something.
 *
 * The count is not bookkeeping. A literal naming a published value is only a
 * guard while something independent of it says it found that value: a
 * substitution that silently matched nothing would hand the comparison an
 * artifact still full of hashes, and the hashes compare equal to themselves for
 * as long as nobody renames anything. So the number of replacements is measured
 * against the number of fingerprints the surface published, and a shortfall is a
 * failure of its own ({@see FailureClass::FINGERPRINT_OPAQUE}) rather than a
 * quietly weaker comparison.
 */
final class FingerprintSubstitution
{
    /**
     * @param list<string> $missing the published hashes no occurrence of which was found
     */
    public function __construct(
        public readonly string $text,
        public readonly int $replaced,
        public readonly int $published,
        public readonly array $missing,
    ) {}

    public function isComplete(): bool
    {
        return $this->missing === [] && $this->replaced === $this->published;
    }

    public function shortfall(): string
    {
        return \sprintf(
            'replaced %d of the %d fingerprint(s) this surface publishes%s',
            $this->replaced,
            $this->published,
            $this->missing === [] ? '' : '; not found: ' . implode(', ', $this->missing),
        );
    }
}

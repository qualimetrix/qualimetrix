<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;

#[CoversClass(OccurrenceKey::class)]
final class OccurrenceKeyTest extends TestCase
{
    #[Test]
    public function itCanonicalizesNamedScalarEvidenceIndependentlyOfInputOrder(): void
    {
        $first = OccurrenceKey::semantic('security-pattern', ['type' => 'superglobal', 'name' => '_GET']);
        $second = OccurrenceKey::semantic('security-pattern', ['name' => '_GET', 'type' => 'superglobal']);

        self::assertSame($first->value, $second->value);
        self::assertSame(16, \strlen($first->value));
    }

    #[Test]
    public function itDistinguishesTheSemanticKindAndEvidence(): void
    {
        $first = OccurrenceKey::semantic('code-smell', ['type' => 'goto']);
        $second = OccurrenceKey::semantic('security-pattern', ['type' => 'goto']);
        $third = OccurrenceKey::semantic('code-smell', ['type' => 'eval']);

        self::assertNotSame($first->value, $second->value);
        self::assertNotSame($first->value, $third->value);
    }

    #[Test]
    public function itRejectsAnEmptyKindOrEvidenceName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OccurrenceKey::semantic('', ['type' => 'goto']);
    }

    /**
     * The stored value must be a prefix of the full SHA-256 of the same
     * canonical payload — not a shorter hash computed over different or less
     * material. A truncation bug that hashed a different payload (or a
     * different algorithm) could still produce 16 hex characters and pass
     * every other test here.
     */
    #[Test]
    public function itTruncatesTheSameSha256ItWouldHaveReturnedInFull(): void
    {
        $key = OccurrenceKey::semantic('security-pattern', ['type' => 'superglobal', 'name' => '_GET']);

        $expectedPayload = json_encode(
            ['kind' => 'security-pattern', 'evidence' => ['name' => '_GET', 'type' => 'superglobal']],
            \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION | \JSON_UNESCAPED_SLASHES,
        );

        self::assertSame(substr(hash('sha256', $expectedPayload), 0, 16), $key->value);
    }

    /**
     * Every other test in this class computes its expected value by calling
     * {@see OccurrenceKey::semantic()} itself (directly, or by reassembling
     * the same payload construction) — so a change to the hashing mechanism
     * itself (payload shape, `ksort`, JSON flags, or the truncation length)
     * would still make expectation and actual agree and none of them would
     * fail. The values below are hardcoded hex literals, produced once by
     * an independent computation and pinned as text, specifically so that a
     * change to the mechanism — not just to the discriminator inputs — is
     * caught. Changing any literal here is a breaking change to every
     * consumer that persists this value across runs: the baseline file, and,
     * through {@see \Qualimetrix\Analysis\Finding\Contract\Finding::getFingerprint()},
     * GitLab and SARIF output.
     *
     * Each case pins a distinct part of the mechanism:
     * - `dbbe0e35ed4a985b` — an ordinary two-key evidence set, keys already
     *   alphabetical; pins the payload shape (`{"kind":...,"evidence":...}`)
     *   and the SHA-256 algorithm choice.
     * - `30e2c440d8e96d81` — the same evidence values passed in non-alphabetical
     *   input order (gamma, alpha, beta); pins that `ksort` runs before
     *   hashing, so input order cannot change the key.
     * - `e4e9e77c9057a83b` — one evidence set spanning bool, int, float, and
     *   string in one payload; the float is a whole number (2.0), which pins
     *   `JSON_PRESERVE_ZERO_FRACTION` (without it, `2.0` encodes as `2` and
     *   the digest — and this literal — would differ).
     *
     * strlen() on each pins the truncation length independently of the
     * literals' own length, so a `LENGTH` change is caught even if a shorter
     * prefix happened to still start with the same characters.
     */
    #[Test]
    public function itPinsTheHashOfFixedInputsAsLiteralHexToCatchMechanismDrift(): void
    {
        $ordinary = OccurrenceKey::semantic('code-smell', ['type' => 'superglobal', 'name' => '_GET']);
        $unsortedInput = OccurrenceKey::semantic('complexity', ['gamma' => 'x', 'alpha' => 'z', 'beta' => 'y']);
        $scalarDiversity = OccurrenceKey::semantic('coupling', ['ratio' => 2.0, 'active' => true, 'label' => 'x', 'count' => 3]);

        self::assertSame('dbbe0e35ed4a985b', $ordinary->value);
        self::assertSame('30e2c440d8e96d81', $unsortedInput->value);
        self::assertSame('e4e9e77c9057a83b', $scalarDiversity->value);

        self::assertSame(16, \strlen($ordinary->value));
        self::assertSame(16, \strlen($unsortedInput->value));
        self::assertSame(16, \strlen($scalarDiversity->value));
    }
}

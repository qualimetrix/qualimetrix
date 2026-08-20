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
}

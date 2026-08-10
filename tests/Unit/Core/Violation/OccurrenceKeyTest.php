<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Violation;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Violation\OccurrenceKey;

#[CoversClass(OccurrenceKey::class)]
final class OccurrenceKeyTest extends TestCase
{
    #[Test]
    public function itCanonicalizesNamedScalarEvidenceIndependentlyOfInputOrder(): void
    {
        $first = OccurrenceKey::semantic('security-pattern', ['type' => 'superglobal', 'name' => '_GET']);
        $second = OccurrenceKey::semantic('security-pattern', ['name' => '_GET', 'type' => 'superglobal']);

        self::assertSame($first->value, $second->value);
        self::assertSame(64, \strlen($first->value));
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
}

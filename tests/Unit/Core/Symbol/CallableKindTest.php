<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Symbol;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\CallableKind;

#[CoversClass(CallableKind::class)]
final class CallableKindTest extends TestCase
{
    #[Test]
    public function itDefinesTheApprovedCallableKinds(): void
    {
        self::assertSame(
            ['method', 'function', 'property-hook', 'anonymous-callable'],
            array_map(static fn(CallableKind $kind): string => $kind->value, CallableKind::cases()),
        );
    }
}

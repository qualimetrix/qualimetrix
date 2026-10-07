<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\DependencyModel\Unit\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\AttributeSite;

#[CoversClass(AttributeSite::class)]
final class AttributeSiteTest extends TestCase
{
    #[Test]
    public function itIdentifiesOnlyDeclaredMemberSites(): void
    {
        $members = array_values(array_map(
            static fn(AttributeSite $site): string => $site->value,
            array_filter(AttributeSite::cases(), static fn(AttributeSite $site): bool => $site->isDeclaredMember()),
        ));

        self::assertSame([
            'method',
            'property',
            'parameter',
            'promoted_parameter',
            'class_constant',
            'enum_case',
            'property_hook',
            'hook_parameter',
        ], $members);
    }
}

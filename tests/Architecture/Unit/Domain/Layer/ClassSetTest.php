<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Architecture\Unit\Domain\Layer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Architecture\Domain\Layer\ClassContextFactory;
use Qualimetrix\Architecture\Domain\Layer\ClassSet;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(ClassSet::class)]
final class ClassSetTest extends TestCase
{
    #[Test]
    public function isEmpty_reflectsClassListContents(): void
    {
        self::assertTrue((new ClassSet([], new ClassContextFactory()))->isEmpty());

        $set = new ClassSet(
            [SymbolPath::forClass('App\\Service', 'UserService')],
            new ClassContextFactory(),
        );

        self::assertFalse($set->isEmpty());
    }

    #[Test]
    public function classes_returnsSuppliedListUnchanged(): void
    {
        $first = SymbolPath::forClass('App\\Service', 'UserService');
        $second = SymbolPath::forClass('App\\Service', 'OrderService');

        $set = new ClassSet([$first, $second], new ClassContextFactory());

        self::assertSame([$first, $second], $set->classes());
    }

    #[Test]
    public function contextFor_delegatesToFactory_inNoGraphModeReturnsMinimalContext(): void
    {
        $factory = new ClassContextFactory();
        $set = new ClassSet(
            [SymbolPath::forClass('App\\Service', 'UserService')],
            $factory,
        );

        $context = $set->contextFor(SymbolPath::forClass('App\\Service', 'UserService'));

        self::assertSame('App\\Service\\UserService', $context->fqn);
        self::assertSame('UserService', $context->shortName);
        self::assertSame([], $context->attributeFqns);
        self::assertSame([], $context->interfaces);
        self::assertSame([], $context->parentClasses);
    }
}

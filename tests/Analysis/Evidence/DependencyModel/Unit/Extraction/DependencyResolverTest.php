<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\DependencyModel\Unit\Extraction;

use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Name\Relative;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Stmt\UseUse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyResolver;

#[CoversClass(DependencyResolver::class)]
final class DependencyResolverTest extends TestCase
{
    private DependencyResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new DependencyResolver();
    }

    #[Test]
    public function itResolvesAFullyQualifiedNameUnchanged(): void
    {
        $name = new FullyQualified('Foo\\Bar\\Baz');

        $result = $this->resolver->resolve($name);

        self::assertSame('Foo\\Bar\\Baz', $result);
    }

    #[Test]
    public function itPrependsTheCurrentNamespaceToARelativeName(): void
    {
        $this->resolver->setNamespace('App\\Service');
        $name = new Relative(['Foo', 'Bar']);

        $result = $this->resolver->resolve($name);

        self::assertSame('App\\Service\\Foo\\Bar', $result);
    }

    #[Test]
    public function itResolvesAnUnqualifiedNameToItsImportedFqcn(): void
    {
        $use = new Use_([
            new UseUse(new Name('Vendor\\Package\\SomeClass')),
        ]);
        $this->resolver->addUseStatement($use);

        $name = new Name('SomeClass');
        $result = $this->resolver->resolve($name);

        self::assertSame('Vendor\\Package\\SomeClass', $result);
    }

    #[Test]
    public function itResolvesAnAliasedNameToTheOriginalImportedFqcn(): void
    {
        $use = new Use_([
            new UseUse(
                new Name('Vendor\\Package\\SomeClass'),
                new Identifier('Alias'),
            ),
        ]);
        $this->resolver->addUseStatement($use);

        $name = new Name('Alias');
        $result = $this->resolver->resolve($name);

        self::assertSame('Vendor\\Package\\SomeClass', $result);
    }

    #[Test]
    public function itResolvesAQualifiedNameWhoseFirstSegmentIsImported(): void
    {
        $use = new Use_([
            new UseUse(new Name('Vendor\\Package')),
        ]);
        $this->resolver->addUseStatement($use);

        $name = new Name(['Package', 'SubClass']);
        $result = $this->resolver->resolve($name);

        self::assertSame('Vendor\\Package\\SubClass', $result);
    }

    #[Test]
    public function itPrependsTheCurrentNamespaceToAnUnimportedUnqualifiedName(): void
    {
        $this->resolver->setNamespace('App\\Domain');

        $name = new Name('MyClass');
        $result = $this->resolver->resolve($name);

        self::assertSame('App\\Domain\\MyClass', $result);
    }

    #[Test]
    public function itResolvesAnUnimportedUnqualifiedNameToItselfWhenThereIsNoNamespace(): void
    {
        $name = new Name('GlobalClass');
        $result = $this->resolver->resolve($name);

        self::assertSame('GlobalClass', $result);
    }

    #[Test]
    public function itResolvesNamesImportedThroughAGroupUseStatement(): void
    {
        $groupUse = new GroupUse(
            new Name('Vendor\\Package'),
            [
                new UseUse(new Name('ClassA')),
                new UseUse(new Name('ClassB'), new Identifier('B')),
            ],
        );
        $this->resolver->addGroupUseStatement($groupUse);

        $name1 = new Name('ClassA');
        $name2 = new Name('B');

        self::assertSame('Vendor\\Package\\ClassA', $this->resolver->resolve($name1));
        self::assertSame('Vendor\\Package\\ClassB', $this->resolver->resolve($name2));
    }

    #[Test]
    public function itClearsImportsAndTheNamespaceOnReset(): void
    {
        $this->resolver->setNamespace('App');
        $use = new Use_([
            new UseUse(new Name('Vendor\\Class')),
        ]);
        $this->resolver->addUseStatement($use);

        $this->resolver->reset();

        self::assertNull($this->resolver->getNamespace());
        self::assertSame([], $this->resolver->getImports());
    }

    #[Test]
    public function itStripsTheLeadingBackslashWhenResolvingAFullyQualifiedString(): void
    {
        $result = $this->resolver->resolveString('\\Foo\\Bar');

        self::assertSame('Foo\\Bar', $result);
    }

    #[Test]
    public function itResolvesAnImportedNameGivenAsAString(): void
    {
        $use = new Use_([
            new UseUse(new Name('Vendor\\SomeClass')),
        ]);
        $this->resolver->addUseStatement($use);

        $result = $this->resolver->resolveString('SomeClass');

        self::assertSame('Vendor\\SomeClass', $result);
    }

    #[Test]
    public function itResolvesAQualifiedStringWhoseFirstSegmentIsImported(): void
    {
        $use = new Use_([
            new UseUse(new Name('Vendor\\Package')),
        ]);
        $this->resolver->addUseStatement($use);

        $result = $this->resolver->resolveString('Package\\SubClass');

        self::assertSame('Vendor\\Package\\SubClass', $result);
    }

    #[Test]
    public function itPrependsTheCurrentNamespaceToAnUnimportedStringName(): void
    {
        $this->resolver->setNamespace('App\\Domain');

        $result = $this->resolver->resolveString('MyClass');

        self::assertSame('App\\Domain\\MyClass', $result);
    }

    #[Test]
    public function itIgnoresFunctionImportsWhenRecordingUseStatements(): void
    {
        $use = new Use_(
            [new UseUse(new Name('strlen'))],
            Use_::TYPE_FUNCTION,
        );
        $this->resolver->addUseStatement($use);

        self::assertSame([], $this->resolver->getImports());
    }

    #[Test]
    public function itIgnoresConstantImportsWhenRecordingUseStatements(): void
    {
        $use = new Use_(
            [new UseUse(new Name('PHP_EOL'))],
            Use_::TYPE_CONSTANT,
        );
        $this->resolver->addUseStatement($use);

        self::assertSame([], $this->resolver->getImports());
    }
}

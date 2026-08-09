<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics\Complexity;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Metrics\Complexity\CyclomaticComplexityVisitor;

#[CoversClass(CyclomaticComplexityVisitor::class)]
final class CyclomaticComplexityVisitorTest extends TestCase
{
    #[Test]
    public function itEmitsFinalMetadataForEveryCallableKind(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

function helper(): void {}

class Service
{
    public string $name {
        get => 'name';
    }

    public function run(): void
    {
        $closure = function (): void {};
        $arrow = fn (): int => 1;
    }
}
PHP;

        $visitor = new CyclomaticComplexityVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse((new ParserFactory())->createForHostVersion()->parse($code) ?? []);

        $callables = $visitor->getCallablesWithMetrics(RelativePath::fromString('src/Service.php'));
        $byKind = [];
        foreach ($callables as $callable) {
            $byKind[$callable->kind->value][] = $callable;
        }

        self::assertArrayHasKey('function', $byKind);
        self::assertArrayHasKey('method', $byKind);
        self::assertArrayHasKey('property-hook', $byKind);
        self::assertArrayHasKey('anonymous-callable', $byKind);

        self::assertCount(1, $byKind['function']);
        self::assertCount(1, $byKind['method']);
        self::assertCount(1, $byKind['property-hook']);
        self::assertCount(2, $byKind['anonymous-callable']);

        $method = $byKind['method'][0];
        self::assertSame('src/Service.php', $method->declarationPath->file->value());
        self::assertNotNull($method->lexicalClassContext);
        self::assertNotNull($method->classAggregationOwner);

        $hook = $byKind['property-hook'][0];
        self::assertNotNull($hook->classAggregationOwner);

        $anonymousSyntaxes = array_map(
            static fn($callable): ?string => $callable->anonymousSyntax,
            $byKind['anonymous-callable'],
        );
        sort($anonymousSyntaxes);
        self::assertSame(['arrow', 'closure'], $anonymousSyntaxes);
        self::assertNull($byKind['anonymous-callable'][0]->classAggregationOwner);
    }

    #[Test]
    public function itDoesNotOverwriteDuplicateLogicalDeclarations(): void
    {
        $visitor = new CyclomaticComplexityVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse((new ParserFactory())->createForHostVersion()->parse(<<<'PHP'
<?php
namespace App;
function duplicate(): void {}
function duplicate(): void { if (true) {} }
PHP) ?? []);

        $callables = $visitor->getCallablesWithMetrics(RelativePath::fromString('src/Duplicate.php'));
        self::assertCount(2, $callables);
        self::assertSame('func:App::duplicate', $callables[0]->declarationPath->logical->toCanonical());
        self::assertSame('func:App::duplicate', $callables[1]->declarationPath->logical->toCanonical());
        self::assertNotSame($callables[0]->declarationPath->startFilePos, $callables[1]->declarationPath->startFilePos);
        self::assertSame(1, $callables[0]->metrics->get('ccn'));
        self::assertSame(2, $callables[1]->metrics->get('ccn'));
    }
}

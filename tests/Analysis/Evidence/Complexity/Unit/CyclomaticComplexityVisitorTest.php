<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Complexity\Unit;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Complexity\CyclomaticComplexityVisitor;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Evidence\Measurement\Visitor\VisitorMethodContext;
use Qualimetrix\Core\Path\RelativePath;

#[CoversClass(CyclomaticComplexityVisitor::class)]
#[CoversClass(VisitorMethodContext::class)]
final class CyclomaticComplexityVisitorTest extends TestCase
{
    #[Test]
    public function itKeepsNestedCallableAndLexicalSubjectsScopedToOneFile(): void
    {
        $context = new VisitorMethodContext();
        $probe = new class ($context) extends \PhpParser\NodeVisitorAbstract {
            /** @var list<array<string, int|string>> */
            public array $subjects = [];

            public function __construct(private readonly VisitorMethodContext $context) {}

            public function enterNode(\PhpParser\Node $node): ?int
            {
                $this->context->enter($node);
                if ($node instanceof \PhpParser\Node\Stmt\ClassMethod
                    || $node instanceof \PhpParser\Node\PropertyHook
                    || $node instanceof \PhpParser\Node\Stmt\Function_
                    || $node instanceof \PhpParser\Node\Expr\Closure
                    || $node instanceof \PhpParser\Node\Expr\ArrowFunction
                ) {
                    $this->subjects[] = $this->context->fileEntrySubjectComponents($this->context->currentFileEntrySubjectId());
                }

                return null;
            }

            public function leaveNode(\PhpParser\Node $node): ?int
            {
                $this->context->leave($node);

                return null;
            }
        };
        $parser = (new ParserFactory())->createForHostVersion();
        $firstFile = <<<'PHP'
<?php
namespace App;
class Service {
    public string $name { get => fn (): string => 'name'; }
    public function outer(): void {
        function nested(): void {}
        $closure = function (): void {};
        $anonymous = new class { public function hidden(): void {} };
    }
}
PHP;
        $traverser = new NodeTraverser();
        $registrar = (new DeclarationRegistrarFactory())->createForFile();
        $traverser->addVisitor($registrar);
        $context->useDeclarationIndex($registrar->index());
        $traverser->addVisitor($probe);
        $traverser->traverse($parser->parse($firstFile) ?? []);

        self::assertSame('method', $probe->subjects[0]['logicalKind']);
        self::assertSame('name::get', $probe->subjects[0]['member']);
        self::assertSame('function', $probe->subjects[1]['logicalKind']);
        self::assertSame('{closure#1}', $probe->subjects[1]['member']);
        self::assertSame('method', $probe->subjects[2]['logicalKind']);
        self::assertSame('outer', $probe->subjects[2]['member']);
        self::assertSame('function', $probe->subjects[3]['logicalKind']);
        self::assertSame('nested', $probe->subjects[3]['member']);
        self::assertSame('function', $probe->subjects[4]['logicalKind']);
        self::assertSame('{closure#2}', $probe->subjects[4]['member']);
        self::assertSame(['subjectKind' => 'file'], $probe->subjects[5]);

        $context->reset();
        $secondFile = $parser->parse('<?php namespace Next; function onlyHere(): void {}') ?? [];
        $secondTraverser = new NodeTraverser();
        $secondProbe = new class ($context) extends \PhpParser\NodeVisitorAbstract {
            /** @var list<array<string, int|string>> */
            public array $subjects = [];

            public function __construct(private readonly VisitorMethodContext $context) {}

            public function enterNode(\PhpParser\Node $node): ?int
            {
                $this->context->enter($node);
                if ($node instanceof \PhpParser\Node\Stmt\Function_) {
                    $this->subjects[] = $this->context->fileEntrySubjectComponents($this->context->currentFileEntrySubjectId());
                }

                return null;
            }

            public function leaveNode(\PhpParser\Node $node): ?int
            {
                $this->context->leave($node);

                return null;
            }
        };
        $secondRegistrar = (new DeclarationRegistrarFactory())->createForFile();
        $secondTraverser->addVisitor($secondRegistrar);
        $context->useDeclarationIndex($secondRegistrar->index());
        $secondTraverser->addVisitor($secondProbe);
        $secondTraverser->traverse($secondFile);

        self::assertSame('Next', $secondProbe->subjects[0]['namespace']);
        self::assertSame('onlyHere', $secondProbe->subjects[0]['member']);
    }

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
        $registrar = (new DeclarationRegistrarFactory())->createForFile();
        $traverser->addVisitor($registrar);
        $visitor->useDeclarationIndex($registrar->index());
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

        self::assertSame(4, $byKind['function'][0]->sourceLine);
        self::assertSame(9, $hook->sourceLine);
        self::assertSame(12, $method->sourceLine);

        $anonymousBySyntax = [];
        foreach ($byKind['anonymous-callable'] as $anonymousCallable) {
            $anonymousBySyntax[$anonymousCallable->anonymousSyntax] = $anonymousCallable;
        }
        self::assertSame(14, $anonymousBySyntax['closure']->sourceLine);
        self::assertSame(15, $anonymousBySyntax['arrow']->sourceLine);

        $repository = new InMemoryMetricRepository();
        foreach ($callables as $collectedCallable) {
            $repository->addCallable($collectedCallable);
        }

        $linesByDeclaration = [];
        foreach ($repository->allCallables() as $info) {
            $linesByDeclaration[$info->subject?->toCanonical() ?? ''] = $info->line;
        }
        foreach ($callables as $collectedCallable) {
            self::assertSame(
                $collectedCallable->sourceLine,
                $linesByDeclaration[$collectedCallable->declarationPath->toCanonical()],
            );
        }
    }

    #[Test]
    public function itDoesNotOverwriteDuplicateLogicalDeclarations(): void
    {
        $visitor = new CyclomaticComplexityVisitor();
        $traverser = new NodeTraverser();
        $registrar = (new DeclarationRegistrarFactory())->createForFile();
        $traverser->addVisitor($registrar);
        $visitor->useDeclarationIndex($registrar->index());
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
        self::assertNotSame($callables[0]->declarationPath->ordinal->value, $callables[1]->declarationPath->ordinal->value);
        self::assertNotSame($callables[0]->declarationPath->toCanonical(), $callables[1]->declarationPath->toCanonical());
        self::assertSame(1, $callables[0]->metrics->get('complexity.ccn'));
        self::assertSame(2, $callables[1]->metrics->get('complexity.ccn'));
    }
}

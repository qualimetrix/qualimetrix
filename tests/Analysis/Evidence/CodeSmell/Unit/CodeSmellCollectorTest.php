<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CodeSmell\Unit;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\CodeSmellCollector;
use Qualimetrix\Analysis\Evidence\Complexity\CyclomaticComplexityVisitor;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubjectCodec;
use SplFileInfo;

#[CoversClass(CodeSmellCollector::class)]
final class CodeSmellCollectorTest extends TestCase
{
    private CodeSmellCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new CodeSmellCollector();
    }

    #[Test]
    public function itReturnsCollectorName(): void
    {
        self::assertSame('code-smell', $this->collector->getName());
    }

    #[Test]
    public function itProvidesExpectedMetricKeys(): void
    {
        $provides = $this->collector->provides();

        self::assertContains('codeSmell.goto', $provides);
        self::assertContains('codeSmell.eval', $provides);
        self::assertContains('codeSmell.exit', $provides);
        self::assertContains('codeSmell.empty_catch', $provides);
        self::assertContains('codeSmell.debug_code', $provides);
        self::assertContains('codeSmell.error_suppression', $provides);
        self::assertContains('codeSmell.count_in_loop', $provides);
        self::assertContains('codeSmell.superglobals', $provides);
        self::assertContains('codeSmell.boolean_argument', $provides);
        self::assertCount(9, $provides);
    }

    #[Test]
    public function itCollectsNoSmellsForEmptyInput(): void
    {
        $code = '<?php // nothing here';

        $bag = $this->collectMetrics($code);

        foreach (CodeSmellCollector::SMELL_TYPES as $type) {
            self::assertSame([], $bag->entries("codeSmell.{$type}"));
        }
    }

    #[Test]
    public function itCollectsSingleSmellEntry(): void
    {
        $code = '<?php eval("echo 1;");';

        $bag = $this->collectMetrics($code);

        $evalEntries = $bag->entries('codeSmell.eval');
        self::assertCount(1, $evalEntries);
        self::assertArrayHasKey('line', $evalEntries[0]);
    }

    #[Test]
    public function itTransportsTheExactNamedCallableSubjectAsScalars(): void
    {
        $bag = $this->collectMetrics('<?php namespace App; class Example { public function run(bool $enabled): void {} }');
        $entry = $bag->entries('codeSmell.boolean_argument')[0];

        self::assertSame('declaration', $entry['subjectKind']);
        self::assertSame('method', $entry['logicalKind']);
        self::assertSame('App', $entry['namespace']);
        self::assertSame('Example', $entry['class']);
        self::assertSame('run', $entry['member']);
        self::assertIsInt($entry['startFilePos']);
    }

    #[Test]
    public function itKeepsClosureTraversalOrdinalsAlignedAfterAnAnonymousClassClosure(): void
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse(<<<'PHP'
<?php
$anonymous = new class {
    public function hidden(): void { $skip = static fn(bool $secret) => null; }
};
$owned = static fn(bool $password) => null;
PHP) ?? [];
        $callableVisitor = new CyclomaticComplexityVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->collector->getVisitor());
        $traverser->addVisitor($callableVisitor);
        $traverser->traverse($ast);

        $bag = $this->collector->collect(new SplFileInfo(__FILE__), $ast);
        $entry = $bag->entries('codeSmell.boolean_argument')[1];
        $file = RelativePath::fromString('src/Example.php');
        $decoded = MetricSubjectCodec::decode(
            [
                'subjectKind' => (string) $entry['subjectKind'],
                'logicalKind' => (string) $entry['logicalKind'],
                'namespace' => (string) $entry['namespace'],
                'member' => (string) $entry['member'],
                'startFilePos' => (int) $entry['startFilePos'],
            ],
            $file,
        );
        $callables = $callableVisitor->getCallablesWithMetrics($file);

        self::assertCount(3, $callables);
        self::assertSame($callables[array_key_last($callables)]->declarationPath->toCanonical(), $decoded->toCanonical());
    }

    #[Test]
    public function itTransportsPropertyHookAndOrdinaryOrPromotedConstructorParametersAsDeclarations(): void
    {
        $bag = $this->collectMetrics(<<<'PHP'
<?php
namespace App;
class Example {
    public string $value { get { var_dump($this->value); } }
    public function __construct(bool $ordinary, public bool $promoted) {}
}
PHP);
        $hook = $bag->entries('codeSmell.debug_code')[0];
        $parameters = $bag->entries('codeSmell.boolean_argument');

        self::assertSame('declaration', $hook['subjectKind']);
        self::assertSame('value::get', $hook['member']);
        self::assertSame('declaration', $parameters[0]['subjectKind']);
        self::assertSame('__construct', $parameters[0]['member']);
        self::assertSame('__construct', $parameters[1]['member']);
        self::assertSame(false, $parameters[0]['promoted']);
        self::assertSame(true, $parameters[1]['promoted']);
    }

    #[Test]
    public function itCollectsMultipleDistinctSmells(): void
    {
        $code = <<<'PHP'
<?php

eval("code");
@file_get_contents("url");
goto end;
end:
echo "done";
PHP;

        $bag = $this->collectMetrics($code);

        self::assertCount(1, $bag->entries('codeSmell.eval'));
        self::assertCount(1, $bag->entries('codeSmell.error_suppression'));
        self::assertCount(1, $bag->entries('codeSmell.goto'));
    }

    #[Test]
    public function itCollectsMultipleSmellsOfSameType(): void
    {
        $code = <<<'PHP'
<?php

eval("one");
eval("two");
eval("three");
PHP;

        $bag = $this->collectMetrics($code);

        self::assertCount(3, $bag->entries('codeSmell.eval'));
    }

    #[Test]
    public function itIncludesExtraFieldInSmellEntry(): void
    {
        $code = <<<'PHP'
<?php

var_dump($x);
PHP;

        $bag = $this->collectMetrics($code);

        $entries = $bag->entries('codeSmell.debug_code');
        self::assertCount(1, $entries);
        self::assertArrayHasKey('extra', $entries[0]);
        self::assertSame('var_dump', $entries[0]['extra']);
    }

    #[Test]
    public function itCollectsSuperglobalSmells(): void
    {
        $code = <<<'PHP'
<?php

$x = $_GET['id'];
$y = $_POST['data'];
PHP;

        $bag = $this->collectMetrics($code);

        $entries = $bag->entries('codeSmell.superglobals');
        self::assertCount(2, $entries);
    }

    #[Test]
    public function itClearsStateOnReset(): void
    {
        $code1 = '<?php eval("code");';
        $this->collectMetrics($code1);

        $this->collector->reset();

        $code2 = '<?php // clean code';
        $bag = $this->collectMetrics($code2);

        self::assertCount(0, $bag->entries('codeSmell.eval'));
    }

    #[Test]
    public function itDeliberatelyDoesNotProvideCallableMetrics(): void
    {
        self::assertNotContains(\Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableMetricsProviderInterface::class, class_implements($this->collector));
    }

    private function collectMetrics(string $code): \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->collector->getVisitor());
        $traverser->traverse($ast);

        return $this->collector->collect(new SplFileInfo(__FILE__), $ast);
    }
}

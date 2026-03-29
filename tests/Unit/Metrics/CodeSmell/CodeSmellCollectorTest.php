<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics\CodeSmell;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Metrics\CodeSmell\CodeSmellCollector;
use SplFileInfo;

#[CoversClass(CodeSmellCollector::class)]
final class CodeSmellCollectorTest extends TestCase
{
    private CodeSmellCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new CodeSmellCollector();
    }

    public function testGetName(): void
    {
        self::assertSame('code-smell', $this->collector->getName());
    }

    public function testProvides(): void
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

    public function testCollectWithEmptyInput(): void
    {
        $code = '<?php // nothing here';

        $bag = $this->collectMetrics($code);

        foreach (CodeSmellCollector::SMELL_TYPES as $type) {
            self::assertSame([], $bag->entries("codeSmell.{$type}"));
        }
    }

    public function testCollectWithSingleSmell(): void
    {
        $code = '<?php eval("echo 1;");';

        $bag = $this->collectMetrics($code);

        $evalEntries = $bag->entries('codeSmell.eval');
        self::assertCount(1, $evalEntries);
        self::assertArrayHasKey('line', $evalEntries[0]);
    }

    public function testCollectWithMultipleSmells(): void
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

    public function testCollectWithMultipleSmellsOfSameType(): void
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

    public function testCollectWithExtraField(): void
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

    public function testCollectWithSuperglobal(): void
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

    public function testResetClearsState(): void
    {
        $code1 = '<?php eval("code");';
        $this->collectMetrics($code1);

        $this->collector->reset();

        $code2 = '<?php // clean code';
        $bag = $this->collectMetrics($code2);

        self::assertCount(0, $bag->entries('codeSmell.eval'));
    }

    private function collectMetrics(string $code): \Qualimetrix\Core\Metric\MetricBag
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->collector->getVisitor());
        $traverser->traverse($ast);

        return $this->collector->collect(new SplFileInfo(__FILE__), $ast);
    }
}

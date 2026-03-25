<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics\Structure;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Metrics\Structure\InheritanceDepthCollector;
use Qualimetrix\Metrics\Structure\InheritanceDepthVisitor;
use SplFileInfo;

/**
 * Tests the behavior of InheritanceDepthVisitor with `use` imports and aliases.
 *
 * The visitor now tracks `use` statements and resolves imported names correctly.
 */
#[CoversClass(InheritanceDepthCollector::class)]
#[CoversClass(InheritanceDepthVisitor::class)]
final class InheritanceDepthUseAliasTest extends TestCase
{
    #[Test]
    public function aliasedParentFromDifferentNamespaceIsResolvedCorrectly(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

use Vendor\Base as BaseAlias;

class ChildClass extends BaseAlias {}
PHP;

        $metrics = $this->collectMetrics($code);

        // The visitor resolves "BaseAlias" via the use import to "Vendor\Base".
        // Since "Vendor\Base" is not a known class in the file, DIT = 1
        // (extends unknown external class).
        $dit = $metrics->get('dit:App\ChildClass');
        self::assertIsInt($dit);
        self::assertSame(1, $dit);
    }

    #[Test]
    public function visitorResolvesAliasViaUseImport(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

use Vendor\Base as BaseAlias;

class ChildClass extends BaseAlias {}
PHP;

        $collector = new InheritanceDepthCollector();

        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector->getVisitor());
        $traverser->traverse($ast);

        // Verify what FQN the visitor resolves for the parent
        $visitor = $collector->getVisitor();
        \assert($visitor instanceof InheritanceDepthVisitor);

        $classParents = $visitor->getClassParents();

        // Now correctly resolves to "Vendor\Base" via use import
        self::assertArrayHasKey('App\ChildClass', $classParents);
        self::assertSame('Vendor\Base', $classParents['App\ChildClass']);
    }

    #[Test]
    public function aliasedParentInSameFileIsLinkedCorrectly(): void
    {
        // Parent class is in the same file, the alias correctly links the chain
        $code = <<<'PHP'
<?php

namespace Vendor;

class Base {}

namespace App;

use Vendor\Base as BaseAlias;

class ChildClass extends BaseAlias {}
PHP;

        $collector = new InheritanceDepthCollector();

        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector->getVisitor());
        $traverser->traverse($ast);

        $visitor = $collector->getVisitor();
        \assert($visitor instanceof InheritanceDepthVisitor);

        $classParents = $visitor->getClassParents();

        // Vendor\Base has DIT 0 (no parent)
        self::assertNull($classParents['Vendor\Base']);

        // App\ChildClass now correctly links to Vendor\Base via the use import
        self::assertSame('Vendor\Base', $classParents['App\ChildClass']);

        // Collect metrics to verify DIT
        $metrics = $collector->collect(new SplFileInfo(__FILE__), $ast);

        // Vendor\Base has DIT 0
        self::assertSame(0, $metrics->get('dit:Vendor\Base'));

        // App\ChildClass correctly gets DIT 1 (extends Vendor\Base which has DIT 0)
        self::assertSame(1, $metrics->get('dit:App\ChildClass'));
    }

    #[Test]
    public function fullyQualifiedExtendsWorksCorrectly(): void
    {
        // Contrast: fully qualified name works perfectly
        $code = <<<'PHP'
<?php

namespace App;

class ChildClass extends \Exception {}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('dit:App\ChildClass'));
    }

    #[Test]
    public function simpleUseImportWithoutAlias(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

use Vendor\Base;

class ChildClass extends Base {}
PHP;

        $collector = new InheritanceDepthCollector();

        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector->getVisitor());
        $traverser->traverse($ast);

        $visitor = $collector->getVisitor();
        \assert($visitor instanceof InheritanceDepthVisitor);

        $classParents = $visitor->getClassParents();

        // "Base" resolves to "Vendor\Base" via use import
        self::assertSame('Vendor\Base', $classParents['App\ChildClass']);
    }

    #[Test]
    public function useImportWithSameFileParent(): void
    {
        // Inheritance chain across namespaces in the same file
        $code = <<<'PHP'
<?php

namespace Vendor;

class GrandParent_ {}
class Base extends GrandParent_ {}

namespace App;

use Vendor\Base;

class ChildClass extends Base {}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('dit:Vendor\GrandParent_'));
        self::assertSame(1, $metrics->get('dit:Vendor\Base'));
        // ChildClass -> Vendor\Base (DIT 1) -> Vendor\GrandParent_ (DIT 0) = DIT 2
        self::assertSame(2, $metrics->get('dit:App\ChildClass'));
    }

    private function collectMetrics(string $code): MetricBag
    {
        $collector = new InheritanceDepthCollector();

        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector->getVisitor());
        $traverser->traverse($ast);

        return $collector->collect(new SplFileInfo(__FILE__), $ast);
    }
}

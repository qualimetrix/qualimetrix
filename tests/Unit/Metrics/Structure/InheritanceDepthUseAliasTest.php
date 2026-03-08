<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics\Structure;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Metrics\Structure\InheritanceDepthCollector;
use AiMessDetector\Metrics\Structure\InheritanceDepthVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Tests the behavior of InheritanceDepthVisitor with `use ... as ...` aliases.
 *
 * KNOWN LIMITATION: The visitor's resolveClassName() method does not track
 * `use` import statements or aliases. When a class extends an alias like
 * `BaseAlias`, the visitor resolves it as `{currentNamespace}\BaseAlias`
 * instead of the actual imported FQN. This means:
 * - For aliased parents in a different namespace, DIT may be incorrectly 0
 *   (parent not found in current file's classParents map).
 * - For aliased parents in the same file, the alias won't match the
 *   actual class FQN stored in classParents.
 *
 * php-parser's NameResolver visitor could fix this but is not currently used.
 */
#[CoversClass(InheritanceDepthCollector::class)]
#[CoversClass(InheritanceDepthVisitor::class)]
final class InheritanceDepthUseAliasTest extends TestCase
{
    #[Test]
    public function aliasedParentFromDifferentNamespaceIsNotResolvedCorrectly(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

use Vendor\Base as BaseAlias;

class ChildClass extends BaseAlias {}
PHP;

        $metrics = $this->collectMetrics($code);

        // The visitor resolves "BaseAlias" as "App\BaseAlias" (prepends current namespace)
        // instead of "Vendor\Base" (the actual imported class).
        // Since "App\BaseAlias" is not a known class, DIT falls back to 1
        // (extends unknown external class = 1 + 0 from resolveExternalClassDit).
        $dit = $metrics->get('dit:App\ChildClass');
        self::assertIsInt($dit);

        // Documents current behavior: DIT = 1 because parent "App\BaseAlias" is
        // treated as an unknown external class (not in classParents map, not autoloadable).
        self::assertSame(1, $dit);
    }

    #[Test]
    public function visitorResolvesAliasAsCurrentNamespacePrefixed(): void
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

        // Documents the limitation: parent FQN is "App\BaseAlias" not "Vendor\Base"
        self::assertArrayHasKey('App\ChildClass', $classParents);
        self::assertSame('App\BaseAlias', $classParents['App\ChildClass']);
    }

    #[Test]
    public function aliasedParentInSameFileIsNotLinkedCorrectly(): void
    {
        // Even if the parent class is in the same file, the alias breaks the chain
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

        // App\ChildClass should link to Vendor\Base, but due to the alias limitation
        // it links to App\BaseAlias which doesn't exist in the map
        self::assertSame('App\BaseAlias', $classParents['App\ChildClass']);

        // Collect metrics to verify DIT
        $metrics = $collector->collect(new SplFileInfo(__FILE__), $ast);

        // Vendor\Base has DIT 0
        self::assertSame(0, $metrics->get('dit:Vendor\Base'));

        // App\ChildClass gets DIT 1 (unknown external parent) instead of the correct DIT 1
        // (which coincidentally is the same value here, but for the wrong reason)
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

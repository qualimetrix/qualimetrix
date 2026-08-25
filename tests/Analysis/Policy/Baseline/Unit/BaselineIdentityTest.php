<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEdge;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Finding\Support\FindingFactory;

#[CoversClass(BaselineIdentity::class)]
#[CoversClass(BaselineEdge::class)]
final class BaselineIdentityTest extends TestCase
{
    #[Test]
    public function itTakesTheExactSubjectAndChannelOfAFinding(): void
    {
        $identity = BaselineIdentity::forFinding(
            FindingFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 15),
        );

        self::assertSame('declaration:callable:App\Foo::bar@src/Foo.php', $identity->subjectKey);
        self::assertSame('complexity.cyclomatic.callable', $identity->channel->code);
        self::assertNull($identity->edge);
    }

    #[Test]
    public function itCarriesTheDependencyEdgeWhenTheFindingHasOne(): void
    {
        $identity = BaselineIdentity::forFinding(FindingFactory::edge(
            SymbolPath::forClass('App\Web', 'Controller'),
            SymbolPath::forClass('App\Db', 'Connection'),
        ));

        self::assertNotNull($identity->edge);
        self::assertSame('class:App\Db\Connection', $identity->edge->target);
        self::assertSame(DependencyType::New_, $identity->edge->type);
    }

    /**
     * Without the edge, swapping one forbidden dependency for another leaves
     * the group's count unchanged and the swap passes in silence.
     */
    #[Test]
    public function itSeparatesTwoEdgesOutOfOneClassOnOneChannel(): void
    {
        $source = SymbolPath::forClass('App\Web', 'Controller');

        $first = BaselineIdentity::forFinding(
            FindingFactory::edge($source, SymbolPath::forClass('App\Db', 'Connection')),
        );
        $second = BaselineIdentity::forFinding(
            FindingFactory::edge($source, SymbolPath::forClass('App\Db', 'Statement')),
        );

        self::assertNotSame($first->key(), $second->key());
        self::assertNotSame($first->selector()->value, $second->selector()->value);
    }

    #[Test]
    public function itSeparatesTwoEdgeTypesToOneTarget(): void
    {
        $source = SymbolPath::forClass('App\Web', 'Controller');
        $target = SymbolPath::forClass('App\Db', 'Connection');

        self::assertNotSame(
            BaselineIdentity::forFinding(FindingFactory::edge($source, $target, DependencyType::New_))->key(),
            BaselineIdentity::forFinding(FindingFactory::edge($source, $target, DependencyType::TypeHint))->key(),
        );
    }

    #[Test]
    public function itRetainsTargetOnlyEdgesAndSeparatesThemFromTypedEdges(): void
    {
        $source = SymbolPath::forClass('App\\Web', 'Controller');
        $alpha = self::targetOnlyEdge($source, SymbolPath::forClass('App', 'Alpha'));
        $beta = self::targetOnlyEdge($source, SymbolPath::forClass('App', 'Beta'));
        $typedAlpha = FindingFactory::edge($source, SymbolPath::forClass('App', 'Alpha'));

        $alphaIdentity = BaselineIdentity::forFinding($alpha);

        self::assertNotNull($alphaIdentity->edge);
        self::assertSame('class:App\\Alpha', $alphaIdentity->edge->target);
        self::assertNull($alphaIdentity->edge->type);
        self::assertNotSame($alphaIdentity->key(), BaselineIdentity::forFinding($beta)->key());
        self::assertNotSame($alphaIdentity->key(), BaselineIdentity::forFinding($typedAlpha)->key());
    }

    #[Test]
    public function itSeparatesTwoChannelsOnOneSymbol(): void
    {
        $symbol = SymbolPath::forMethod('App', 'Foo', 'bar');

        self::assertNotSame(
            (new BaselineIdentity($symbol->toCanonical(), new FindingChannel('a.code')))->key(),
            (new BaselineIdentity($symbol->toCanonical(), new FindingChannel('other.code')))->key(),
        );
    }

    private static function targetOnlyEdge(SymbolPath $source, SymbolPath $target): Finding
    {
        return new Finding(
            location: new Location(RelativePath::fromString('src/Foo.php'), 11),
            subject: MetricSubject::declaration(DeclarationPath::of($source, RelativePath::fromString('src/Foo.php'), DeclarationOrdinal::fromRank(0))),
            symbolPath: $source,
            ruleName: 'architecture.layer-violation',
            code: 'architecture.layer-violation',
            message: 'untyped forbidden dependency',
            severity: Severity::Error,
            dependencyTarget: $target,
        );
    }

    #[Test]
    public function itSeparatesSemanticOccurrencesAtTheSameSubject(): void
    {
        $symbol = SymbolPath::forMethod('App', 'Foo', 'bar');
        $subject = MetricSubject::declaration(DeclarationPath::of($symbol, RelativePath::fromString('src/Foo.php'), DeclarationOrdinal::fromRank(0)));

        $first = new Finding(
            location: new Location(RelativePath::fromString('src/Foo.php'), 20),
            subject: $subject,
            symbolPath: $symbol,
            ruleName: 'code-smell.debug-code',
            code: 'code-smell.debug-code',
            message: 'first presentation',
            severity: Severity::Warning,
            occurrenceKey: OccurrenceKey::semantic('debug-code', ['kind' => 'var_dump']),
        );
        $second = new Finding(
            location: new Location(RelativePath::fromString('src/Foo.php'), 200),
            subject: $subject,
            symbolPath: $symbol,
            ruleName: 'code-smell.debug-code',
            code: 'code-smell.debug-code',
            message: 'second presentation',
            severity: Severity::Warning,
            occurrenceKey: OccurrenceKey::semantic('debug-code', ['kind' => 'print_r']),
        );

        self::assertNotSame(
            BaselineIdentity::forFinding($first)->key(),
            BaselineIdentity::forFinding($second)->key(),
        );
    }

    #[Test]
    public function itRejectsAnEmptySubjectKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BaselineIdentity('', new FindingChannel('a.code'));
    }

    /**
     * Exact declaration subjects deliberately preserve same-FQN collisions.
     */
    #[Test]
    public function itSeparatesTwoSameFqnDeclarations(): void
    {
        $symbol = SymbolPath::forMethod('App', 'Duplicated', 'run');

        $fromFirstFile = new Finding(
            location: new Location(RelativePath::fromString('src/a/Duplicated.php'), 10),
            subject: MetricSubject::declaration(DeclarationPath::of($symbol, RelativePath::fromString('src/a/Duplicated.php'), DeclarationOrdinal::fromRank(0))),
            symbolPath: $symbol,
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic.callable',
            message: 'from the first declaration',
            severity: Severity::Warning,
            metricValue: 12,
        );
        $fromSecondFile = new Finding(
            location: new Location(RelativePath::fromString('src/b/Duplicated.php'), 10),
            subject: MetricSubject::declaration(DeclarationPath::of($symbol, RelativePath::fromString('src/b/Duplicated.php'), DeclarationOrdinal::fromRank(0))),
            symbolPath: $symbol,
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic.callable',
            message: 'from the second declaration',
            severity: Severity::Warning,
            metricValue: 30,
        );

        self::assertNotSame(
            BaselineIdentity::forFinding($fromFirstFile)->key(),
            BaselineIdentity::forFinding($fromSecondFile)->key(),
        );
    }

    /**
     * ADR 0017 also records the aggregation-level consequence: the namespace strategy
     * and the aggregation prefixes both change which namespace a symbol is
     * reported under with no code change — but both act before the key is
     * formed, so a namespace-level entry stays unambiguous, and a parent
     * namespace introduced by a prefix is a different symbol from its
     * children rather than a merged group.
     */
    #[Test]
    public function itKeepsNamespaceLevelsApartAcrossAggregationDepths(): void
    {
        $channel = new FindingChannel('coupling.cbo.namespace');

        $parent = new BaselineIdentity(SymbolPath::forNamespace('App')->toCanonical(), $channel);
        $child = new BaselineIdentity(SymbolPath::forNamespace('App\Service')->toCanonical(), $channel);

        self::assertSame('ns:App', $parent->subjectKey);
        self::assertSame('ns:App\Service', $child->subjectKey);
        self::assertNotSame($parent->key(), $child->key());
    }

    /**
     * A pre-existing `SymbolPath` defect, independent of the baseline and
     * pinned here so it is not rediscovered as a baseline bug: the project
     * sentinel is a legal PHP namespace name, so a namespace literally
     * called `__PROJECT__` canonicalizes to the project key.
     */
    #[Test]
    public function itInheritsTheProjectSentinelCollisionFromSymbolPath(): void
    {
        self::assertSame(
            SymbolPath::forProject()->toCanonical(),
            SymbolPath::forNamespace('__PROJECT__')->toCanonical(),
        );
    }

    #[Test]
    public function itDescribesItselfWithSymbolChannelAndEdge(): void
    {
        $identity = BaselineIdentity::forFinding(FindingFactory::edge(
            SymbolPath::forClass('App\Web', 'Controller'),
            SymbolPath::forClass('App\Db', 'Connection'),
        ));

        self::assertSame(
            'declaration:class:App\Web\Controller@src/Foo.php architecture.layer-violation'
            . ' -> class:App\Db\Connection (new)',
            $identity->describe(),
        );
    }
}

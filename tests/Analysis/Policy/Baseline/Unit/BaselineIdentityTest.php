<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEdge;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Finding\Support\ViolationFactory;

#[CoversClass(BaselineIdentity::class)]
#[CoversClass(BaselineEdge::class)]
final class BaselineIdentityTest extends TestCase
{
    #[Test]
    public function itTakesTheExactSubjectAndChannelOfAViolation(): void
    {
        $identity = BaselineIdentity::forViolation(
            ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 15),
        );

        self::assertSame('declaration:callable:App\Foo::bar@src/Foo.php:42', $identity->subjectKey);
        self::assertSame('complexity.cyclomatic#complexity.cyclomatic.callable', $identity->channel->toKey());
        self::assertNull($identity->edge);
    }

    #[Test]
    public function itCarriesTheDependencyEdgeWhenTheFindingHasOne(): void
    {
        $identity = BaselineIdentity::forViolation(ViolationFactory::edge(
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

        $first = BaselineIdentity::forViolation(
            ViolationFactory::edge($source, SymbolPath::forClass('App\Db', 'Connection')),
        );
        $second = BaselineIdentity::forViolation(
            ViolationFactory::edge($source, SymbolPath::forClass('App\Db', 'Statement')),
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
            BaselineIdentity::forViolation(ViolationFactory::edge($source, $target, DependencyType::New_))->key(),
            BaselineIdentity::forViolation(ViolationFactory::edge($source, $target, DependencyType::TypeHint))->key(),
        );
    }

    #[Test]
    public function itRetainsTargetOnlyEdgesAndSeparatesThemFromTypedEdges(): void
    {
        $source = SymbolPath::forClass('App\\Web', 'Controller');
        $alpha = self::targetOnlyEdge($source, SymbolPath::forClass('App', 'Alpha'));
        $beta = self::targetOnlyEdge($source, SymbolPath::forClass('App', 'Beta'));
        $typedAlpha = ViolationFactory::edge($source, SymbolPath::forClass('App', 'Alpha'));

        $alphaIdentity = BaselineIdentity::forViolation($alpha);

        self::assertNotNull($alphaIdentity->edge);
        self::assertSame('class:App\\Alpha', $alphaIdentity->edge->target);
        self::assertNull($alphaIdentity->edge->type);
        self::assertNotSame($alphaIdentity->key(), BaselineIdentity::forViolation($beta)->key());
        self::assertNotSame($alphaIdentity->key(), BaselineIdentity::forViolation($typedAlpha)->key());
    }

    #[Test]
    public function itSeparatesTwoChannelsOnOneSymbol(): void
    {
        $symbol = SymbolPath::forMethod('App', 'Foo', 'bar');

        self::assertNotSame(
            (new BaselineIdentity($symbol->toCanonical(), new ViolationChannel('a.rule', 'a.code')))->key(),
            (new BaselineIdentity($symbol->toCanonical(), new ViolationChannel('a.rule', 'other.code')))->key(),
        );
    }

    private static function targetOnlyEdge(SymbolPath $source, SymbolPath $target): Violation
    {
        return new Violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 11),
            subject: MetricSubject::declaration(new DeclarationPath(
                $source,
                RelativePath::fromString('src/Foo.php'),
                11,
            )),
            symbolPath: $source,
            ruleName: 'architecture.layer-violation',
            violationCode: 'architecture.layer-violation',
            message: 'untyped forbidden dependency',
            severity: Severity::Error,
            dependencyTarget: $target,
        );
    }

    #[Test]
    public function itSeparatesSemanticOccurrencesAtTheSameSubject(): void
    {
        $symbol = SymbolPath::forMethod('App', 'Foo', 'bar');
        $subject = MetricSubject::declaration(new DeclarationPath($symbol, RelativePath::fromString('src/Foo.php'), 10));

        $first = new Violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 20),
            subject: $subject,
            symbolPath: $symbol,
            ruleName: 'code-smell.debug-code',
            violationCode: 'code-smell.debug-code',
            message: 'first presentation',
            severity: Severity::Warning,
            occurrenceKey: OccurrenceKey::semantic('debug-code', ['kind' => 'var_dump']),
        );
        $second = new Violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 200),
            subject: $subject,
            symbolPath: $symbol,
            ruleName: 'code-smell.debug-code',
            violationCode: 'code-smell.debug-code',
            message: 'second presentation',
            severity: Severity::Warning,
            occurrenceKey: OccurrenceKey::semantic('debug-code', ['kind' => 'print_r']),
        );

        self::assertNotSame(
            BaselineIdentity::forViolation($first)->key(),
            BaselineIdentity::forViolation($second)->key(),
        );
    }

    #[Test]
    public function itRejectsAnEmptySubjectKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BaselineIdentity('', new ViolationChannel('a.rule', 'a.code'));
    }

    /**
     * Exact declaration subjects deliberately preserve same-FQN collisions.
     */
    #[Test]
    public function itSeparatesTwoSameFqnDeclarations(): void
    {
        $symbol = SymbolPath::forMethod('App', 'Duplicated', 'run');

        $fromFirstFile = new Violation(
            location: new Location(RelativePath::fromString('src/a/Duplicated.php'), 10),
            subject: MetricSubject::declaration(new DeclarationPath(
                $symbol,
                RelativePath::fromString('src/a/Duplicated.php'),
                100,
            )),
            symbolPath: $symbol,
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.callable',
            message: 'from the first declaration',
            severity: Severity::Warning,
            metricValue: 12,
        );
        $fromSecondFile = new Violation(
            location: new Location(RelativePath::fromString('src/b/Duplicated.php'), 10),
            subject: MetricSubject::declaration(new DeclarationPath(
                $symbol,
                RelativePath::fromString('src/b/Duplicated.php'),
                100,
            )),
            symbolPath: $symbol,
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.callable',
            message: 'from the second declaration',
            severity: Severity::Warning,
            metricValue: 30,
        );

        self::assertNotSame(
            BaselineIdentity::forViolation($fromFirstFile)->key(),
            BaselineIdentity::forViolation($fromSecondFile)->key(),
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
        $channel = new ViolationChannel('coupling.cbo', 'coupling.cbo.namespace');

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
        $identity = BaselineIdentity::forViolation(ViolationFactory::edge(
            SymbolPath::forClass('App\Web', 'Controller'),
            SymbolPath::forClass('App\Db', 'Connection'),
        ));

        self::assertSame(
            'declaration:class:App\Web\Controller@src/Foo.php:11 architecture.layer-violation#architecture.layer-violation'
            . ' -> class:App\Db\Connection (new)',
            $identity->describe(),
        );
    }
}

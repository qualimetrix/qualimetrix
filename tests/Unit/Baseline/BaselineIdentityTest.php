<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Baseline;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Baseline\BaselineEdge;
use Qualimetrix\Baseline\BaselineIdentity;
use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Core\Violation\ViolationChannel;
use Qualimetrix\Tests\Support\Violation\ViolationFactory;

#[CoversClass(BaselineIdentity::class)]
#[CoversClass(BaselineEdge::class)]
final class BaselineIdentityTest extends TestCase
{
    #[Test]
    public function itTakesTheSymbolAndChannelOfAViolation(): void
    {
        $identity = BaselineIdentity::forViolation(
            ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 15),
        );

        self::assertSame('callable:App\Foo::bar', $identity->symbolKey);
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
    public function itSeparatesTwoChannelsOnOneSymbol(): void
    {
        $symbol = SymbolPath::forMethod('App', 'Foo', 'bar');

        self::assertNotSame(
            (new BaselineIdentity($symbol->toCanonical(), new ViolationChannel('a.rule', 'a.code')))->key(),
            (new BaselineIdentity($symbol->toCanonical(), new ViolationChannel('a.rule', 'other.code')))->key(),
        );
    }

    #[Test]
    public function itRejectsAnEmptySymbolKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BaselineIdentity('', new ViolationChannel('a.rule', 'a.code'));
    }

    /**
     * ADR 0017, decided in P2: symbol keys are names, not locations, and the
     * collisions that follow are accepted rather than discriminated away.
     *
     * Two declarations of one FQN — which PHP itself cannot load — produce
     * one identity, so their findings form one group whose ceiling bounds
     * their sum. That errs toward reporting: a member added to either
     * declaration breaches.
     */
    #[Test]
    public function itMergesTwoSameFqnDeclarationsIntoOneIdentity(): void
    {
        $symbol = SymbolPath::forMethod('App', 'Duplicated', 'run');

        $fromFirstFile = new Violation(
            location: new Location(RelativePath::fromString('src/a/Duplicated.php'), 10),
            symbolPath: $symbol,
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.callable',
            message: 'from the first declaration',
            severity: Severity::Warning,
            metricValue: 12,
        );
        $fromSecondFile = new Violation(
            location: new Location(RelativePath::fromString('src/b/Duplicated.php'), 10),
            symbolPath: $symbol,
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.callable',
            message: 'from the second declaration',
            severity: Severity::Warning,
            metricValue: 30,
        );

        self::assertSame(
            BaselineIdentity::forViolation($fromFirstFile)->key(),
            BaselineIdentity::forViolation($fromSecondFile)->key(),
            'The file a finding was reported from is not part of the identity.',
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

        self::assertSame('ns:App', $parent->symbolKey);
        self::assertSame('ns:App\Service', $child->symbolKey);
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
            'class:App\Web\Controller architecture.layer-violation#architecture.layer-violation'
            . ' -> class:App\Db\Connection (new)',
            $identity->describe(),
        );
    }
}

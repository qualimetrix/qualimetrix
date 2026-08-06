<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Baseline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Baseline\BaselineEntry;
use Qualimetrix\Baseline\BaselineEntryMode;
use Qualimetrix\Baseline\BaselineEntryParser;
use Qualimetrix\Baseline\InertBaselineEntry;
use Qualimetrix\Baseline\InertEntryReason;
use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Tests\Support\Violation\StubChannelDeclarationRegistry;

/**
 * One case per ambiguity of the governing invariant: an entry the mechanism
 * cannot apply must not suppress, and must say why.
 */
#[CoversClass(BaselineEntryParser::class)]
#[CoversClass(InertBaselineEntry::class)]
final class BaselineEntryParserTest extends TestCase
{
    private BaselineEntryParser $parser;

    protected function setUp(): void
    {
        $this->parser = new BaselineEntryParser(StubChannelDeclarationRegistry::withDefaults());
    }

    #[Test]
    public function itParsesAMagnitudeEntry(): void
    {
        $entry = $this->parser->parse('method:App\Foo::bar', [
            'channel' => 'complexity.cyclomatic#complexity.cyclomatic.method',
            'magnitudes' => [25],
            'count' => 1,
        ]);

        self::assertInstanceOf(BaselineEntry::class, $entry);
        self::assertSame([25.0], $entry->magnitudes);
        self::assertSame(1, $entry->count);
    }

    #[Test]
    public function itParsesAnOccurrenceEntryWithAnEdge(): void
    {
        $entry = $this->parser->parse('class:App\Web\Controller', [
            'channel' => 'architecture.layer-violation#architecture.layer-violation',
            'edge' => ['target' => 'class:App\Db\Connection', 'type' => 'new'],
            'count' => 1,
        ]);

        self::assertInstanceOf(BaselineEntry::class, $entry);
        self::assertNotNull($entry->identity->edge);
        self::assertSame(DependencyType::New_, $entry->identity->edge->type);
    }

    #[Test]
    public function itParsesTheSuppressMode(): void
    {
        $entry = $this->parser->parse('method:App\Foo::bar', [
            'channel' => 'code-smell.goto#code-smell.goto',
            'count' => 2,
            'mode' => 'suppress',
        ]);

        self::assertInstanceOf(BaselineEntry::class, $entry);
        self::assertSame(BaselineEntryMode::Suppress, $entry->mode);
    }

    #[Test]
    public function itTurnsAnEntryThatIsNotAnObjectInert(): void
    {
        $entry = $this->parser->parse('method:App\Foo::bar', 'not an object');

        self::assertInertFor($entry, InertEntryReason::Malformed);
    }

    #[Test]
    public function itTurnsAnEntryWithoutAChannelInert(): void
    {
        $entry = $this->parser->parse('method:App\Foo::bar', ['count' => 1]);

        self::assertInertFor($entry, InertEntryReason::Malformed);
    }

    #[Test]
    public function itTurnsAnEntryWithAnUnparseableChannelInert(): void
    {
        $entry = $this->parser->parse('method:App\Foo::bar', ['channel' => 'no-separator', 'count' => 1]);

        self::assertInertFor($entry, InertEntryReason::Malformed);
    }

    #[Test]
    public function itTurnsAnEntryWithoutACountInert(): void
    {
        $entry = $this->parser->parse('method:App\Foo::bar', ['channel' => 'code-smell.goto#code-smell.goto']);

        self::assertInertFor($entry, InertEntryReason::Malformed);
    }

    /**
     * §5.1 calls `count` a *positive* integer. A present-but-non-positive one
     * is a different branch from a missing one and from a length mismatch,
     * and it is the branch that would let an entry claim a group of nobody.
     *
     * @param int $count a value the invariant forbids
     */
    #[Test]
    #[TestWith([0])]
    #[TestWith([-1])]
    public function itTurnsANonPositiveCountInert(int $count): void
    {
        $entry = $this->parser->parse('method:App\Foo::bar', [
            'channel' => 'code-smell.goto#code-smell.goto',
            'count' => $count,
        ]);

        self::assertInertFor($entry, InertEntryReason::Malformed);
    }

    #[Test]
    public function itTurnsANonFiniteMagnitudeInert(): void
    {
        // JSON has no literal for infinity, but an overflowing exponent
        // decodes to one.
        $entry = $this->parser->parse('method:App\Foo::bar', json_decode(
            '{"channel":"complexity.cyclomatic#complexity.cyclomatic.method","magnitudes":[1e400],"count":1}',
            true,
            512,
            \JSON_THROW_ON_ERROR,
        ));

        self::assertInertFor($entry, InertEntryReason::Malformed);
    }

    #[Test]
    public function itTurnsAMagnitudeListThatDisagreesWithTheCountInert(): void
    {
        $entry = $this->parser->parse('method:App\Foo::bar', [
            'channel' => 'complexity.cyclomatic#complexity.cyclomatic.method',
            'magnitudes' => [10, 20],
            'count' => 3,
        ]);

        self::assertInertFor($entry, InertEntryReason::Malformed);
    }

    #[Test]
    public function itTurnsAnUndeclaredChannelInert(): void
    {
        $entry = $this->parser->parse('method:App\Foo::bar', [
            'channel' => 'nobody.declares#this.channel',
            'count' => 1,
        ]);

        self::assertInertFor($entry, InertEntryReason::UndeclaredChannel);
        self::assertNotNull($entry->identity, 'The identity parsed; only its channel is unknown.');
    }

    #[Test]
    public function itTurnsAMagnitudeChannelWithoutMagnitudesInert(): void
    {
        $entry = $this->parser->parse('method:App\Foo::bar', [
            'channel' => 'complexity.cyclomatic#complexity.cyclomatic.method',
            'count' => 1,
        ]);

        self::assertInertFor($entry, InertEntryReason::ShapeMismatch);
    }

    #[Test]
    public function itTurnsAnOccurrenceChannelWithMagnitudesInert(): void
    {
        $entry = $this->parser->parse('method:App\Foo::bar', [
            'channel' => 'code-smell.goto#code-smell.goto',
            'magnitudes' => [1.0],
            'count' => 1,
        ]);

        self::assertInertFor($entry, InertEntryReason::ShapeMismatch);
    }

    #[Test]
    public function itTurnsAnUnrecognizedModeInert(): void
    {
        $entry = $this->parser->parse('method:App\Foo::bar', [
            'channel' => 'code-smell.goto#code-smell.goto',
            'count' => 1,
            'mode' => 'ceiling',
        ]);

        self::assertInertFor($entry, InertEntryReason::UnrecognizedMode);
    }

    #[Test]
    public function itTurnsAnUnknownEdgeTypeInert(): void
    {
        $entry = $this->parser->parse('class:App\Web\Controller', [
            'channel' => 'architecture.layer-violation#architecture.layer-violation',
            'edge' => ['target' => 'class:App\Db\Connection', 'type' => 'teleports-into'],
            'count' => 1,
        ]);

        self::assertInertFor($entry, InertEntryReason::Malformed);
    }

    /**
     * An inert entry whose identity parsed carries the same selector a valid
     * entry for that identity would, so the handle a user is shown is the
     * handle that removes it.
     */
    #[Test]
    public function itGivesAnInertEntryWithAnIdentityThatIdentitysSelector(): void
    {
        $entry = $this->parser->parse('method:App\Foo::bar', [
            'channel' => 'complexity.cyclomatic#complexity.cyclomatic.method',
            'count' => 1,
        ]);

        self::assertInstanceOf(InertBaselineEntry::class, $entry);
        self::assertNotNull($entry->identity);
        self::assertSame($entry->identity->selector()->value, $entry->selector->value);
    }

    #[Test]
    public function itGivesAnUnreadableEntryASelectorAnyway(): void
    {
        $entry = $this->parser->parse('method:App\Foo::bar', ['nonsense' => true]);

        self::assertInstanceOf(InertBaselineEntry::class, $entry);
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $entry->selector->value);
    }

    #[Test]
    public function itKeepsTheRawPayloadOfAnInertEntry(): void
    {
        $raw = ['channel' => 'nobody.declares#this.channel', 'count' => 1];

        $entry = $this->parser->parse('method:App\Foo::bar', $raw);

        self::assertInstanceOf(InertBaselineEntry::class, $entry);
        self::assertSame($raw, $entry->raw);
    }

    private static function assertInertFor(
        BaselineEntry|InertBaselineEntry $entry,
        InertEntryReason $reason,
    ): void {
        self::assertInstanceOf(InertBaselineEntry::class, $entry);
        self::assertSame($reason, $entry->reason);
        self::assertNotSame('', $entry->detail);
    }
}

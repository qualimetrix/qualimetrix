<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryMode;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryParser;
use Qualimetrix\Analysis\Policy\Baseline\InertBaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\InertEntryReason;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;

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
        $entry = $this->parser->parse('callable:App\Foo::bar', [
            'channel' => 'complexity.cyclomatic',
            'magnitudes' => [25],
        ]);

        self::assertInstanceOf(BaselineEntry::class, $entry);
        self::assertSame([25.0], $entry->magnitudes);
        self::assertSame(1, $entry->count);
    }

    #[Test]
    public function itParsesAnOccurrenceEntryWithAnEdge(): void
    {
        $entry = $this->parser->parse('class:App\Web\Controller', [
            'channel' => 'architecture.layer-violation',
            'edge' => ['target' => 'class:App\Db\Connection', 'type' => 'new'],
            'count' => 1,
        ]);

        self::assertInstanceOf(BaselineEntry::class, $entry);
        self::assertNotNull($entry->identity->edge);
        self::assertSame(DependencyType::New_, $entry->identity->edge->type);
    }

    #[Test]
    public function itParsesSemanticOccurrencesAsDistinctSelectorBearingIdentities(): void
    {
        $firstKey = OccurrenceKey::semantic('goto', ['line' => 10])->value;
        $secondKey = OccurrenceKey::semantic('goto', ['line' => 20])->value;
        $raw = [
            'channel' => 'code-smell.goto',
            'count' => 1,
        ];

        $first = $this->parser->parse('file:src/Foo.php', [...$raw, 'occurrence' => $firstKey]);
        $second = $this->parser->parse('file:src/Foo.php', [...$raw, 'occurrence' => $secondKey]);

        self::assertInstanceOf(BaselineEntry::class, $first);
        self::assertInstanceOf(BaselineEntry::class, $second);
        self::assertSame($firstKey, $first->identity->occurrenceKey);
        self::assertSame($secondKey, $second->identity->occurrenceKey);
        self::assertNotSame($first->identity->key(), $second->identity->key());
        self::assertNotSame($first->selector()->value, $second->selector()->value);
    }

    #[Test]
    public function itParsesTheSuppressMode(): void
    {
        $entry = $this->parser->parse('callable:App\Foo::bar', [
            'channel' => 'code-smell.goto',
            'count' => 2,
            'mode' => 'suppress',
        ]);

        self::assertInstanceOf(BaselineEntry::class, $entry);
        self::assertSame(BaselineEntryMode::Suppress, $entry->mode);
    }

    #[Test]
    public function itTurnsAnEntryThatIsNotAnObjectInert(): void
    {
        $entry = $this->parser->parse('callable:App\Foo::bar', 'not an object');

        self::assertInertFor($entry, InertEntryReason::Malformed);
    }

    #[Test]
    public function itTurnsAJsonListAtTheEntryBoundaryInert(): void
    {
        $entry = $this->parser->parse('callable:App\Foo::bar', []);

        self::assertInstanceOf(InertBaselineEntry::class, $entry);
        self::assertSame('entry must be a JSON object', $entry->detail);
    }

    #[Test]
    public function itTurnsAnEntryWithoutAChannelInert(): void
    {
        $entry = $this->parser->parse('callable:App\Foo::bar', ['count' => 1]);

        self::assertInertFor($entry, InertEntryReason::Malformed);
    }

    /**
     * A channel written in the retired `rule#code` spelling is not a name, so
     * the entry never reaches the "is this channel declared?" question.
     */
    #[Test]
    public function itTurnsAnEntryWithAnUnparseableChannelInert(): void
    {
        $entry = $this->parser->parse(
            'callable:App\Foo::bar',
            ['channel' => 'code-smell.goto#code-smell.goto', 'count' => 1],
        );

        self::assertInertFor($entry, InertEntryReason::Malformed);
    }

    #[Test]
    public function itTurnsAnEntryWithoutACountInert(): void
    {
        $entry = $this->parser->parse('callable:App\Foo::bar', ['channel' => 'code-smell.goto']);

        self::assertInertFor($entry, InertEntryReason::Malformed);
    }

    /**
     * ADR 0017 calls `count` a *positive* integer. A present-but-non-positive one
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
        $entry = $this->parser->parse('callable:App\Foo::bar', [
            'channel' => 'code-smell.goto',
            'count' => $count,
        ]);

        self::assertInertFor($entry, InertEntryReason::Malformed);
    }

    #[Test]
    public function itTurnsANonFiniteMagnitudeInert(): void
    {
        // JSON has no literal for infinity, but an overflowing exponent
        // decodes to one.
        $entry = $this->parser->parse('callable:App\Foo::bar', json_decode(
            '{"channel":"complexity.cyclomatic","magnitudes":[1e400]}',
            true,
            512,
            \JSON_THROW_ON_ERROR,
        ));

        self::assertInertFor($entry, InertEntryReason::Malformed);
    }

    /**
     * `count` is derived from `magnitudes`, so a file that writes both is
     * refused outright — the disagreement this used to test (`count`
     * mismatching the magnitude list's length) can no longer even be
     * expressed once `count` is forbidden alongside `magnitudes`.
     */
    #[Test]
    public function itTurnsAnEntryWithCountAlongsideMagnitudesInert(): void
    {
        $entry = $this->parser->parse('callable:App\Foo::bar', [
            'channel' => 'complexity.cyclomatic',
            'magnitudes' => [10, 20],
            'count' => 2,
        ]);

        self::assertInertFor($entry, InertEntryReason::Malformed);
        self::assertInstanceOf(InertBaselineEntry::class, $entry);
        self::assertSame(
            '"count" must not be present alongside "magnitudes"; it is derived from the magnitude list',
            $entry->detail,
        );
    }

    #[Test]
    public function itTurnsAnUndeclaredChannelInert(): void
    {
        $entry = $this->parser->parse('callable:App\Foo::bar', [
            'channel' => 'this.channel',
            'count' => 1,
        ]);

        self::assertInertFor($entry, InertEntryReason::UndeclaredChannel);
        self::assertNotNull($entry->identity, 'The identity parsed; only its channel is unknown.');
    }

    #[Test]
    public function itTurnsAMagnitudeChannelWithoutMagnitudesInert(): void
    {
        $entry = $this->parser->parse('callable:App\Foo::bar', [
            'channel' => 'complexity.cyclomatic',
            'count' => 1,
        ]);

        self::assertInertFor($entry, InertEntryReason::ShapeMismatch);
    }

    #[Test]
    public function itTurnsAnOccurrenceChannelWithMagnitudesInert(): void
    {
        $entry = $this->parser->parse('callable:App\Foo::bar', [
            'channel' => 'code-smell.goto',
            'magnitudes' => [1.0],
        ]);

        self::assertInertFor($entry, InertEntryReason::ShapeMismatch);
    }

    #[Test]
    public function itTurnsAnUnrecognizedModeInert(): void
    {
        $entry = $this->parser->parse('callable:App\Foo::bar', [
            'channel' => 'code-smell.goto',
            'count' => 1,
            'mode' => 'ceiling',
        ]);

        self::assertInertFor($entry, InertEntryReason::UnrecognizedMode);
    }

    #[Test]
    public function itTurnsAnUnknownEdgeTypeInert(): void
    {
        $entry = $this->parser->parse('class:App\Web\Controller', [
            'channel' => 'architecture.layer-violation',
            'edge' => ['target' => 'class:App\Db\Connection', 'type' => 'teleports-into'],
            'count' => 1,
        ]);

        self::assertInertFor($entry, InertEntryReason::Malformed);
    }

    #[Test]
    public function itRejectsAJsonListWhereEdgeRequiresAnObject(): void
    {
        $entry = $this->parser->parse('class:App\\Web\\Controller', [
            'channel' => 'architecture.layer-violation',
            'edge' => [],
            'count' => 1,
        ]);

        self::assertInstanceOf(InertBaselineEntry::class, $entry);
        self::assertSame(InertEntryReason::Malformed, $entry->reason);
        self::assertSame('"edge" must be a JSON object', $entry->detail);
    }

    #[Test]
    public function itRejectsWrongOptionalAndRequiredJsonShapesWithoutLosingRawInput(): void
    {
        $cases = [
            ['channel' => 12, 'count' => 1],
            ['channel' => 'code-smell.goto', 'occurrence' => [], 'count' => 1],
            ['channel' => 'code-smell.goto', 'count' => 1.0],
            ['channel' => 'complexity.cyclomatic', 'magnitudes' => ['value' => 1]],
            ['channel' => 'complexity.cyclomatic', 'magnitudes' => ['one']],
            ['channel' => 'architecture.layer-violation', 'edge' => ['target' => ''], 'count' => 1],
            ['channel' => 'architecture.layer-violation', 'edge' => ['target' => 'class:App\\Target', 'type' => 1], 'count' => 1],
        ];

        foreach ($cases as $raw) {
            $entry = $this->parser->parse('callable:App\Foo::bar', $raw);

            self::assertInertFor($entry, InertEntryReason::Malformed);
            self::assertInstanceOf(InertBaselineEntry::class, $entry);
            self::assertSame($raw, $entry->raw);
        }
    }

    /**
     * An inert entry whose identity parsed carries the same selector a valid
     * entry for that identity would, so the handle a user is shown is the
     * handle that removes it.
     */
    #[Test]
    public function itGivesAnInertEntryWithAnIdentityThatIdentitysSelector(): void
    {
        $entry = $this->parser->parse('callable:App\Foo::bar', [
            'channel' => 'complexity.cyclomatic',
            'count' => 1,
        ]);

        self::assertInstanceOf(InertBaselineEntry::class, $entry);
        self::assertNotNull($entry->identity);
        self::assertSame($entry->identity->selector()->value, $entry->selector->value);
    }

    #[Test]
    public function itGivesAnUnreadableEntryASelectorAnyway(): void
    {
        $entry = $this->parser->parse('callable:App\Foo::bar', ['nonsense' => true]);

        self::assertInstanceOf(InertBaselineEntry::class, $entry);
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $entry->selector->value);
    }

    #[Test]
    public function itKeepsTheRawPayloadOfAnInertEntry(): void
    {
        $raw = ['channel' => 'this.channel', 'count' => 1];

        $entry = $this->parser->parse('callable:App\Foo::bar', $raw);

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

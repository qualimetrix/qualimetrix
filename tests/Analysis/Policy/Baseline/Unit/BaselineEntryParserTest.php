<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Policy\Baseline\BaselineDocumentLayout;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryMode;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryParser;
use Qualimetrix\Analysis\Policy\Baseline\BaselineFileShape;
use Qualimetrix\Analysis\Policy\Baseline\BaselineFormatVersion;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Baseline\Contract\BaselineAuditChannels;
use Qualimetrix\Analysis\Policy\Baseline\InertBaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\InertEntryReason;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;

/**
 * One case per ambiguity of the governing invariant: an entry the mechanism
 * cannot apply must not suppress, and must say why.
 */
#[CoversClass(BaselineEntryParser::class)]
#[CoversClass(BaselineFileShape::class)]
#[CoversClass(BaselineLoader::class)]
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
            'channel' => 'complexity.ccn',
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
        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $declarations->declare('code-smell.goto', ChannelDeclaration::occurrence(SymbolLevel::File));
        $parser = new BaselineEntryParser($declarations);
        $firstKey = OccurrenceKey::semantic('goto', ['line' => 10])->value;
        $secondKey = OccurrenceKey::semantic('goto', ['line' => 20])->value;
        $raw = [
            'channel' => 'code-smell.goto',
            'count' => 1,
        ];

        $first = $parser->parse('file:src/Foo.php', [...$raw, 'occurrence' => $firstKey]);
        $second = $parser->parse('file:src/Foo.php', [...$raw, 'occurrence' => $secondKey]);

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
     * A key no subject is written as never meets a finding, and it measures
     * at no level a run could be asked about; kept, it would read as stale
     * forever.
     */
    #[Test]
    public function itTurnsAnEntryUnderAKeyNoSubjectIsWrittenAsInert(): void
    {
        $entry = $this->parser->parse('App\Foo::bar', ['channel' => 'complexity.ccn', 'magnitudes' => [25]]);

        self::assertInertFor($entry, InertEntryReason::Malformed);
        self::assertInstanceOf(InertBaselineEntry::class, $entry);
        self::assertStringContainsString('not a canonical metric subject', $entry->detail);
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
            '{"channel":"complexity.ccn","magnitudes":[1e400]}',
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
            'channel' => 'complexity.ccn',
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
            'channel' => 'complexity.ccn',
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

    /**
     * The `edge` field is read by {@see BaselineEdge::fromArray()} for both
     * this parser and the channel carry, so the sentence a user sees for a
     * malformed edge is pinned here rather than left to whichever caller
     * happens to run.
     */
    #[Test]
    public function itNamesWhatIsWrongWithAMalformedEdge(): void
    {
        $cases = [
            [[], '"edge" must be a JSON object'],
            [['target' => ''], '"edge.target" must be a non-empty canonical symbol path'],
            [['target' => 12], '"edge.target" must be a non-empty canonical symbol path'],
            [['target' => 'class:App\\Target', 'type' => 'teleports-into'], '"edge.type" is not a known dependency type: "teleports-into"'],
            [['target' => 'class:App\\Target', 'type' => 1], '"edge.type" is not a known dependency type: int'],
        ];

        foreach ($cases as [$edge, $detail]) {
            $entry = $this->parser->parse('callable:App\Foo::bar', [
                'channel' => 'architecture.layer-violation',
                'edge' => $edge,
                'count' => 1,
            ]);

            self::assertInstanceOf(InertBaselineEntry::class, $entry);
            self::assertSame($detail, $entry->detail);
        }
    }

    #[Test]
    public function itRejectsWrongOptionalAndRequiredJsonShapesWithoutLosingRawInput(): void
    {
        $cases = [
            ['channel' => 12, 'count' => 1],
            ['channel' => 'code-smell.goto', 'occurrence' => [], 'count' => 1],
            ['channel' => 'code-smell.goto', 'count' => 1.0],
            ['channel' => 'complexity.ccn', 'magnitudes' => ['value' => 1]],
            ['channel' => 'complexity.ccn', 'magnitudes' => ['one']],
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
            'channel' => 'complexity.ccn',
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

    #[Test]
    public function itTurnsAnUndeclaredSubjectLevelInertWithItsIdentityAndRawPayload(): void
    {
        foreach (['ns:App', 'file:src/Foo.php', 'project:'] as $subject) {
            $raw = ['channel' => 'complexity.ccn', 'magnitudes' => [25]];
            $entry = $this->parser->parse($subject, $raw);

            $entry = self::assertInertFor($entry, InertEntryReason::LevelNotDeclared);
            self::assertNotNull($entry->identity);
            self::assertSame($raw, $entry->raw);
            self::assertSame($entry->identity->selector()->value, $entry->selector->value);
            self::assertStringContainsString('in this configuration', $entry->detail);
        }
    }

    #[Test]
    public function itJudgesTheConfiguredLevelsOfAComputedChannel(): void
    {
        $declarations = new StubChannelDeclarationRegistry([
            'computed.risk' => ChannelDeclaration::magnitude(\Qualimetrix\Core\Observation\WorseDirection::Higher, SymbolLevel::Namespace_),
        ]);
        $parser = new BaselineEntryParser($declarations);
        $raw = ['channel' => 'computed.risk', 'magnitudes' => [25]];

        self::assertInstanceOf(BaselineEntry::class, $parser->parse('ns:App', $raw));
        self::assertInertFor($parser->parse('project:', $raw), InertEntryReason::LevelNotDeclared);
    }

    #[Test]
    public function itTurnsABaselineAuditChannelInertEvenWithoutADeclaration(): void
    {
        $raw = ['channel' => BaselineAuditChannels::UNUSED_ENTRY, 'count' => 1, 'mode' => 'suppress'];
        $entry = $this->parser->parse('project:', $raw);

        $entry = self::assertInertFor($entry, InertEntryReason::BaselineAuditChannel);
        self::assertSame($raw, $entry->raw);
        self::assertNotNull($entry->identity);
    }

    #[Test]
    public function itRefusesUnknownFileKeysAtTheSamePositionInCanonicalAndPrettyLayouts(): void
    {
        $subject = 'class:App\Foo';
        $envelope = [
            'version' => BaselineFormatVersion::CURRENT,
            'generated' => '2026-01-01T00:00:00+00:00',
            'scope' => ['src'],
            'exclusions' => ['patterns' => [], 'generated' => 'excluded'],
        ];
        $entry = ['channel' => 'complexity.ccn', 'magnitudes' => [25]];
        $cases = [
            [[...$envelope, 'genrated' => 'bad'], $entry, ['genrated'], BaselineFileShape::ENVELOPE],
            [[...$envelope, 'exclusions' => ['patterns' => [], 'generated' => 'excluded', 'extra' => true]], $entry, ['exclusions', 'extra'], BaselineFileShape::EXCLUSIONS],
            [$envelope, [...$entry, 'mod' => 'suppress'], ['entries', $subject, '1', 'mod'], BaselineFileShape::ENTRY],
            [$envelope, [...$entry, 'cnt' => 1], ['entries', $subject, '1', 'cnt'], BaselineFileShape::ENTRY],
            [$envelope, [...$entry, 'occurence' => 'bad'], ['entries', $subject, '1', 'occurence'], BaselineFileShape::ENTRY],
            [$envelope, ['channel' => 'complexity.ccn', 'magnitude' => 25], ['entries', $subject, '1', 'magnitude'], BaselineFileShape::ENTRY],
            [$envelope, [...$entry, 'edge' => ['target' => 'class:App\Bar', 'bogus' => true]], ['entries', $subject, '1', 'edge', 'bogus'], BaselineFileShape::EDGE],
            [$envelope, (object) ['0' => true], ['entries', $subject, '1', '0'], BaselineFileShape::ENTRY],
            [$envelope, [...$entry, 'edge' => (object) ['0' => true]], ['entries', $subject, '1', 'edge', '0'], BaselineFileShape::EDGE],
        ];
        $path = tempnam(sys_get_temp_dir(), 'qmx-grammar-');
        self::assertNotFalse($path);
        $loader = new BaselineLoader($this->parser);

        try {
            foreach ($cases as [$fields, $raw, $position, $accepted]) {
                $entries = [$subject => [$entry, $raw]];
                $messages = [];
                foreach ([BaselineDocumentLayout::render($fields, $entries), json_encode([...$fields, 'entries' => $entries], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR)] as $content) {
                    file_put_contents($path, $content);
                    try {
                        $loader->load((new \Qualimetrix\Analysis\Policy\Baseline\BaselineDocumentReader())->preflight($path));
                        self::fail('An unknown file key must refuse the complete document.');
                    } catch (ConfigurationRefusal $refusal) {
                        self::assertNotNull($refusal->position());
                        self::assertSame($position, $refusal->position()->segments);
                        self::assertSame($position[\count($position) - 1], $refusal->position()->written);
                        self::assertSame($accepted, $refusal->position()->accepted);
                        self::assertTrue($refusal->position()->closed);
                        self::assertSame('baseline', $refusal->sources()[0]->source()->value);
                        self::assertSame($path, $refusal->sources()[0]->locator());
                        $messages[] = $refusal->summary();
                    }
                }
                self::assertSame($messages[0], $messages[1]);
            }
        } finally {
            unlink($path);
        }
    }

    #[Test]
    public function itKeepsKnownBadEntryValuesInertInBothFileLayouts(): void
    {
        $fields = [
            'version' => BaselineFormatVersion::CURRENT,
            'generated' => '2026-01-01T00:00:00+00:00',
            'scope' => ['src'],
            'exclusions' => ['patterns' => [], 'generated' => 'excluded'],
        ];
        $rawEntries = [
            ['channel' => 'code-smell.goto', 'count' => 'x'],
            [true],
            ['channel' => 'code-smell.goto', 'count' => 1, 'edge' => [true]],
        ];
        $entries = ['callable:App\Foo::bar' => $rawEntries];
        $path = tempnam(sys_get_temp_dir(), 'qmx-inert-grammar-');
        self::assertNotFalse($path);
        $loader = new BaselineLoader($this->parser);

        try {
            foreach ([BaselineDocumentLayout::render($fields, $entries), json_encode([...$fields, 'entries' => $entries], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR)] as $content) {
                file_put_contents($path, $content);
                $baseline = $loader->load((new \Qualimetrix\Analysis\Policy\Baseline\BaselineDocumentReader())->preflight($path));
                self::assertSame([], $baseline->entries);
                self::assertCount(3, $baseline->inertEntries);
                foreach ($rawEntries as $index => $raw) {
                    self::assertSame(InertEntryReason::Malformed, $baseline->inertEntries[$index]->reason);
                    self::assertSame($raw, $baseline->inertEntries[$index]->raw);
                }
            }
        } finally {
            unlink($path);
        }
    }

    #[Test]
    public function itRequiresACompleteAndValidExclusionDefinition(): void
    {
        $envelope = [
            'version' => BaselineFormatVersion::CURRENT,
            'generated' => '2026-01-01T00:00:00+00:00',
            'scope' => ['src'],
            'entries' => [],
        ];
        $cases = [
            [null, ['exclusions']],
            [[], ['exclusions']],
            [['generated' => 'excluded'], ['exclusions', 'patterns']],
            [['patterns' => [], 'generated' => 'sometimes'], ['exclusions', 'generated']],
            [['patterns' => ['src/Legacy'], 'generated' => 'excluded'], ['exclusions', 'patterns', '0']],
            [['patterns' => ['subtree:'], 'generated' => 'excluded'], ['exclusions', 'patterns', '0']],
            [['patterns' => [12], 'generated' => 'excluded'], ['exclusions', 'patterns', '0']],
        ];

        foreach ($cases as [$exclusions, $position]) {
            try {
                BaselineFileShape::decode(json_encode([...$envelope, 'exclusions' => $exclusions], \JSON_THROW_ON_ERROR), 'accepted.json');
                self::fail('An incomplete exclusion definition must not default to no exclusions.');
            } catch (ConfigurationRefusal $refusal) {
                self::assertNotNull($refusal->position());
                self::assertSame($position, $refusal->position()->segments);
                self::assertSame('baseline', $refusal->sources()[0]->source()->value);
                self::assertSame('accepted.json', $refusal->sources()[0]->locator());
            }
        }

        try {
            BaselineFileShape::decode(json_encode($envelope, \JSON_THROW_ON_ERROR), 'accepted.json');
            self::fail('The exclusion definition is mandatory.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame(['exclusions'], $refusal->position()?->segments);
        }
    }

    #[Test]
    public function itRefusesVersionThirteenAndLoadsItsExplicitlyMigratedEnvelope(): void
    {
        $document = [
            'version' => 13,
            'generated' => '2026-01-01T00:00:00+00:00',
            'scope' => ['src'],
            'entries' => ['callable:App\Foo::bar' => [['channel' => 'complexity.ccn', 'magnitudes' => [25]]]],
        ];
        $path = tempnam(sys_get_temp_dir(), 'qmx-migration-');
        self::assertNotFalse($path);
        $loader = new BaselineLoader($this->parser);

        try {
            file_put_contents($path, json_encode($document, \JSON_THROW_ON_ERROR));
            try {
                $loader->load((new \Qualimetrix\Analysis\Policy\Baseline\BaselineDocumentReader())->preflight($path));
                self::fail('Version 13 has no recorded exclusion definition.');
            } catch (ConfigurationRefusal $refusal) {
                self::assertStringContainsString('version 13', $refusal->summary());
                self::assertStringContainsString('git log -1 -- <baseline-file>', $refusal->summary());
                self::assertStringContainsString('"exclusions"', $refusal->summary());
                self::assertStringContainsString('"version" to ' . BaselineFormatVersion::CURRENT, $refusal->summary());
            }

            $document['version'] = BaselineFormatVersion::CURRENT;
            $document['exclusions'] = ['patterns' => ['subtree:src/Legacy'], 'generated' => 'included'];
            file_put_contents($path, json_encode($document, \JSON_THROW_ON_ERROR));
            $baseline = $loader->load((new \Qualimetrix\Analysis\Policy\Baseline\BaselineDocumentReader())->preflight($path));
            self::assertSame([25.0], $baseline->entries[0]->magnitudes);
            self::assertSame($document['exclusions'], $baseline->exclusions->toArray());
        } finally {
            unlink($path);
        }
    }

    private static function assertInertFor(
        BaselineEntry|InertBaselineEntry $entry,
        InertEntryReason $reason,
    ): InertBaselineEntry {
        self::assertInstanceOf(InertBaselineEntry::class, $entry);
        self::assertSame($reason, $entry->reason);
        self::assertNotSame('', $entry->detail);

        return $entry;
    }
}

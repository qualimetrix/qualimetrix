<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Contract\Document;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\BareElementPolicy;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\IntegerJudgement;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\KeyDictionary;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\MergePolicy;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeCollectionFacts;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeMapFacts;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeScalarFacts;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SchemaWordSet;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\Shorthand;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\TextRequirement;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\WordComparison;

#[CoversClass(NodeSchema::class)]
#[CoversClass(SchemaWordSet::class)]
#[CoversClass(WordComparison::class)]
#[CoversClass(TextRequirement::class)]
#[CoversClass(NodeScalarFacts::class)]
#[CoversClass(NodeMapFacts::class)]
#[CoversClass(NodeCollectionFacts::class)]
#[CoversClass(BareElementPolicy::class)]
#[CoversClass(KeyDictionary::class)]
final class NodeSchemaTest extends TestCase
{
    #[Test]
    public function itDeclaresOnePolicyPerFactory(): void
    {
        $scalar = NodeSchema::scalar(ScalarForm::String);

        self::assertSame(MergePolicy::LastWriterWins, $scalar->policy);
        self::assertSame(MergePolicy::DeepMerge, NodeSchema::map(['dir' => $scalar])->policy);
        self::assertSame(MergePolicy::Replace, NodeSchema::list(NodeSchema::scalar(ScalarForm::String))->policy);
        self::assertSame(MergePolicy::Accumulate, NodeSchema::set($scalar)->policy);
        self::assertSame(MergePolicy::ByName, NodeSchema::namedMap($scalar)->policy);
        self::assertSame(MergePolicy::PerLayer, NodeSchema::opaque()->policy);
    }

    #[Test]
    public function itRefusesToDeclareANonCanonicalKey(): void
    {
        $this->expectException(LogicException::class);

        NodeSchema::map(['failOn' => NodeSchema::scalar()]);
    }

    #[Test]
    public function itRefusesAnIntegerJudgementOnANonIntegerScalar(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('An integer judgement requires a last-writer-wins integer scalar.');

        NodeSchema::scalar(ScalarForm::String)->judgedInEachLayer(new IntegerJudgement(static fn(int $value): ?string => null));
    }

    #[Test]
    public function itRefusesAShorthandSpreadingToAnUndeclaredKey(): void
    {
        $this->expectException(LogicException::class);

        NodeSchema::map(['warning' => NodeSchema::scalar()], Shorthand::spreading('threshold', ['warning', 'error']));
    }

    #[Test]
    public function itRefusesAShorthandThatIsAlsoAKey(): void
    {
        $this->expectException(LogicException::class);

        NodeSchema::map(['threshold' => NodeSchema::scalar(), 'warning' => NodeSchema::scalar()], Shorthand::spreading('threshold', ['warning']));
    }

    #[Test]
    public function itRefusesTwoShorthandsSpreadingToOneKey(): void
    {
        $this->expectException(LogicException::class);

        NodeSchema::map(
            ['warning' => NodeSchema::scalar(), 'error' => NodeSchema::scalar()],
            Shorthand::spreading('threshold', ['warning', 'error']),
            Shorthand::spreading('limit', ['error']),
        );
    }

    #[Test]
    public function itAnnouncesAnEmptyOverrideOnlyOnAReplacedList(): void
    {
        self::assertSame('All rules run.', NodeSchema::list(NodeSchema::scalar(ScalarForm::String))->announcingEmptyOverride('All rules run.')->wording->emptyOverrideNotice);

        $this->expectException(LogicException::class);

        NodeSchema::set(NodeSchema::scalar())->announcingEmptyOverride('never');
    }

    #[Test]
    public function itKeepsEveryDeclarationWhenAHintAndAnEmptyOverrideNoticeAreAdded(): void
    {
        $element = NodeSchema::scalar(ScalarForm::String);
        $hintedThenAnnounced = NodeSchema::list($element)->withHint('Quote it.')->announcingEmptyOverride('Lifted.');
        $announcedThenHinted = NodeSchema::list($element)->announcingEmptyOverride('Lifted.')->withHint('Quote it.');

        foreach ([$hintedThenAnnounced, $announcedThenHinted] as $schema) {
            self::assertSame('Quote it.', $schema->wording->hint);
            self::assertSame('Lifted.', $schema->wording->emptyOverrideNotice);
            self::assertSame($element, $schema->collection?->element);
            self::assertSame(MergePolicy::Replace, $schema->policy);
        }

        $map = NodeSchema::map(['dir' => $element], Shorthand::spreading('all', ['dir']))->withHint('Name a kind.');
        self::assertSame(['dir' => $element], $map->map->keys->fields());
        self::assertCount(1, $map->map->keys->shorthands());
        self::assertSame([ScalarForm::String], $element->withHint('x')->scalar->forms);
        self::assertNull($element->wording->hint);
    }

    #[Test]
    public function itDescribesTheDeclaredFormAndRetainsItAcrossModifiers(): void
    {
        $word = NodeSchema::scalar(ScalarForm::String)->words(SchemaWordSet::foldingCase('warn', 'error'))->nonEmpty();
        self::assertSame('non-empty string (one of warn, error, case-insensitive)', $word->describe());
        self::assertSame($word->describe(), $word->withHint('Choose one.')->describe());
        self::assertSame('integer at least 1', NodeSchema::scalar(ScalarForm::Integer)->atLeast(1)->describe());
        self::assertSame('a list or one element', NodeSchema::list(NodeSchema::scalar(ScalarForm::String))->admittingBareElement()->describe());
        self::assertSame('a map or a boolean for "enabled"', NodeSchema::map([
            'enabled' => NodeSchema::scalar(ScalarForm::Boolean),
        ])->bareFor('enabled')->describe());
    }

    #[Test]
    public function itRetainsBlankWordsInAScalarVocabulary(): void
    {
        $words = SchemaWordSet::of('');
        self::assertTrue($words->contains(''));
        self::assertSame('string (one of )', NodeSchema::scalar(ScalarForm::String)->words($words)->describe());
        self::assertTrue(SchemaWordSet::foldingCase(' ')->contains(' '));
    }
}

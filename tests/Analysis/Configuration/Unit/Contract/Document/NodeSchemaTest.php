<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Contract\Document;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\KeyDictionary;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\MergePolicy;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\Shorthand;

#[CoversClass(NodeSchema::class)]
#[CoversClass(KeyDictionary::class)]
final class NodeSchemaTest extends TestCase
{
    #[Test]
    public function itDeclaresOnePolicyPerFactory(): void
    {
        $scalar = NodeSchema::scalar(ScalarForm::String);

        self::assertSame(MergePolicy::LastWriterWins, $scalar->policy);
        self::assertSame(MergePolicy::DeepMerge, NodeSchema::map(['dir' => $scalar])->policy);
        self::assertSame(MergePolicy::Replace, NodeSchema::stringList()->policy);
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
        self::assertSame('All rules run.', NodeSchema::stringList()->announcingEmptyOverride('All rules run.')->emptyOverrideNotice());

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
            self::assertSame('Quote it.', $schema->hint());
            self::assertSame('Lifted.', $schema->emptyOverrideNotice());
            self::assertSame($element, $schema->element());
            self::assertSame(MergePolicy::Replace, $schema->policy);
        }

        $map = NodeSchema::map(['dir' => $element], Shorthand::spreading('all', ['dir']))->withHint('Name a kind.');
        self::assertSame(['dir' => $element], $map->fields());
        self::assertCount(1, $map->shorthands());
        self::assertSame([ScalarForm::String], $element->withHint('x')->scalarForms());
        self::assertNull($element->hint());
    }
}

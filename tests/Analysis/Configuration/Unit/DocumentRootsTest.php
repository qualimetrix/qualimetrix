<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\ConfigurationRoot;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\MergePolicy;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\DocumentRoots;
use Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument;

#[CoversClass(DocumentRoots::class)]
final class DocumentRootsTest extends TestCase
{
    #[Test]
    public function itKnowsEveryRootTheSchemaAcceptsInItsCanonicalKey(): void
    {
        self::assertContains('computed_metrics', DocumentRoots::known());
        self::assertContains('fail_on', DocumentRoots::known());
        self::assertCount(\count(ConfigSchema::allowedRootKeys()), DocumentRoots::known());
    }

    /**
     * Configuration declares its own roots and leaves every capability-owned
     * root, and `rules`, to its owner: a root declared twice is a build error.
     */
    #[Test]
    public function itDeclaresNoRootAnOwnerDeclares(): void
    {
        $declared = self::keys(ConfigurationRoot::cases());
        $ownerRoots = array_map(
            static fn(string $root): string => strtolower((string) preg_replace('/[A-Z]/', '_$0', $root)),
            [...ConfigSchema::DOCUMENT_ROOTS, ConfigSchema::RULES, ...self::keys(LayeredDocument::standaloneSections())],
        );

        self::assertSame([], array_values(array_intersect($declared, $ownerRoots)));
        self::assertSame([], array_values(array_diff($declared, DocumentRoots::known())));
        self::assertEqualsCanonicalizing(DocumentRoots::known(), [...$declared, ...$ownerRoots]);
    }

    /** A known root nobody declares is carried unread; an owner's section displaces that stand-in. */
    #[Test]
    public function itStandsInForAKnownRootOnlyUntilItsOwnerDeclaresIt(): void
    {
        $standIns = static fn(array $sections): array => self::keys(array_values(array_filter(
            $sections,
            static fn(DocumentSectionSchemaInterface $section): bool => $section->schema()->policy === MergePolicy::PerLayer,
        )));

        self::assertContains('coupling', $standIns(DocumentRoots::completing([])));

        $completed = DocumentRoots::completing([new class implements DocumentSectionSchemaInterface {
            public function key(): string
            {
                return 'coupling';
            }

            public function schema(): NodeSchema
            {
                return NodeSchema::map([]);
            }
        }]);
        self::assertNotContains('coupling', $standIns($completed));
        self::assertEqualsCanonicalizing(DocumentRoots::known(), self::keys($completed));
    }

    /** Every key the command line writes under has a place in the document. */
    #[Test]
    public function itPlacesEveryFlatKeyInTheDocument(): void
    {
        self::assertSame(['cache', 'dir'], DocumentRoots::pathOf(ConfigSchema::CACHE_DIR));
        self::assertSame(['exclude'], DocumentRoots::pathOf(ConfigSchema::EXCLUDES));
        self::assertSame(['disabled_rules'], DocumentRoots::pathOf(ConfigSchema::DISABLED_RULES));
        self::assertSame(['exclude_health'], DocumentRoots::pathOf(ConfigSchema::EXCLUDE_HEALTH));
    }

    /**
     * @param list<DocumentSectionSchemaInterface> $sections
     *
     * @return list<string>
     */
    private static function keys(array $sections): array
    {
        return array_map(static fn(DocumentSectionSchemaInterface $section): string => $section->key(), $sections);
    }
}

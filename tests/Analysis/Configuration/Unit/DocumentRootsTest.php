<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\ConfigurationRoot;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
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
        $ownerRoots = array_values(array_unique(array_map(
            static fn(string $root): string => strtolower((string) preg_replace('/[A-Z]/', '_$0', $root)),
            [...ConfigSchema::DOCUMENT_ROOTS, ConfigSchema::RULES, ...self::keys(LayeredDocument::standaloneSections())],
        )));

        self::assertSame([], array_values(array_intersect($declared, $ownerRoots)));
        self::assertSame([], array_values(array_diff($declared, DocumentRoots::known())));
        self::assertEqualsCanonicalizing(DocumentRoots::known(), [...$declared, ...$ownerRoots]);
    }

    #[Test]
    public function itRequiresTheActualOwnerForEveryKnownRoot(): void
    {
        $layer = new \Qualimetrix\Analysis\Configuration\Document\AuthoredLayer(
            \Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin::of(
                \Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource::ConfigFile,
                '/project/qmx.yaml',
            ),
            \Qualimetrix\Analysis\Configuration\Document\AuthoredNode::fromPlain([
                'coupling' => ['framework_namespaces' => [['subtree' => 'App']]],
            ]),
        );
        try {
            \Qualimetrix\Analysis\Configuration\Document\DocumentComposer::compose(
                new \Qualimetrix\Analysis\Configuration\Document\DocumentSchema(ConfigurationRoot::cases()),
                [$layer],
            );
            self::fail('A known root requires its declared owner.');
        } catch (\Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal $refusal) {
            self::assertSame('Unknown key "coupling" in configuration file "/project/qmx.yaml". Accepted keys: exclude, suppress_paths, suppress_namespaces, include_generated, include_autoload_dev.', $refusal->summary());
            self::assertSame('/project/qmx.yaml', $refusal->sources()[0]->locator());
            self::assertSame(['coupling'], $refusal->position()?->segments);
        }
        $sections = [...ConfigurationRoot::cases(), ...LayeredDocument::standaloneSections()];
        $document = \Qualimetrix\Analysis\Configuration\Document\DocumentComposer::compose(
            new \Qualimetrix\Analysis\Configuration\Document\DocumentSchema($sections),
            [$layer],
        );
        self::assertSame([['subtree' => 'App']], $document->get('coupling', 'framework_namespaces')?->plain());
        self::assertEqualsCanonicalizing(DocumentRoots::known(), self::keys($sections));
    }

    /** Every key the command line writes under has a place in the document. */
    #[Test]
    public function itPlacesEveryFlatKeyInTheDocument(): void
    {
        self::assertSame(['cache', 'dir'], DocumentRoots::pathOf(ConfigSchema::CACHE_DIR));
        self::assertSame(['exclude'], DocumentRoots::pathOf(ConfigSchema::EXCLUDE));
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
        return array_map(static fn(DocumentSectionSchemaInterface $section): string => $section->declaration()->key, $sections);
    }
}

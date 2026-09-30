<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\ProjectManifest\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestDecoder;
use Qualimetrix\Analysis\ProjectManifest\Contract\ManifestIssueKind;
use Qualimetrix\Analysis\ProjectManifest\Contract\ManifestReadState;
use Qualimetrix\Core\Path\AbsolutePath;

#[CoversClass(ComposerManifestDecoder::class)]
final class ComposerManifestDecoderTest extends TestCase
{
    #[Test]
    public function itPreservesTheLiteralZeroPath(): void
    {
        $facts = (new ComposerManifestDecoder())->decode(AbsolutePath::fromString('/manifest-fixture'), '{"autoload":{"files":["0","0/"]}}');
        self::assertSame(['0'], $facts->productionTargets());
    }

    #[Test]
    public function itDistinguishesAnEmptyObjectFromAnArrayRoot(): void
    {
        $decoder = new ComposerManifestDecoder();
        $root = AbsolutePath::fromString('/manifest-fixture');
        self::assertSame(ManifestReadState::Read, $decoder->decode($root, '{}')->state);
        $invalid = $decoder->decode($root, '[]');
        self::assertSame(ManifestReadState::Invalid, $invalid->state);
        self::assertSame(ManifestIssueKind::InvalidRoot, $invalid->issues[0]->kind);
    }

    #[Test]
    public function itRetainsGoodPathsAndNamesEveryDroppedRecord(): void
    {
        $facts = (new ComposerManifestDecoder())->decode(AbsolutePath::fromString('/manifest-fixture'), <<<'JSON'
            {"autoload":{"psr-4":{"App\\":["src/",false,"",["nested"]],"Dropped\\":7},"classmap":["legacy/",{"path":"bad"}],"files":["helpers.php",["nested"]]},"autoload-dev":{"psr-4":{"Test\\":"tests/"}}}
            JSON);
        self::assertSame(['src', '.', 'legacy', 'helpers.php'], $facts->productionTargets());
        self::assertSame(['App\\' => ['src', '.'], 'Test\\' => ['tests']], $facts->psr4Roots());
        self::assertFalse($facts->production->complete);
        self::assertTrue($facts->development->complete);
        self::assertSame([
            ['autoload', 'psr-4', 'App\\', '1'], ['autoload', 'psr-4', 'App\\', '3'],
            ['autoload', 'psr-4', 'Dropped\\'], ['autoload', 'classmap', '1'], ['autoload', 'files', '1'],
        ], array_column($facts->issues, 'location'));
        self::assertSame('/manifest-fixture/composer.json', $facts->issues[0]->source);
    }

    #[Test]
    public function itRejectsMapsAndScalarsWherePlainPathListsAreRequired(): void
    {
        $facts = (new ComposerManifestDecoder())->decode(AbsolutePath::fromString('/manifest-fixture'), '{"autoload":{"classmap":{"named":"legacy"},"files":"helpers.php","psr-0":{"Old_":{"named":"legacy"}}}}');
        self::assertSame([], $facts->productionTargets());
        self::assertFalse($facts->production->complete);
        self::assertCount(3, $facts->issues);
    }

    #[Test]
    public function itKeepsMetadataAndExcludedDevelopmentDamageOutOfProductionIntegrity(): void
    {
        $facts = (new ComposerManifestDecoder())->decode(AbsolutePath::fromString('/manifest-fixture'), '{"name":false,"config":{"vendor-dir":[]},"autoload":{"files":["helpers.php"]},"autoload-dev":{"classmap":[false]}}');
        self::assertTrue($facts->production->complete);
        self::assertFalse($facts->development->complete);
        self::assertNull($facts->name);
        self::assertSame('vendor', $facts->vendorDirectory);
        self::assertSame([], $facts->productionScopeIssues());
        self::assertCount(1, $facts->allScopeIssues());
    }
}

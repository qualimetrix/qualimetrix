<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\ValueReach;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Analysis\Policy\Baseline\Ceiling\SubjectRegion;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\FindingFactory;

#[CoversClass(SubjectRegion::class)]
final class SubjectRegionTest extends TestCase
{
    #[Test]
    public function itUsesBothComposerSectionsAndWidensForObservedFilesOutsideTheMappedRoots(): void
    {
        $app = new BaselineIdentity(SymbolPath::forNamespace('App\\Domain')->toCanonical(), new FindingChannel('size.namespace-size'));
        $roots = ['App\\' => ['src/'], 'App\\Domain\\' => ['lib/'], 'Tests\\' => ['tests/']];
        $region = SubjectRegion::forIdentity($app, ValueReach::Members, $roots);

        self::assertSame('namespace', $region->kind);
        self::assertTrue($region->contains(RelativePath::fromString('src/Domain/Service.php')));
        self::assertTrue($region->contains(RelativePath::fromString('lib/Service.php')));
        self::assertFalse($region->contains(RelativePath::fromString('src/Other/Service.php')));

        $dev = new BaselineIdentity(SymbolPath::forNamespace('Tests\\Unit')->toCanonical(), new FindingChannel('size.namespace-size'));
        self::assertTrue(SubjectRegion::forIdentity($dev, ValueReach::Members, $roots)->contains(RelativePath::fromString('tests/Unit/ServiceTest.php')));

        $outside = FindingFactory::magnitude(SymbolPath::forNamespace('App\\Domain'), 10);
        self::assertSame('whole', SubjectRegion::forIdentity($app, ValueReach::Members, $roots, [$outside])->kind);
    }
}

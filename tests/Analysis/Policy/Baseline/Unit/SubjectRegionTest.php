<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\ValueReach;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Analysis\Policy\Baseline\Ceiling\SubjectRegion;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\FindingFactory;

#[CoversClass(SubjectRegion::class)]
final class SubjectRegionTest extends TestCase
{
    #[Test]
    public function itResolvesDeclarationFileBytesBeforeDiscardingTheOccurrenceOrdinal(): void
    {
        $identity = new BaselineIdentity(
            'declaration:callable:App\\Service::run@src/Service%23%40.php#2',
            new FindingChannel('complexity.ccn'),
        );
        self::assertSame('src/Service#@.php', SubjectRegion::subjectFile($identity)?->value());
        $relation = new BaselineIdentity('class:App\\Service', new FindingChannel('architecture.layer-violation'));
        self::assertNull(SubjectRegion::subjectFile($relation));
    }

    #[Test]
    public function itDoesNotTreatAnEmptyCurrentGroupAsProofOfPsr4Containment(): void
    {
        $identity = new BaselineIdentity(SymbolPath::forNamespace('App\\Domain')->toCanonical(), new FindingChannel('size.namespace-size'));

        self::assertSame(
            'whole',
            SubjectRegion::forIdentity($identity, ValueReach::Members, ['App\\' => ['src/']], [])->kind,
        );
    }

    #[Test]
    public function itUsesBothComposerSectionsAndWidensForObservedFilesOutsideTheMappedRoots(): void
    {
        $app = new BaselineIdentity(SymbolPath::forNamespace('App\\Domain')->toCanonical(), new FindingChannel('size.namespace-size'));
        $roots = ['App\\' => ['src/'], 'App\\Domain\\' => ['lib/'], 'Tests\\' => ['tests/']];
        $region = SubjectRegion::forIdentity($app, ValueReach::Members, $roots, [self::observed('App\\Domain', 'lib/Service.php')]);

        self::assertSame('namespace', $region->kind);
        self::assertTrue($region->contains(RelativePath::fromString('src/Domain/Service.php')));
        self::assertTrue($region->contains(RelativePath::fromString('lib/Service.php')));
        self::assertFalse($region->contains(RelativePath::fromString('src/Other/Service.php')));

        $dev = new BaselineIdentity(SymbolPath::forNamespace('Tests\\Unit')->toCanonical(), new FindingChannel('size.namespace-size'));
        self::assertTrue(SubjectRegion::forIdentity($dev, ValueReach::Members, $roots, [self::observed('Tests\\Unit', 'tests/Unit/ServiceTest.php')])->contains(RelativePath::fromString('tests/Unit/ServiceTest.php')));

        $outside = FindingFactory::magnitude(SymbolPath::forNamespace('App\\Domain'), 10);
        self::assertSame('whole', SubjectRegion::forIdentity($app, ValueReach::Members, $roots, [$outside])->kind);
    }

    private static function observed(string $namespace, string $file): Finding
    {
        $symbol = SymbolPath::forNamespace($namespace);

        return new Finding(
            new Location(RelativePath::fromString($file)),
            MetricSubject::aggregate($symbol),
            $symbol,
            'size.namespace-size',
            'size.namespace-size',
            'namespace size',
            Severity::Warning,
        );
    }
}

<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterInterface;
use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStage;
use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStageResult;
use Qualimetrix\Analysis\Finding\Contract\Filter\PredicateFilterStage;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(PredicateFilterStage::class)]
#[CoversClass(FindingFilterStageResult::class)]
final class PredicateFilterStageTest extends TestCase
{
    #[Test]
    public function itReportsTheStageItWasBuiltFor(): void
    {
        $stage = new PredicateFilterStage(FindingFilterStage::Suppression, self::keepingAll());

        self::assertSame(FindingFilterStage::Suppression, $stage->stage());
        self::assertSame(FindingFilterStage::Suppression, $stage->apply([])->stage);
    }

    #[Test]
    public function itKeepsWhatThePredicateIncludesAndCollectsWhatItDoesNot(): void
    {
        $kept = self::finding('kept');
        $dropped = self::finding('dropped');

        $stage = new PredicateFilterStage(
            FindingFilterStage::PathExclusion,
            new class implements FindingFilterInterface {
                public function shouldInclude(Finding $finding): bool
                {
                    return $finding->message === 'kept';
                }
            },
        );

        $result = $stage->apply([$kept, $dropped, $kept]);

        self::assertSame([$kept, $kept], $result->findings);
        self::assertSame([$dropped], $result->removed);
        self::assertSame(1, $result->removedCount());
    }

    /**
     * A predicate stage never rewrites what it keeps: the very objects that
     * went in come out, which is what lets the pipeline treat it and the
     * transforming baseline stage as one kind of thing.
     */
    #[Test]
    public function itPassesThroughTheSameObjectsInTheSameOrder(): void
    {
        $first = self::finding('first');
        $second = self::finding('second');

        $result = (new PredicateFilterStage(FindingFilterStage::GitScope, self::keepingAll()))
            ->apply([$first, $second]);

        self::assertSame([$first, $second], $result->findings);
        self::assertSame([], $result->removed);
    }

    private static function keepingAll(): FindingFilterInterface
    {
        return new class implements FindingFilterInterface {
            public function shouldInclude(Finding $finding): bool
            {
                return true;
            }
        };
    }

    private static function finding(string $message): Finding
    {
        return new Finding(
            location: new Location(RelativePath::fromString('src/Foo.php'), 1),
            symbolPath: SymbolPath::forClass('App', 'Foo'),
            subject: MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass('App', 'Foo'), RelativePath::fromString('src/Foo.php'), DeclarationOrdinal::fromRank(0))),
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic.callable',
            message: $message,
            severity: Severity::Warning,
        );
    }
}

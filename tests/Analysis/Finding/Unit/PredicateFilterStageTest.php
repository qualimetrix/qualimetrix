<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Filter\PredicateFilterStage;
use Qualimetrix\Analysis\Finding\Contract\Filter\ViolationFilterInterface;
use Qualimetrix\Analysis\Finding\Contract\Filter\ViolationFilterStage;
use Qualimetrix\Analysis\Finding\Contract\Filter\ViolationFilterStageResult;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(PredicateFilterStage::class)]
#[CoversClass(ViolationFilterStageResult::class)]
final class PredicateFilterStageTest extends TestCase
{
    #[Test]
    public function itReportsTheStageItWasBuiltFor(): void
    {
        $stage = new PredicateFilterStage(ViolationFilterStage::Suppression, self::keepingAll());

        self::assertSame(ViolationFilterStage::Suppression, $stage->stage());
        self::assertSame(ViolationFilterStage::Suppression, $stage->apply([])->stage);
    }

    #[Test]
    public function itKeepsWhatThePredicateIncludesAndCollectsWhatItDoesNot(): void
    {
        $kept = self::violation('kept');
        $dropped = self::violation('dropped');

        $stage = new PredicateFilterStage(
            ViolationFilterStage::PathExclusion,
            new class implements ViolationFilterInterface {
                public function shouldInclude(Violation $violation): bool
                {
                    return $violation->message === 'kept';
                }
            },
        );

        $result = $stage->apply([$kept, $dropped, $kept]);

        self::assertSame([$kept, $kept], $result->violations);
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
        $first = self::violation('first');
        $second = self::violation('second');

        $result = (new PredicateFilterStage(ViolationFilterStage::GitScope, self::keepingAll()))
            ->apply([$first, $second]);

        self::assertSame([$first, $second], $result->violations);
        self::assertSame([], $result->removed);
    }

    private static function keepingAll(): ViolationFilterInterface
    {
        return new class implements ViolationFilterInterface {
            public function shouldInclude(Violation $violation): bool
            {
                return true;
            }
        };
    }

    private static function violation(string $message): Violation
    {
        return new Violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 1),
            symbolPath: SymbolPath::forClass('App', 'Foo'),
            subject: MetricSubject::declaration(new DeclarationPath(
                SymbolPath::forClass('App', 'Foo'),
                RelativePath::fromString('src/Foo.php'),
                1,
            )),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.callable',
            message: $message,
            severity: Severity::Warning,
        );
    }
}

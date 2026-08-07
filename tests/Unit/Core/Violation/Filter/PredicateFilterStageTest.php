<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Violation\Filter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Filter\PredicateFilterStage;
use Qualimetrix\Core\Violation\Filter\ViolationFilterInterface;
use Qualimetrix\Core\Violation\Filter\ViolationFilterStage;
use Qualimetrix\Core\Violation\Filter\ViolationFilterStageResult;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;

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
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.method',
            message: $message,
            severity: Severity::Warning,
        );
    }
}

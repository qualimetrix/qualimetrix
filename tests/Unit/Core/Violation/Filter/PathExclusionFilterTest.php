<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Violation\Filter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Util\PathMatcher;
use Qualimetrix\Core\Violation\Filter\PathExclusionFilter;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;

#[CoversClass(PathExclusionFilter::class)]
final class PathExclusionFilterTest extends TestCase
{
    #[Test]
    public function itFiltersSuppressedPath(): void
    {
        $filter = new PathExclusionFilter(new PathMatcher(['src/Entity']));

        $violation = $this->createViolation('src/Entity/User.php');

        self::assertFalse($filter->shouldInclude($violation), 'Violation matching exclusion prefix should be suppressed');
    }

    #[Test]
    public function itPassesNonMatchingPath(): void
    {
        $filter = new PathExclusionFilter(new PathMatcher(['src/Entity']));

        $violation = $this->createViolation('src/Service/UserService.php');

        self::assertTrue($filter->shouldInclude($violation), 'Violation not matching exclusion prefix should pass through');
    }

    #[Test]
    public function itPassesEmptyFilePath(): void
    {
        $filter = new PathExclusionFilter(new PathMatcher(['src']));

        $violation = new Violation(
            location: new Location(''),
            symbolPath: SymbolPath::forNamespace('App\\Service'),
            ruleName: 'test.rule',
            violationCode: 'test.rule',
            message: 'Test',
            severity: Severity::Warning,
        );

        self::assertTrue($filter->shouldInclude($violation), 'Violation with empty file path should never be filtered');
    }

    #[Test]
    public function itFiltersGlobPattern(): void
    {
        $filter = new PathExclusionFilter(new PathMatcher(['src/Metrics/*Visitor.php']));

        $violation = $this->createViolation('src/Metrics/CboVisitor.php');

        self::assertFalse($filter->shouldInclude($violation), 'Violation matching glob pattern should be suppressed');
    }

    #[Test]
    public function itPassesWhenNoPrefixes(): void
    {
        $filter = new PathExclusionFilter(new PathMatcher([]));

        $violation = $this->createViolation('src/Entity/User.php');

        self::assertTrue($filter->shouldInclude($violation), 'Empty PathMatcher should not filter any violations');
    }

    private function createViolation(string $file): Violation
    {
        return new Violation(
            location: new Location($file, 10),
            symbolPath: SymbolPath::forClass('App\\Entity', 'User'),
            ruleName: 'test.rule',
            violationCode: 'test.rule',
            message: 'Test',
            severity: Severity::Warning,
            metricValue: 5,
        );
    }
}

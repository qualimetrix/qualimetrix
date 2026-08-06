<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Support\Violation;

use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;

/**
 * Builds the violations the baseline tests reason about, so each test states
 * only the parts it is actually about.
 */
final class ViolationFactory
{
    /**
     * A finding on a declared `magnitude` channel.
     */
    public static function magnitude(
        SymbolPath $symbolPath,
        int|float $metricValue,
        string $ruleName = 'complexity.cyclomatic',
        string $violationCode = 'complexity.cyclomatic.method',
    ): Violation {
        return new Violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 42),
            symbolPath: $symbolPath,
            ruleName: $ruleName,
            violationCode: $violationCode,
            message: 'magnitude finding',
            severity: Severity::Warning,
            metricValue: $metricValue,
        );
    }

    /**
     * A finding on a declared `occurrence` channel.
     */
    public static function occurrence(
        SymbolPath $symbolPath,
        string $ruleName = 'code-smell.goto',
        string $violationCode = 'code-smell.goto',
    ): Violation {
        return new Violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 7),
            symbolPath: $symbolPath,
            ruleName: $ruleName,
            violationCode: $violationCode,
            message: 'occurrence finding',
            severity: Severity::Warning,
            metricValue: 1.0,
        );
    }

    /**
     * A finding on an edge-bearing `occurrence` channel — the shape that
     * makes the dependency edge part of the identity.
     */
    public static function edge(
        SymbolPath $symbolPath,
        SymbolPath $target,
        DependencyType $type = DependencyType::New_,
    ): Violation {
        return new Violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 11),
            symbolPath: $symbolPath,
            ruleName: 'architecture.layer-violation',
            violationCode: 'architecture.layer-violation',
            message: 'forbidden dependency',
            severity: Severity::Error,
            dependencyTarget: $target,
            dependencyType: $type,
        );
    }
}

<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Support;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;

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
        string $violationCode = 'complexity.cyclomatic.callable',
        ?MetricSubject $subject = null,
    ): Violation {
        return new Violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 42),
            subject: $subject ?? self::subjectFor($symbolPath, 42),
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
        ?MetricSubject $subject = null,
    ): Violation {
        return new Violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 7),
            subject: $subject ?? self::subjectFor($symbolPath, 7),
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
        ?MetricSubject $subject = null,
    ): Violation {
        return new Violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 11),
            subject: $subject ?? self::subjectFor($symbolPath, 11),
            symbolPath: $symbolPath,
            ruleName: 'architecture.layer-violation',
            violationCode: 'architecture.layer-violation',
            message: 'forbidden dependency',
            severity: Severity::Error,
            dependencyTarget: $target,
            dependencyType: $type,
        );
    }

    private static function subjectFor(SymbolPath $symbolPath, int $startFilePos): MetricSubject
    {
        return match ($symbolPath->getType()) {
            SymbolType::File, SymbolType::Namespace_, SymbolType::Project => MetricSubject::aggregate($symbolPath),
            SymbolType::Class_, SymbolType::Method, SymbolType::Function_ => MetricSubject::declaration(
                DeclarationPath::of($symbolPath, RelativePath::fromString('src/Foo.php'), DeclarationOrdinal::fromRank(0)),
            ),
        };
    }
}

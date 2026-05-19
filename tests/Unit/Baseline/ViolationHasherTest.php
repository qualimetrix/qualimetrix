<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Baseline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Baseline\ViolationHasher;
use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;

#[CoversClass(ViolationHasher::class)]
final class ViolationHasherTest extends TestCase
{
    private ViolationHasher $hasher;

    protected function setUp(): void
    {
        $this->hasher = new ViolationHasher();
    }

    #[Test]
    public function itGeneratesConsistentHash(): void
    {
        $violation = $this->createViolation(
            line: 42,
            message: 'Complexity 15 exceeds 10',
        );

        $hash1 = $this->hasher->hash($violation);
        $hash2 = $this->hasher->hash($violation);

        self::assertSame($hash1, $hash2, 'Hash should be consistent');
        self::assertSame(16, \strlen($hash1), 'Hash should be 16 characters long');
    }

    #[Test]
    public function itHashStableAcrossLineChanges(): void
    {
        $violation1 = $this->createViolation(line: 45, message: 'Complexity 15 exceeds 10');
        $violation2 = $this->createViolation(line: 55, message: 'Complexity 15 exceeds 10');

        $hash1 = $this->hasher->hash($violation1);
        $hash2 = $this->hasher->hash($violation2);

        self::assertSame(
            $hash1,
            $hash2,
            'Hash should be stable when line changes (line drift)',
        );
    }

    #[Test]
    public function itHashStableAcrossMessageChanges(): void
    {
        $violation1 = $this->createViolation(line: 42, message: 'Complexity 15 exceeds 10');
        $violation2 = $this->createViolation(line: 42, message: 'Completely different message text');

        $hash1 = $this->hasher->hash($violation1);
        $hash2 = $this->hasher->hash($violation2);

        self::assertSame(
            $hash1,
            $hash2,
            'Hash should be stable when message changes (only violationCode matters)',
        );
    }

    #[Test]
    public function itHashChangesWhenViolationCodeChanges(): void
    {
        $violation1 = new Violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 42),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity',
            violationCode: 'complexity.method',
            message: 'Test message',
            severity: Severity::Warning,
        );

        $violation2 = new Violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 42),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity',
            violationCode: 'complexity.class',
            message: 'Test message',
            severity: Severity::Warning,
        );

        $hash1 = $this->hasher->hash($violation1);
        $hash2 = $this->hasher->hash($violation2);

        self::assertNotSame(
            $hash1,
            $hash2,
            'Hash should change when violationCode changes',
        );
    }

    #[Test]
    public function itSameViolationCodeProducesSameHash(): void
    {
        $violation1 = new Violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 42),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity',
            violationCode: 'complexity.method',
            message: 'First message',
            severity: Severity::Warning,
        );

        $violation2 = new Violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 99),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity',
            violationCode: 'complexity.method',
            message: 'Completely different message',
            severity: Severity::Error,
        );

        $hash1 = $this->hasher->hash($violation1);
        $hash2 = $this->hasher->hash($violation2);

        self::assertSame(
            $hash1,
            $hash2,
            'Same violationCode with different message/severity/line should produce same hash',
        );
    }

    #[Test]
    public function itHashStableAcrossSeverityChanges(): void
    {
        $violation1 = new Violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 42),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Complexity 15 exceeds 10',
            severity: Severity::Warning,
        );

        $violation2 = new Violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 42),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Complexity 15 exceeds 10',
            severity: Severity::Error,
        );

        $hash1 = $this->hasher->hash($violation1);
        $hash2 = $this->hasher->hash($violation2);

        self::assertSame(
            $hash1,
            $hash2,
            'Hash should be stable when severity changes',
        );
    }

    #[Test]
    public function itHashChangesWhenRuleChanges(): void
    {
        $violation1 = new Violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 42),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Test message',
            severity: Severity::Warning,
        );

        $violation2 = new Violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 42),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'coupling',
            violationCode: 'coupling',
            message: 'Test message',
            severity: Severity::Warning,
        );

        $hash1 = $this->hasher->hash($violation1);
        $hash2 = $this->hasher->hash($violation2);

        self::assertNotSame(
            $hash1,
            $hash2,
            'Hash should change when rule changes',
        );
    }

    #[Test]
    public function itHashChangesWhenMethodNameChanges(): void
    {
        $violation1 = new Violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 42),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'calculate'),
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Test message',
            severity: Severity::Warning,
        );

        $violation2 = new Violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 42),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'process'),
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Test message',
            severity: Severity::Warning,
        );

        $hash1 = $this->hasher->hash($violation1);
        $hash2 = $this->hasher->hash($violation2);

        self::assertNotSame(
            $hash1,
            $hash2,
            'Hash should change when method name changes',
        );
    }

    #[Test]
    public function itHashChangesWhenClassChanges(): void
    {
        $violation1 = new Violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 42),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Test message',
            severity: Severity::Warning,
        );

        $violation2 = new Violation(
            location: new Location(RelativePath::fromString('src/Baz.php'), 42),
            symbolPath: SymbolPath::forMethod('App', 'Baz', 'bar'),
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Test message',
            severity: Severity::Warning,
        );

        $hash1 = $this->hasher->hash($violation1);
        $hash2 = $this->hasher->hash($violation2);

        self::assertNotSame(
            $hash1,
            $hash2,
            'Hash should change when class changes',
        );
    }

    #[Test]
    public function itHashForClassLevelViolation(): void
    {
        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/Service/UserService.php'), 10),
            symbolPath: SymbolPath::forClass('App\Service', 'UserService'),
            ruleName: 'class-size',
            violationCode: 'class-size',
            message: 'Class has 150 lines',
            severity: Severity::Warning,
        );

        $hash = $this->hasher->hash($violation);

        self::assertSame(16, \strlen($hash));
    }

    #[Test]
    public function itHashForNamespaceLevelViolation(): void
    {
        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/Service/UserService.php')),
            symbolPath: SymbolPath::forNamespace('App\Service'),
            ruleName: 'namespace-size',
            violationCode: 'namespace-size',
            message: 'Namespace has 50 classes',
            severity: Severity::Warning,
        );

        $hash = $this->hasher->hash($violation);

        self::assertSame(16, \strlen($hash));
    }

    #[Test]
    public function itHashForFileLevelViolation(): void
    {
        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/bootstrap.php')),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('src/bootstrap.php')),
            ruleName: 'file-length',
            violationCode: 'file-length',
            message: 'File has 500 lines',
            severity: Severity::Warning,
        );

        $hash = $this->hasher->hash($violation);

        self::assertSame(16, \strlen($hash));
    }

    /**
     * Regression pin (CCN-style, method-level violation).
     *
     * Pins the legacy hash format for a method-level violation with no dependency
     * payload. Any change to the payload composition or hashing path for non-dep
     * violations MUST break this test — it guards backward-compatible baselines.
     *
     * Payload: 'complexity.cyclomatic|App\Service|UserService|calculate|complexity.cyclomatic'
     *   xxh3   (first 16 chars): 5c20ffa65ac250af
     *   sha256 (first 16 chars): 2db0f10a4622890d
     */
    #[Test]
    public function itRegressionPinForMethodLevelViolation(): void
    {
        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/Service/UserService.php'), 42),
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'Cyclomatic complexity 15 exceeds threshold 10',
            severity: Severity::Warning,
            metricValue: 15,
        );

        $expected = \in_array('xxh3', hash_algos(), true)
            ? '5c20ffa65ac250af'
            : '2db0f10a4622890d';

        self::assertSame($expected, $this->hasher->hash($violation));
    }

    /**
     * Regression pin (LCOM-style, class-level violation).
     *
     * Pins the legacy hash format for a class-level violation (member is null,
     * stored as empty string in the payload).
     *
     * Payload: 'cohesion.lcom4|App\Service|OrderProcessor||cohesion.lcom4'
     *   xxh3   (first 16 chars): 3cfded211c0e63b3
     *   sha256 (first 16 chars): fc6229c5c897635b
     */
    #[Test]
    public function itRegressionPinForClassLevelViolation(): void
    {
        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/Service/OrderProcessor.php'), 10),
            symbolPath: SymbolPath::forClass('App\Service', 'OrderProcessor'),
            ruleName: 'cohesion.lcom4',
            violationCode: 'cohesion.lcom4',
            message: 'LCOM4 = 4 exceeds threshold 2',
            severity: Severity::Warning,
            metricValue: 4,
        );

        $expected = \in_array('xxh3', hash_algos(), true)
            ? '3cfded211c0e63b3'
            : 'fc6229c5c897635b';

        self::assertSame($expected, $this->hasher->hash($violation));
    }

    #[Test]
    public function itNullDependencyTargetMatchesRegressionPin(): void
    {
        // Same shape as the CCN regression pin but explicit null dependency fields.
        // Asserts that explicitly passing null dependencyTarget/dependencyType is
        // byte-for-byte equivalent to not passing them at all.
        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/Service/UserService.php'), 42),
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'Cyclomatic complexity 15 exceeds threshold 10',
            severity: Severity::Warning,
            metricValue: 15,
            dependencyTarget: null,
            dependencyType: null,
        );

        $expected = \in_array('xxh3', hash_algos(), true)
            ? '5c20ffa65ac250af'
            : '2db0f10a4622890d';

        self::assertSame($expected, $this->hasher->hash($violation));
    }

    #[Test]
    public function itDependencyHashStableAcrossLineChanges(): void
    {
        $violation1 = $this->createDependencyViolation(
            line: 12,
            target: SymbolPath::forClass('App\Repository', 'UserRepository'),
            type: DependencyType::TypeHint,
        );
        $violation2 = $this->createDependencyViolation(
            line: 87,
            target: SymbolPath::forClass('App\Repository', 'UserRepository'),
            type: DependencyType::TypeHint,
        );

        self::assertSame(
            $this->hasher->hash($violation1),
            $this->hasher->hash($violation2),
            'Dependency hash must be stable across line drift',
        );
    }

    #[Test]
    public function itDependencyHashChangesWhenTargetChanges(): void
    {
        $violation1 = $this->createDependencyViolation(
            line: 12,
            target: SymbolPath::forClass('App\Repository', 'UserRepository'),
            type: DependencyType::TypeHint,
        );
        $violation2 = $this->createDependencyViolation(
            line: 12,
            target: SymbolPath::forClass('App\Repository', 'OrderRepository'),
            type: DependencyType::TypeHint,
        );

        self::assertNotSame(
            $this->hasher->hash($violation1),
            $this->hasher->hash($violation2),
            'Dependency hash must change when target symbol changes',
        );
    }

    #[Test]
    public function itDependencyHashChangesWhenDependencyTypeChanges(): void
    {
        $target = SymbolPath::forClass('App\Repository', 'UserRepository');

        $violation1 = $this->createDependencyViolation(
            line: 12,
            target: $target,
            type: DependencyType::TypeHint,
        );
        $violation2 = $this->createDependencyViolation(
            line: 12,
            target: $target,
            type: DependencyType::New_,
        );

        self::assertNotSame(
            $this->hasher->hash($violation1),
            $this->hasher->hash($violation2),
            'Dependency hash must change when dependency type changes',
        );
    }

    #[Test]
    public function itDependencyHashDiffersFromNonDependencyHashForSameSource(): void
    {
        $nonDep = new Violation(
            location: new Location(RelativePath::fromString('src/Controller/UserController.php'), 12),
            symbolPath: SymbolPath::forClass('App\Controller', 'UserController'),
            ruleName: 'architecture.layer-violation',
            violationCode: 'architecture.layer-violation',
            message: 'Layer violation',
            severity: Severity::Warning,
        );

        $dep = $this->createDependencyViolation(
            line: 12,
            target: SymbolPath::forClass('App\Repository', 'UserRepository'),
            type: DependencyType::TypeHint,
        );

        self::assertNotSame(
            $this->hasher->hash($nonDep),
            $this->hasher->hash($dep),
            'A dependency-aware violation must hash differently than a non-dep violation '
                . 'with the same source identity, otherwise per-use-site dedup would collide '
                . 'with legacy entries.',
        );
    }

    private function createViolation(int $line, string $message): Violation
    {
        return new Violation(
            location: new Location(RelativePath::fromString('src/Service/UserService.php'), $line),
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            ruleName: 'cyclomatic-complexity',
            violationCode: 'cyclomatic-complexity',
            message: $message,
            severity: Severity::Warning,
            metricValue: 15,
        );
    }

    private function createDependencyViolation(
        int $line,
        SymbolPath $target,
        DependencyType $type,
    ): Violation {
        return new Violation(
            location: new Location(RelativePath::fromString('src/Controller/UserController.php'), $line),
            symbolPath: SymbolPath::forClass('App\Controller', 'UserController'),
            ruleName: 'architecture.layer-violation',
            violationCode: 'architecture.layer-violation',
            message: 'Layer "controller" must not depend on layer "repository"',
            severity: Severity::Warning,
            dependencyTarget: $target,
            dependencyType: $type,
        );
    }
}

<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\AcceptedLevel;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Formatter\GitLabCodeQualityFormatter;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\ReportBuilder;

#[CoversClass(GitLabCodeQualityFormatter::class)]
final class GitLabCodeQualityFormatterTest extends TestCase
{
    private GitLabCodeQualityFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new GitLabCodeQualityFormatter();
    }

    #[Test]
    public function itReturnsGitlabAsName(): void
    {
        self::assertSame('gitlab', $this->formatter->getName());
    }

    #[Test]
    public function itReturnsValidJson(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(10)
            ->filesSkipped(0)
            ->duration(0.5)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());

        self::assertJson($output);
    }

    #[Test]
    public function itFormatsEmptyReport(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(42)
            ->filesSkipped(0)
            ->duration(0.15)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        // Empty report should return empty array
        self::assertIsArray($data);
        self::assertSame([], $data);
    }

    #[Test]
    public function itFormatsReportWithViolations(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(self::violation(
                location: new Location(RelativePath::fromString('src/Service/UserService.php'), 42),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculateDiscount'),
                ruleName: 'cyclomatic-complexity',
                violationCode: 'cyclomatic-complexity',
                message: 'Cyclomatic complexity of 25 exceeds threshold',
                severity: Severity::Error,
                metricValue: 25,
            ))
            ->addViolation(self::violation(
                location: new Location(RelativePath::fromString('src/Service/UserService.php'), 120),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'processOrder'),
                ruleName: 'cyclomatic-complexity',
                violationCode: 'cyclomatic-complexity',
                message: 'Cyclomatic complexity of 12 exceeds threshold',
                severity: Severity::Warning,
                metricValue: 12,
            ))
            ->filesAnalyzed(42)
            ->filesSkipped(1)
            ->duration(0.23)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        // Should have 2 issues
        self::assertCount(2, $data);

        // First issue
        $issue1 = $data[0];
        self::assertSame('Cyclomatic complexity of 25 exceeds threshold', $issue1['description']);
        self::assertSame('cyclomatic-complexity', $issue1['check_name']);
        self::assertSame('critical', $issue1['severity']);
        self::assertSame('src/Service/UserService.php', $issue1['location']['path']);
        self::assertSame(42, $issue1['location']['lines']['begin']);
        self::assertArrayHasKey('fingerprint', $issue1);

        // Second issue
        $issue2 = $data[1];
        self::assertSame('Cyclomatic complexity of 12 exceeds threshold', $issue2['description']);
        self::assertSame('cyclomatic-complexity', $issue2['check_name']);
        self::assertSame('major', $issue2['severity']);
        self::assertSame('src/Service/UserService.php', $issue2['location']['path']);
        self::assertSame(120, $issue2['location']['lines']['begin']);
        self::assertArrayHasKey('fingerprint', $issue2);
    }

    #[Test]
    public function itMapsSeverityCorrectly(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(self::violation(
                location: new Location(RelativePath::fromString('src/A.php'), 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Error violation',
                severity: Severity::Error,
            ))
            ->addViolation(self::violation(
                location: new Location(RelativePath::fromString('src/B.php'), 20),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Warning violation',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        // Verify GitLab severity mapping
        self::assertSame('critical', $data[0]['severity']);
        self::assertSame('major', $data[1]['severity']);
    }

    #[Test]
    public function itMapsInfoSeverityToInfo(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(self::violation(
                location: new Location(RelativePath::fromString('src/A.php'), 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'architecture.coverage',
                violationCode: 'architecture.coverage',
                message: 'Class is not assigned to a layer',
                severity: Severity::Info,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('info', $data[0]['severity']);
    }

    #[Test]
    public function itGeneratesStableFingerprint(): void
    {
        $violation = self::violation(
            location: new Location(RelativePath::fromString('src/Service/UserService.php'), 42),
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            ruleName: 'cyclomatic-complexity',
            violationCode: 'cyclomatic-complexity',
            message: 'Cyclomatic complexity of 25 exceeds threshold',
            severity: Severity::Error,
            metricValue: 25,
        );

        $report = ReportBuilder::create()
            ->addViolation($violation)
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        // Format twice
        $output1 = $this->formatter->format($report, new FormatterContext());
        $output2 = $this->formatter->format($report, new FormatterContext());

        $data1 = json_decode($output1, true, 512, \JSON_THROW_ON_ERROR);
        $data2 = json_decode($output2, true, 512, \JSON_THROW_ON_ERROR);

        // Fingerprint should be identical
        self::assertSame($data1[0]['fingerprint'], $data2[0]['fingerprint']);

        // Verify fingerprint format (MD5 hash = 32 hex characters)
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $data1[0]['fingerprint']);
    }

    #[Test]
    public function itFingerprintsDuplicateLogicalDeclarationsByTheirCanonicalSubjects(): void
    {
        $logical = SymbolPath::forMethod('App\\Service', 'DuplicateService', 'run');
        $make = static fn(int $startFilePos): Violation => self::violation(
            location: new Location(RelativePath::fromString('src/Service/DuplicateService.php'), 42),
            subject: MetricSubject::declaration(new DeclarationPath(
                $logical,
                RelativePath::fromString('src/Service/DuplicateService.php'),
                $startFilePos,
            )),
            symbolPath: $logical,
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.callable',
            message: 'Same message must not participate in the fingerprint',
            severity: Severity::Warning,
        );

        $data = json_decode($this->formatter->format(
            ReportBuilder::create()->addViolations([$make(101), $make(202)])->build(),
            new FormatterContext(),
        ), true, 512, \JSON_THROW_ON_ERROR);

        self::assertNotSame($data[0]['fingerprint'], $data[1]['fingerprint']);

        $unrelated = self::violation(
            location: new Location(RelativePath::fromString('src/Service/UnrelatedService.php'), 5),
            symbolPath: SymbolPath::forMethod('App\\Service', 'UnrelatedService', 'run'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.callable',
            message: 'An unrelated finding',
            severity: Severity::Warning,
        );
        $withUnrelated = json_decode($this->formatter->format(
            ReportBuilder::create()->addViolations([$make(101), $unrelated])->build(),
            new FormatterContext(),
        ), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame($data[0]['fingerprint'], $withUnrelated[0]['fingerprint']);
    }

    #[Test]
    public function itKeepsLegacyFingerprintsAndSeparatesTargetOnlyEdges(): void
    {
        $alpha = SymbolPath::forClass('App', 'Alpha');
        $beta = SymbolPath::forClass('App', 'Beta');
        $violations = [
            self::edgeViolation('no-edge'),
            self::edgeViolation('untyped-alpha', $alpha),
            self::edgeViolation('untyped-beta', $beta),
            self::edgeViolation('typed-alpha', $alpha, DependencyType::New_),
        ];
        $data = json_decode($this->formatter->format(
            ReportBuilder::create()->addViolations($violations)->build(),
            new FormatterContext(),
        ), true, 512, \JSON_THROW_ON_ERROR);
        $fingerprints = array_column($data, 'fingerprint', 'description');
        $prefix = 'r#r.edge:file:src/Foo.php';

        self::assertSame(md5($prefix), $fingerprints['no-edge']);
        self::assertSame(md5($prefix . ':untyped-edge:15:class:App\\Alpha'), $fingerprints['untyped-alpha']);
        self::assertSame(md5($prefix . ':untyped-edge:14:class:App\\Beta'), $fingerprints['untyped-beta']);
        self::assertSame(md5($prefix . ':new:class:App\\Alpha'), $fingerprints['typed-alpha']);
        self::assertCount(4, array_unique($fingerprints));
    }

    #[Test]
    public function itGeneratesDifferentFingerprintsForDifferentViolations(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(self::violation(
                location: new Location(RelativePath::fromString('src/A.php'), 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test-rule',
                violationCode: 'test-rule',
                message: 'First violation',
                severity: Severity::Warning,
            ))
            ->addViolation(self::violation(
                location: new Location(RelativePath::fromString('src/B.php'), 20),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'test-rule',
                violationCode: 'test-rule',
                message: 'Second violation',
                severity: Severity::Warning,
            ))
            ->addViolation(self::violation(
                location: new Location(RelativePath::fromString('src/A.php'), 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'other-rule',
                violationCode: 'other-rule',
                message: 'Same location, different rule',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        // All fingerprints should be unique
        $fingerprints = array_map(fn(array $issue): string => $issue['fingerprint'], $data);
        $uniqueFingerprints = array_unique($fingerprints);

        self::assertCount(3, $fingerprints);
        self::assertCount(3, $uniqueFingerprints);
    }

    #[Test]
    public function itKeepsTheFingerprintStableWhenOnlyTheMessageChanges(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(self::violation(
                location: new Location(RelativePath::fromString('src/A.php'), 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test-rule',
                violationCode: 'test-rule',
                message: 'First violation on same line',
                severity: Severity::Warning,
            ))
            ->addViolation(self::violation(
                location: new Location(RelativePath::fromString('src/A.php'), 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test-rule',
                violationCode: 'test-rule',
                message: 'Second violation on same line',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        // Presentation text does not participate in the canonical identity.
        self::assertCount(2, $data);
        self::assertSame($data[0]['fingerprint'], $data[1]['fingerprint']);
    }

    #[Test]
    public function itFormatsNamespaceLevelViolation(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(self::violation(
                location: new Location(RelativePath::fromString('src/Service/UserService.php')),
                symbolPath: SymbolPath::forNamespace('App\Service'),
                ruleName: 'namespace-size',
                violationCode: 'namespace-size',
                message: 'Namespace contains 16 classes (threshold: 10)',
                severity: Severity::Error,
                metricValue: 16,
            ))
            ->filesAnalyzed(10)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $issue = $data[0];
        // Namespace violations without line should default to line 1
        self::assertSame(1, $issue['location']['lines']['begin']);
    }

    #[Test]
    public function itProducesStructureMatchingGitLabSpec(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(self::violation(
                location: new Location(RelativePath::fromString('src/Service/UserService.php'), 45),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'foo'),
                ruleName: 'complexity',
                violationCode: 'complexity',
                message: 'Method foo() has complexity 25, exceeds 10',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $issue = $data[0];

        // Verify all required GitLab Code Quality fields are present
        self::assertArrayHasKey('description', $issue);
        self::assertArrayHasKey('check_name', $issue);
        self::assertArrayHasKey('fingerprint', $issue);
        self::assertArrayHasKey('severity', $issue);
        self::assertArrayHasKey('location', $issue);

        // Verify location structure
        self::assertArrayHasKey('path', $issue['location']);
        self::assertArrayHasKey('lines', $issue['location']);
        self::assertArrayHasKey('begin', $issue['location']['lines']);

        // Verify no extra fields in location (GitLab spec only requires path and lines.begin)
        $location = $issue['location'];
        \assert(\is_array($location));
        self::assertCount(2, $location);
        $lines = $location['lines'];
        \assert(\is_array($lines));
        self::assertCount(1, $lines);
    }

    #[Test]
    public function itUsesViolationCodeAsCheckName(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(self::violation(
                location: new Location(RelativePath::fromString('src/Foo.php'), 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'complexity',
                violationCode: 'complexity.method',
                message: 'Too complex',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $issue = $data[0];
        self::assertSame('complexity.method', $issue['check_name']);
    }

    #[Test]
    public function itUsesDescriptiveSyntheticPathForProjectLevelViolation(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(self::violation(
                location: Location::none(),
                symbolPath: SymbolPath::forNamespace('App'),
                ruleName: 'architecture',
                violationCode: 'architecture.circular',
                message: 'Circular dependency detected',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(10)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $issue = $data[0];
        // Project-level violations must use a descriptive synthetic path, not '.' or ''
        // '.' is not a valid file path per GitLab Code Quality spec
        self::assertSame('_project', $issue['location']['path']);
        self::assertNotSame('.', $issue['location']['path']);
        self::assertNotSame('', $issue['location']['path']);
    }

    #[Test]
    public function itReturnsNoneAsDefaultGroupBy(): void
    {
        self::assertSame(GroupBy::None, $this->formatter->getDefaultGroupBy());
    }

    #[Test]
    public function itIncludesTheAcceptedLevelInTheDescriptionOnABreach(): void
    {
        $violation = self::violation(
            location: new Location(RelativePath::fromString('src/Service/UserService.php'), 42),
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            ruleName: 'cyclomatic-complexity',
            violationCode: 'cyclomatic-complexity',
            message: 'Cyclomatic complexity of 31 exceeds threshold',
            severity: Severity::Warning,
            metricValue: 31,
        );

        $report = ReportBuilder::create()
            ->addViolation($violation->reportedAsBreach(new AcceptedLevel([25.0], 1)))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(
            'Cyclomatic complexity of 31 exceeds threshold (accepted at 25, now 31)',
            $data[0]['description'],
        );

        // The fingerprint must hash the unmodified message so it stays
        // stable across the run where a breach first appears.
        $plainReport = ReportBuilder::create()
            ->addViolation($violation)
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();
        $plainOutput = $this->formatter->format($plainReport, new FormatterContext());
        $plainData = json_decode($plainOutput, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame($plainData[0]['fingerprint'], $data[0]['fingerprint']);
    }

    #[Test]
    public function itKeepsAlreadyRelativePathUnchanged(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(self::violation(
                location: new Location(RelativePath::fromString('src/Service/UserService.php'), 42),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
                ruleName: 'cyclomatic-complexity',
                violationCode: 'cyclomatic-complexity',
                message: 'Too complex',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $context = new FormatterContext(basePath: '/home/user/project');
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('src/Service/UserService.php', $data[0]['location']['path']);
    }
    /**
     * Builds a violation fixture with an explicit declaration or aggregate
     * subject, preserving the production contract without hiding it behind a
     * legacy fallback.
     *
     * @param list<\Qualimetrix\Core\Violation\Location> $relatedLocations
     */
    private static function violation(
        \Qualimetrix\Core\Violation\Location $location,
        \Qualimetrix\Core\Symbol\SymbolPath $symbolPath,
        string $ruleName,
        string $violationCode,
        string $message,
        \Qualimetrix\Core\Violation\Severity $severity,
        int|float|null $metricValue = null,
        ?\Qualimetrix\Core\Rule\RuleLevel $level = null,
        array $relatedLocations = [],
        ?string $recommendation = null,
        int|float|null $threshold = null,
        ?\Qualimetrix\Core\Symbol\SymbolPath $dependencyTarget = null,
        ?\Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType $dependencyType = null,
        ?\Qualimetrix\Core\Violation\AcceptedLevel $acceptedLevel = null,
        ?\Qualimetrix\Core\Violation\OccurrenceKey $occurrenceKey = null,
        ?\Qualimetrix\Core\Symbol\MetricSubject $subject = null,
    ): Violation {
        $subject ??= match ($symbolPath->getType()) {
            \Qualimetrix\Core\Symbol\SymbolType::File,
            \Qualimetrix\Core\Symbol\SymbolType::Namespace_,
            \Qualimetrix\Core\Symbol\SymbolType::Project => \Qualimetrix\Core\Symbol\MetricSubject::aggregate($symbolPath),
            default => \Qualimetrix\Core\Symbol\MetricSubject::declaration(new \Qualimetrix\Core\Symbol\DeclarationPath(
                $symbolPath,
                $location->file ?? \Qualimetrix\Core\Path\RelativePath::fromString('tests/Reporting/fixture.php'),
                $location->line ?? 0,
            )),
        };

        return new Violation(
            location: $location,
            subject: $subject,
            symbolPath: $symbolPath,
            ruleName: $ruleName,
            violationCode: $violationCode,
            message: $message,
            severity: $severity,
            metricValue: $metricValue,
            level: $level,
            relatedLocations: $relatedLocations,
            recommendation: $recommendation,
            threshold: $threshold,
            dependencyTarget: $dependencyTarget,
            dependencyType: $dependencyType,
            acceptedLevel: $acceptedLevel,
            occurrenceKey: $occurrenceKey,
        );
    }

    private static function edgeViolation(
        string $message,
        ?SymbolPath $target = null,
        ?DependencyType $type = null,
    ): Violation {
        return self::violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 1),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('src/Foo.php')),
            ruleName: 'r',
            violationCode: 'r.edge',
            message: $message,
            severity: Severity::Warning,
            dependencyTarget: $target,
            dependencyType: $type,
        );
    }

}

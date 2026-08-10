<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Baseline;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Baseline\BaselineEntryParser;
use Qualimetrix\Baseline\BaselineGenerator;
use Qualimetrix\Baseline\BaselineIdentity;
use Qualimetrix\Baseline\BaselineLoader;
use Qualimetrix\Baseline\BaselineWriter;
use Qualimetrix\Baseline\Filter\BaselineCeilingStage;
use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\OccurrenceKey;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Tests\Support\Time\FixedClock;
use Qualimetrix\Tests\Support\Violation\StubChannelDeclarationRegistry;
use RuntimeException;

/**
 * Integration test for baseline workflow.
 *
 * Tests the complete baseline lifecycle:
 * 1. Generate baseline from violations
 * 2. Write baseline to file
 * 3. Load baseline from file
 * 4. Filter violations using baseline
 * 5. Detect resolved violations
 */
final class BaselineWorkflowTest extends TestCase
{
    private string $tempDir;
    private string $baselinePath;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx_baseline_test_' . uniqid();
        mkdir($this->tempDir);
        $this->baselinePath = $this->tempDir . '/baseline.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->baselinePath)) {
            unlink($this->baselinePath);
        }
        $lockPath = $this->baselinePath . '.lock';
        if (file_exists($lockPath)) {
            unlink($lockPath);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    #[Test]
    public function itExecutesCompleteBaselineWorkflow(): void
    {
        $occurrenceKey = OccurrenceKey::semantic('goto', ['construct' => 'label']);
        // Create test violations, one on a declared magnitude channel and
        // one on a declared occurrence channel.
        $violations = [
            new Violation(
                subject: self::declarationSubject(SymbolPath::forMethod("App\\Service", "UserService", "calculateDiscount"), 45),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.callable',
                message: 'Complexity 15 exceeds threshold 10',
                severity: Severity::Warning,
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculateDiscount'),
                location: new Location(RelativePath::fromString(basename(__FILE__)), 45),
                metricValue: 15,
            ),
            new Violation(
                subject: self::declarationSubject(SymbolPath::forClass("App\\Service", "UserService"), 1),
                ruleName: 'code-smell.goto',
                violationCode: 'code-smell.goto',
                message: 'goto statement found',
                severity: Severity::Warning,
                symbolPath: SymbolPath::forClass('App\Service', 'UserService'),
                location: new Location(RelativePath::fromString(basename(__FILE__)), 1),
                occurrenceKey: $occurrenceKey,
            ),
        ];

        // Step 1: Generate baseline
        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $generator = new BaselineGenerator($declarations, new FixedClock());
        $baseline = $generator->generate($violations, ['src'])->baseline;

        self::assertSame(2, $baseline->count());
        $expectedIdentityKeys = array_map(
            static fn(Violation $violation): string => BaselineIdentity::forViolation($violation)->key(),
            $violations,
        );
        $expectedSelectors = array_map(
            static fn(Violation $violation): string => BaselineIdentity::forViolation($violation)->selector()->value,
            $violations,
        );
        self::assertSame(
            $expectedIdentityKeys,
            array_map(static fn($entry): string => $entry->identity->key(), $baseline->entries),
        );
        self::assertSame(
            $expectedSelectors,
            array_map(static fn($entry): string => $entry->selector()->value, $baseline->entries),
        );

        // Step 2: Write baseline to file
        $writer = new BaselineWriter();
        $writer->write($baseline, $this->baselinePath, AbsolutePath::fromString($this->tempDir));

        self::assertFileExists($this->baselinePath);
        $written = json_decode((string) file_get_contents($this->baselinePath), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(
            $occurrenceKey->value,
            $written['entries'][$violations[1]->subject->toCanonical()][0]['occurrence'],
        );

        // Step 3: Load baseline from file
        $loader = new BaselineLoader(new BaselineEntryParser($declarations));
        $loadedBaseline = $loader->load($this->baselinePath);

        self::assertSame($baseline->count(), $loadedBaseline->count());
        self::assertSame(0, \count($loadedBaseline->inertEntries));
        self::assertSame(
            $expectedIdentityKeys,
            array_map(static fn($entry): string => $entry->identity->key(), $loadedBaseline->entries),
        );
        self::assertSame(
            $expectedSelectors,
            array_map(static fn($entry): string => $entry->selector()->value, $loadedBaseline->entries),
        );

        // Step 4: Apply the baseline as a ceiling over the same findings
        $stage = new BaselineCeilingStage($loadedBaseline, $declarations);

        // Both groups are within what was captured, so neither is reported
        self::assertSame([], $stage->apply($violations)->violations);

        // Step 5: Test new violation (not in baseline)
        $newViolation = new Violation(
            subject: self::declarationSubject(SymbolPath::forMethod("App\\Service", "UserService", "processOrder"), 100),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.callable',
            message: 'Complexity 25 exceeds threshold 10',
            severity: Severity::Error,
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'processOrder'),
            location: new Location(RelativePath::fromString(basename(__FILE__)), 100),
            metricValue: 25,
        );

        // A finding no entry bounds is reported untouched
        self::assertSame([$newViolation], $stage->apply([$newViolation])->violations);
    }

    #[Test]
    public function itRejectsVersionTenWithFreshAnalysisAndDeliberateMappingGuidance(): void
    {
        file_put_contents($this->baselinePath, json_encode([
            'version' => 10,
            'generated' => '2026-08-05T12:00:00+03:00',
            'scope' => ['src'],
            'entries' => [],
        ], \JSON_THROW_ON_ERROR));

        $loader = new BaselineLoader(new BaselineEntryParser(StubChannelDeclarationRegistry::withDefaults()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Baseline version 10 cannot be converted automatically because declaration identity cannot be inferred '
            . 'from a logical symbol key. Run a fresh analysis, deliberately map or split accepted entries, then '
            . 'write a new version 11 baseline (or regenerate and review the accepted state).',
        );

        $loader->load($this->baselinePath);
    }

    #[Test]
    public function itRoundtripsTypedAndUntypedDependencyEdgesWithoutInventingANullType(): void
    {
        $source = SymbolPath::forClass('App\Service', 'UserService');
        $target = SymbolPath::forClass('App\Repository', 'UserRepository');
        $subject = self::declarationSubject($source, 11);
        $edgeViolation = static fn(?DependencyType $type): Violation => new Violation(
            subject: $subject,
            ruleName: 'architecture.layer-violation',
            violationCode: 'architecture.layer-violation',
            message: 'Forbidden dependency',
            severity: Severity::Error,
            symbolPath: $source,
            location: new Location(RelativePath::fromString(basename(__FILE__)), 11),
            dependencyTarget: $target,
            dependencyType: $type,
        );
        $typed = $edgeViolation(DependencyType::New_);
        $untyped = $edgeViolation(null);
        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $baseline = (new BaselineGenerator($declarations, new FixedClock()))
            ->generate([$typed, $untyped], ['src'])
            ->baseline;

        (new BaselineWriter())->write(
            $baseline,
            $this->baselinePath,
            AbsolutePath::fromString($this->tempDir),
        );

        $written = json_decode((string) file_get_contents($this->baselinePath), true, flags: \JSON_THROW_ON_ERROR);
        $subjectEntries = $written['entries'][$subject->toCanonical()];
        self::assertSame([
            [
                'channel' => 'architecture.layer-violation#architecture.layer-violation',
                'edge' => ['target' => $target->toCanonical()],
                'count' => 1,
            ],
            [
                'channel' => 'architecture.layer-violation#architecture.layer-violation',
                'edge' => ['target' => $target->toCanonical(), 'type' => DependencyType::New_->value],
                'count' => 1,
            ],
        ], $subjectEntries);
        self::assertArrayNotHasKey('type', $subjectEntries[0]['edge']);

        $loaded = (new BaselineLoader(new BaselineEntryParser($declarations)))->load($this->baselinePath);
        $expectedIdentities = [
            BaselineIdentity::forViolation($untyped),
            BaselineIdentity::forViolation($typed),
        ];
        self::assertSame(
            array_map(static fn(BaselineIdentity $identity): string => $identity->key(), $expectedIdentities),
            array_map(static fn($entry): string => $entry->identity->key(), $loaded->entries),
        );
        self::assertSame(
            array_map(static fn(BaselineIdentity $identity): string => $identity->selector()->value, $expectedIdentities),
            array_map(static fn($entry): string => $entry->selector()->value, $loaded->entries),
        );
    }

    #[Test]
    public function itDetectsResolvedViolations(): void
    {
        // Create initial violations and baseline
        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $generator = new BaselineGenerator($declarations, new FixedClock());

        $initialViolations = [
            new Violation(
                subject: self::declarationSubject(SymbolPath::forMethod("App\\Service", "UserService", "method1"), 10),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.callable',
                message: 'Complexity 15 exceeds threshold 10',
                severity: Severity::Warning,
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'method1'),
                location: new Location(RelativePath::fromString(basename(__FILE__)), 10),
                metricValue: 15,
            ),
            new Violation(
                subject: self::declarationSubject(SymbolPath::forMethod("App\\Service", "UserService", "method2"), 20),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.callable',
                message: 'Complexity 20 exceeds threshold 10',
                severity: Severity::Warning,
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'method2'),
                location: new Location(RelativePath::fromString(basename(__FILE__)), 20),
                metricValue: 20,
            ),
        ];

        $baseline = $generator->generate($initialViolations, ['src'])->baseline;
        $writer = new BaselineWriter();
        $writer->write($baseline, $this->baselinePath, AbsolutePath::fromString($this->tempDir));

        // Load baseline
        $loader = new BaselineLoader(new BaselineEntryParser($declarations));
        $loadedBaseline = $loader->load($this->baselinePath);
        $stage = new BaselineCeilingStage($loadedBaseline, $declarations);

        // Current violations: only method1 (method2 was fixed)
        $currentViolations = [
            new Violation(
                subject: self::declarationSubject(SymbolPath::forMethod("App\\Service", "UserService", "method1"), 10),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.callable',
                message: 'Complexity 15 exceeds threshold 10',
                severity: Severity::Warning,
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'method1'),
                location: new Location(RelativePath::fromString(basename(__FILE__)), 10),
                metricValue: 15,
            ),
        ];

        // Get resolved entries — measured over the very list the ceiling
        // judges, which is judgeAll()'s input
        $resolved = $stage->judgeAll($currentViolations)->staleEntries;

        // Should detect that method2 was resolved
        self::assertCount(1, $resolved);
        self::assertSame(
            'declaration:callable:App\Service\UserService::method2@BaselineWorkflowTest.php:20',
            $resolved[0]->identity->subjectKey,
        );
    }

    #[Test]
    public function itRoundtripsFilePathPortability(): void
    {
        $projectRoot = $this->tempDir;

        // Create violations — file-level uses relative path (as the actual pipeline does)
        $violations = [
            new Violation(
                subject: MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString("src/Service.php"))),
                ruleName: 'code-smell.goto',
                violationCode: 'code-smell.goto',
                message: 'goto statement found',
                severity: Severity::Warning,
                symbolPath: SymbolPath::forFile(RelativePath::fromString('src/Service.php')),
                location: new Location(RelativePath::fromString('src/Service.php'), 1),
            ),
            new Violation(
                subject: MetricSubject::declaration(new DeclarationPath(SymbolPath::forMethod("App\\Service", "Service", "handle"), RelativePath::fromString("src/Service.php"), 10)),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.callable',
                message: 'Complexity 15',
                severity: Severity::Warning,
                symbolPath: SymbolPath::forMethod('App\Service', 'Service', 'handle'),
                location: new Location(RelativePath::fromString('src/Service.php'), 10),
                metricValue: 15,
            ),
        ];

        // Generate and write baseline
        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $generator = new BaselineGenerator($declarations, new FixedClock());
        $baseline = $generator->generate($violations, ['src'])->baseline;
        $writer = new BaselineWriter();
        $writer->write($baseline, $this->baselinePath, AbsolutePath::fromString($projectRoot));

        // Verify JSON contains relative file: path
        $data = json_decode((string) file_get_contents($this->baselinePath), true);
        self::assertArrayHasKey('file:src/Service.php', $data['entries']);
        // Method canonical should be unchanged
        self::assertArrayHasKey('declaration:callable:App\Service\Service::handle@src/Service.php:10', $data['entries']);

        // Load baseline — paths kept as-is (relative)
        $loader = new BaselineLoader(new BaselineEntryParser($declarations));
        $loadedBaseline = $loader->load($this->baselinePath);

        // The ceiling should accept the original violations
        $stage = new BaselineCeilingStage($loadedBaseline, $declarations);
        self::assertSame(
            [],
            $stage->apply($violations)->violations,
            'Both the file-level and the callable-level finding should be accepted by their entries',
        );
    }

    #[Test]
    public function itKeepsIdentityStableAcrossLineChanges(): void
    {
        // Same violation at different lines
        $violation1 = new Violation(
            subject: self::declarationSubject(SymbolPath::forMethod("App\\Service", "UserService", "calculate"), 40),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.callable',
            message: 'Complexity 15 exceeds threshold 10',
            severity: Severity::Warning,
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            location: new Location(RelativePath::fromString(basename(__FILE__)), 45),
            metricValue: 15,
        );

        $violation2 = new Violation(
            subject: self::declarationSubject(SymbolPath::forMethod("App\\Service", "UserService", "calculate"), 40),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.callable',
            message: 'Complexity 15 exceeds threshold 10',
            severity: Severity::Warning,
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            location: new Location(RelativePath::fromString(basename(__FILE__)), 100), // Different line
            metricValue: 15,
        );

        // Identity keys should be identical (line drift stability) — the
        // location does not participate in the identity at all.
        self::assertSame(
            BaselineIdentity::forViolation($violation1)->key(),
            BaselineIdentity::forViolation($violation2)->key(),
        );
    }

    #[Test]
    public function itKeepsIdentityStableAcrossMagnitudeChanges(): void
    {
        // Same violation with different numeric values
        $violation1 = new Violation(
            subject: self::declarationSubject(SymbolPath::forMethod("App\\Service", "UserService", "calculate"), 40),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.callable',
            message: 'Complexity 15 exceeds threshold 10',
            severity: Severity::Warning,
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            location: new Location(RelativePath::fromString(basename(__FILE__)), 45),
            metricValue: 15,
        );

        $violation2 = new Violation(
            subject: self::declarationSubject(SymbolPath::forMethod("App\\Service", "UserService", "calculate"), 40),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.callable',
            message: 'Complexity 25 exceeds threshold 20', // Different values
            severity: Severity::Warning,
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            location: new Location(RelativePath::fromString(basename(__FILE__)), 45),
            metricValue: 25,
        );

        // Identity keys should be identical — magnitude drift changes the
        // entry's recorded value, not which entry it belongs to.
        self::assertSame(
            BaselineIdentity::forViolation($violation1)->key(),
            BaselineIdentity::forViolation($violation2)->key(),
        );
    }

    #[Test]
    public function itChangesIdentityOnMethodRename(): void
    {
        $violation1 = new Violation(
            subject: self::declarationSubject(SymbolPath::forMethod("App\\Service", "UserService", "calculate"), 45),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.callable',
            message: 'Complexity 15 exceeds threshold 10',
            severity: Severity::Warning,
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            location: new Location(RelativePath::fromString(basename(__FILE__)), 45),
            metricValue: 15,
        );

        $violation2 = new Violation(
            subject: self::declarationSubject(SymbolPath::forMethod("App\\Service", "UserService", "compute"), 45),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.callable',
            message: 'Complexity 15 exceeds threshold 10',
            severity: Severity::Warning,
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'compute'), // Different method
            location: new Location(RelativePath::fromString(basename(__FILE__)), 45),
            metricValue: 15,
        );

        // Identity keys should be different (method name changed)
        self::assertNotSame(
            BaselineIdentity::forViolation($violation1)->key(),
            BaselineIdentity::forViolation($violation2)->key(),
        );
    }

    private static function declarationSubject(SymbolPath $symbolPath, int $startFilePos): MetricSubject
    {
        return MetricSubject::declaration(new DeclarationPath(
            $symbolPath,
            RelativePath::fromString(basename(__FILE__)),
            $startFilePos,
        ));
    }
}

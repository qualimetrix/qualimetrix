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
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Tests\Support\Time\FixedClock;
use Qualimetrix\Tests\Support\Violation\StubChannelDeclarationRegistry;

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
        // Create test violations, one on a declared magnitude channel and
        // one on a declared occurrence channel.
        $violations = [
            new Violation(
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.method',
                message: 'Complexity 15 exceeds threshold 10',
                severity: Severity::Warning,
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculateDiscount'),
                location: new Location(RelativePath::fromString(basename(__FILE__)), 45),
                metricValue: 15,
            ),
            new Violation(
                ruleName: 'code-smell.goto',
                violationCode: 'code-smell.goto',
                message: 'goto statement found',
                severity: Severity::Warning,
                symbolPath: SymbolPath::forClass('App\Service', 'UserService'),
                location: new Location(RelativePath::fromString(basename(__FILE__)), 1),
            ),
        ];

        // Step 1: Generate baseline
        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $generator = new BaselineGenerator($declarations, new FixedClock());
        $baseline = $generator->generate($violations, ['src'])->baseline;

        self::assertSame(2, $baseline->count());
        self::assertTrue($baseline->hasIdentity(BaselineIdentity::forViolation($violations[0])));
        self::assertTrue($baseline->hasIdentity(BaselineIdentity::forViolation($violations[1])));

        // Step 2: Write baseline to file
        $writer = new BaselineWriter();
        $writer->write($baseline, $this->baselinePath, AbsolutePath::fromString($this->tempDir));

        self::assertFileExists($this->baselinePath);

        // Step 3: Load baseline from file
        $loader = new BaselineLoader(new BaselineEntryParser($declarations));
        $loadedBaseline = $loader->load($this->baselinePath);

        self::assertSame($baseline->count(), $loadedBaseline->count());
        self::assertSame(0, \count($loadedBaseline->inertEntries));

        // Step 4: Apply the baseline as a ceiling over the same findings
        $stage = new BaselineCeilingStage($loadedBaseline, $declarations);

        // Both groups are within what was captured, so neither is reported
        self::assertSame([], $stage->apply($violations)->violations);

        // Step 5: Test new violation (not in baseline)
        $newViolation = new Violation(
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.method',
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
    public function itDetectsResolvedViolations(): void
    {
        // Create initial violations and baseline
        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $generator = new BaselineGenerator($declarations, new FixedClock());

        $initialViolations = [
            new Violation(
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.method',
                message: 'Complexity 15 exceeds threshold 10',
                severity: Severity::Warning,
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'method1'),
                location: new Location(RelativePath::fromString(basename(__FILE__)), 10),
                metricValue: 15,
            ),
            new Violation(
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.method',
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
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.method',
                message: 'Complexity 15 exceeds threshold 10',
                severity: Severity::Warning,
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'method1'),
                location: new Location(RelativePath::fromString(basename(__FILE__)), 10),
                metricValue: 15,
            ),
        ];

        // Get resolved entries — measured over the very list the ceiling
        // judged, which is the stage's input
        $resolved = $stage->staleEntriesOver($currentViolations);

        // Should detect that method2 was resolved
        self::assertCount(1, $resolved);
        self::assertSame(
            'method:App\Service\UserService::method2',
            $resolved[0]->identity->symbolKey,
        );
    }

    #[Test]
    public function itRoundtripsFilePathPortability(): void
    {
        $projectRoot = $this->tempDir;

        // Create violations — file-level uses relative path (as the actual pipeline does)
        $violations = [
            new Violation(
                ruleName: 'code-smell.goto',
                violationCode: 'code-smell.goto',
                message: 'goto statement found',
                severity: Severity::Warning,
                symbolPath: SymbolPath::forFile(RelativePath::fromString('src/Service.php')),
                location: new Location(RelativePath::fromString('src/Service.php'), 1),
            ),
            new Violation(
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic.method',
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
        self::assertArrayHasKey('method:App\Service\Service::handle', $data['entries']);

        // Load baseline — paths kept as-is (relative)
        $loader = new BaselineLoader(new BaselineEntryParser($declarations));
        $loadedBaseline = $loader->load($this->baselinePath);

        // The ceiling should accept the original violations
        $stage = new BaselineCeilingStage($loadedBaseline, $declarations);
        self::assertSame(
            [],
            $stage->apply($violations)->violations,
            'Both the file-level and the method-level finding should be accepted by their entries',
        );
    }

    #[Test]
    public function itKeepsIdentityStableAcrossLineChanges(): void
    {
        // Same violation at different lines
        $violation1 = new Violation(
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.method',
            message: 'Complexity 15 exceeds threshold 10',
            severity: Severity::Warning,
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            location: new Location(RelativePath::fromString(basename(__FILE__)), 45),
            metricValue: 15,
        );

        $violation2 = new Violation(
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.method',
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
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.method',
            message: 'Complexity 15 exceeds threshold 10',
            severity: Severity::Warning,
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            location: new Location(RelativePath::fromString(basename(__FILE__)), 45),
            metricValue: 15,
        );

        $violation2 = new Violation(
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.method',
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
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.method',
            message: 'Complexity 15 exceeds threshold 10',
            severity: Severity::Warning,
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            location: new Location(RelativePath::fromString(basename(__FILE__)), 45),
            metricValue: 15,
        );

        $violation2 = new Violation(
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.method',
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
}

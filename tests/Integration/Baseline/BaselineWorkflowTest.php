<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Baseline;

use PHPUnit\Framework\TestCase;
use Qualimetrix\Baseline\BaselineGenerator;
use Qualimetrix\Baseline\BaselineLoader;
use Qualimetrix\Baseline\BaselineWriter;
use Qualimetrix\Baseline\Filter\BaselineFilter;
use Qualimetrix\Baseline\ViolationHasher;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;

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
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testCompleteBaselineWorkflow(): void
    {
        // Create test violations
        $violations = [
            new Violation(
                ruleName: 'complexity',
                violationCode: 'complexity',
                message: 'Complexity 15 exceeds threshold 10',
                severity: Severity::Warning,
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculateDiscount'),
                location: new Location(__FILE__, 45),
            ),
            new Violation(
                ruleName: 'size',
                violationCode: 'size',
                message: 'Class has 300 lines of code',
                severity: Severity::Warning,
                symbolPath: SymbolPath::forClass('App\Service', 'UserService'),
                location: new Location(__FILE__, 1),
            ),
        ];

        // Step 1: Generate baseline
        $hasher = new ViolationHasher();
        $generator = new BaselineGenerator($hasher);
        $baseline = $generator->generate($violations);

        self::assertSame(2, $baseline->count());
        // Check that entries exist for both canonical paths
        self::assertArrayHasKey('method:App\Service\UserService::calculateDiscount', $baseline->entries);
        self::assertArrayHasKey('class:App\Service\UserService', $baseline->entries);

        // Step 2: Write baseline to file
        $writer = new BaselineWriter();
        $writer->write($baseline, $this->baselinePath);

        self::assertFileExists($this->baselinePath);

        // Step 3: Load baseline from file
        $loader = new BaselineLoader();
        $loadedBaseline = $loader->load($this->baselinePath);

        self::assertSame($baseline->count(), $loadedBaseline->count());
        self::assertSame($baseline->version, $loadedBaseline->version);

        // Step 4: Filter violations using baseline
        $filter = new BaselineFilter($loadedBaseline, $hasher);

        // Both violations should be filtered out (in baseline)
        self::assertFalse($filter->shouldInclude($violations[0]));
        self::assertFalse($filter->shouldInclude($violations[1]));

        // Step 5: Test new violation (not in baseline)
        $newViolation = new Violation(
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Complexity 25 exceeds threshold 10',
            severity: Severity::Error,
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'processOrder'),
            location: new Location(__FILE__, 100),
        );

        // New violation should NOT be filtered
        self::assertTrue($filter->shouldInclude($newViolation));
    }

    public function testResolvedViolationsDetection(): void
    {
        // Create initial violations and baseline
        $hasher = new ViolationHasher();
        $generator = new BaselineGenerator($hasher);

        $initialViolations = [
            new Violation(
                ruleName: 'complexity',
                violationCode: 'complexity',
                message: 'Complexity 15 exceeds threshold 10',
                severity: Severity::Warning,
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'method1'),
                location: new Location(__FILE__, 10),
            ),
            new Violation(
                ruleName: 'complexity',
                violationCode: 'complexity',
                message: 'Complexity 20 exceeds threshold 10',
                severity: Severity::Warning,
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'method2'),
                location: new Location(__FILE__, 20),
            ),
        ];

        $baseline = $generator->generate($initialViolations);
        $writer = new BaselineWriter();
        $writer->write($baseline, $this->baselinePath);

        // Load baseline
        $loader = new BaselineLoader();
        $loadedBaseline = $loader->load($this->baselinePath);
        $filter = new BaselineFilter($loadedBaseline, $hasher);

        // Current violations: only method1 (method2 was fixed)
        $currentViolations = [
            new Violation(
                ruleName: 'complexity',
                violationCode: 'complexity',
                message: 'Complexity 15 exceeds threshold 10',
                severity: Severity::Warning,
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'method1'),
                location: new Location(__FILE__, 10),
            ),
        ];

        // Get resolved violations
        $resolved = $filter->getResolvedFromBaseline($currentViolations);

        // Should detect that method2 was resolved
        $methodKey = 'method:App\Service\UserService::method2';
        self::assertArrayHasKey($methodKey, $resolved);
        self::assertCount(1, $resolved[$methodKey]);
    }

    public function testFilePathPortabilityRoundtrip(): void
    {
        $projectRoot = $this->tempDir;

        // Create violations — file-level uses relative path (as the actual pipeline does)
        $violations = [
            new Violation(
                ruleName: 'size.loc',
                violationCode: 'size.loc',
                message: 'File has 500 lines',
                severity: Severity::Warning,
                symbolPath: SymbolPath::forFile('src/Service.php'),
                location: new Location('src/Service.php', 1),
            ),
            new Violation(
                ruleName: 'complexity',
                violationCode: 'complexity',
                message: 'Complexity 15',
                severity: Severity::Warning,
                symbolPath: SymbolPath::forMethod('App\Service', 'Service', 'handle'),
                location: new Location('src/Service.php', 10),
            ),
        ];

        // Generate and write baseline
        $hasher = new ViolationHasher();
        $generator = new BaselineGenerator($hasher);
        $baseline = $generator->generate($violations);
        $writer = new BaselineWriter();
        $writer->write($baseline, $this->baselinePath, $projectRoot);

        // Verify JSON contains relative file: path
        $data = json_decode((string) file_get_contents($this->baselinePath), true);
        self::assertArrayHasKey('file:src/Service.php', $data['violations']);
        // Method canonical should be unchanged
        self::assertArrayHasKey('method:App\Service\Service::handle', $data['violations']);

        // Load baseline — paths kept as-is (relative)
        $loader = new BaselineLoader();
        $loadedBaseline = $loader->load($this->baselinePath);

        // Filter should match the original violations
        $filter = new BaselineFilter($loadedBaseline, $hasher);
        self::assertFalse($filter->shouldInclude($violations[0]), 'File-level violation should be filtered by baseline');
        self::assertFalse($filter->shouldInclude($violations[1]), 'Method-level violation should be filtered by baseline');
    }

    public function testStableHashAcrossLineChanges(): void
    {
        $hasher = new ViolationHasher();

        // Same violation at different lines
        $violation1 = new Violation(
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Complexity 15 exceeds threshold 10',
            severity: Severity::Warning,
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            location: new Location(__FILE__, 45),
        );

        $violation2 = new Violation(
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Complexity 15 exceeds threshold 10',
            severity: Severity::Warning,
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            location: new Location(__FILE__, 100), // Different line
        );

        // Hashes should be identical (line drift stability)
        self::assertSame($hasher->hash($violation1), $hasher->hash($violation2));
    }

    public function testStableHashAcrossValueChanges(): void
    {
        $hasher = new ViolationHasher();

        // Same violation with different numeric values
        $violation1 = new Violation(
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Complexity 15 exceeds threshold 10',
            severity: Severity::Warning,
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            location: new Location(__FILE__, 45),
        );

        $violation2 = new Violation(
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Complexity 25 exceeds threshold 20', // Different values
            severity: Severity::Warning,
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            location: new Location(__FILE__, 45),
        );

        // Hashes should be identical (value normalization)
        self::assertSame($hasher->hash($violation1), $hasher->hash($violation2));
    }

    public function testHashChangesOnMethodRename(): void
    {
        $hasher = new ViolationHasher();

        $violation1 = new Violation(
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Complexity 15 exceeds threshold 10',
            severity: Severity::Warning,
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            location: new Location(__FILE__, 45),
        );

        $violation2 = new Violation(
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Complexity 15 exceeds threshold 10',
            severity: Severity::Warning,
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'compute'), // Different method
            location: new Location(__FILE__, 45),
        );

        // Hashes should be different (method name changed)
        self::assertNotSame($hasher->hash($violation1), $hasher->hash($violation2));
    }
}

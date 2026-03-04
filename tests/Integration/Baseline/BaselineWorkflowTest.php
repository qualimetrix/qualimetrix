<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Integration\Baseline;

use AiMessDetector\Baseline\BaselineGenerator;
use AiMessDetector\Baseline\BaselineLoader;
use AiMessDetector\Baseline\BaselineWriter;
use AiMessDetector\Baseline\Filter\BaselineFilter;
use AiMessDetector\Baseline\ViolationHasher;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\SymbolPath;
use AiMessDetector\Core\Violation\Violation;
use PHPUnit\Framework\TestCase;

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
        $this->tempDir = sys_get_temp_dir() . '/aimd_baseline_test_' . uniqid();
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
                message: 'Complexity 15 exceeds threshold 10',
                severity: Severity::Warning,
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculateDiscount'),
                location: new Location(__FILE__, 45),
            ),
            new Violation(
                ruleName: 'size',
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

        $this->assertSame(2, $baseline->count());
        // Check that entries exist for both canonical paths
        $this->assertArrayHasKey('method:App\Service\UserService::calculateDiscount', $baseline->entries);
        $this->assertArrayHasKey('class:App\Service\UserService', $baseline->entries);

        // Step 2: Write baseline to file
        $writer = new BaselineWriter();
        $writer->write($baseline, $this->baselinePath);

        $this->assertFileExists($this->baselinePath);

        // Step 3: Load baseline from file
        $loader = new BaselineLoader();
        $loadedBaseline = $loader->load($this->baselinePath);

        $this->assertSame($baseline->count(), $loadedBaseline->count());
        $this->assertSame($baseline->version, $loadedBaseline->version);

        // Step 4: Filter violations using baseline
        $filter = new BaselineFilter($loadedBaseline, $hasher);

        // Both violations should be filtered out (in baseline)
        $this->assertFalse($filter->shouldInclude($violations[0]));
        $this->assertFalse($filter->shouldInclude($violations[1]));

        // Step 5: Test new violation (not in baseline)
        $newViolation = new Violation(
            ruleName: 'complexity',
            message: 'Complexity 25 exceeds threshold 10',
            severity: Severity::Error,
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'processOrder'),
            location: new Location(__FILE__, 100),
        );

        // New violation should NOT be filtered
        $this->assertTrue($filter->shouldInclude($newViolation));
    }

    public function testResolvedViolationsDetection(): void
    {
        // Create initial violations and baseline
        $hasher = new ViolationHasher();
        $generator = new BaselineGenerator($hasher);

        $initialViolations = [
            new Violation(
                ruleName: 'complexity',
                message: 'Complexity 15 exceeds threshold 10',
                severity: Severity::Warning,
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'method1'),
                location: new Location(__FILE__, 10),
            ),
            new Violation(
                ruleName: 'complexity',
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
        $this->assertArrayHasKey($methodKey, $resolved);
        $this->assertCount(1, $resolved[$methodKey]);
    }

    public function testStableHashAcrossLineChanges(): void
    {
        $hasher = new ViolationHasher();

        // Same violation at different lines
        $violation1 = new Violation(
            ruleName: 'complexity',
            message: 'Complexity 15 exceeds threshold 10',
            severity: Severity::Warning,
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            location: new Location(__FILE__, 45),
        );

        $violation2 = new Violation(
            ruleName: 'complexity',
            message: 'Complexity 15 exceeds threshold 10',
            severity: Severity::Warning,
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            location: new Location(__FILE__, 100), // Different line
        );

        // Hashes should be identical (line drift stability)
        $this->assertSame($hasher->hash($violation1), $hasher->hash($violation2));
    }

    public function testStableHashAcrossValueChanges(): void
    {
        $hasher = new ViolationHasher();

        // Same violation with different numeric values
        $violation1 = new Violation(
            ruleName: 'complexity',
            message: 'Complexity 15 exceeds threshold 10',
            severity: Severity::Warning,
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            location: new Location(__FILE__, 45),
        );

        $violation2 = new Violation(
            ruleName: 'complexity',
            message: 'Complexity 25 exceeds threshold 20', // Different values
            severity: Severity::Warning,
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            location: new Location(__FILE__, 45),
        );

        // Hashes should be identical (value normalization)
        $this->assertSame($hasher->hash($violation1), $hasher->hash($violation2));
    }

    public function testHashChangesOnMethodRename(): void
    {
        $hasher = new ViolationHasher();

        $violation1 = new Violation(
            ruleName: 'complexity',
            message: 'Complexity 15 exceeds threshold 10',
            severity: Severity::Warning,
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            location: new Location(__FILE__, 45),
        );

        $violation2 = new Violation(
            ruleName: 'complexity',
            message: 'Complexity 15 exceeds threshold 10',
            severity: Severity::Warning,
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'compute'), // Different method
            location: new Location(__FILE__, 45),
        );

        // Hashes should be different (method name changed)
        $this->assertNotSame($hasher->hash($violation1), $hasher->hash($violation2));
    }
}

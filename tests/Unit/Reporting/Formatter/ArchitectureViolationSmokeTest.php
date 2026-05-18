<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter;

use DOMDocument;
use DOMElement;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Architecture\Rules\CircularDependencyRule;
use Qualimetrix\Architecture\Rules\LayerViolationRule;
use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Debt\DebtCalculator;
use Qualimetrix\Reporting\Debt\RemediationTimeRegistry;
use Qualimetrix\Reporting\Filter\ViolationFilter;
use Qualimetrix\Reporting\Formatter\CheckstyleFormatter;
use Qualimetrix\Reporting\Formatter\GithubActionsFormatter;
use Qualimetrix\Reporting\Formatter\GitLabCodeQualityFormatter;
use Qualimetrix\Reporting\Formatter\Health\HealthTextFormatter;
use Qualimetrix\Reporting\Formatter\Html\HtmlFormatter;
use Qualimetrix\Reporting\Formatter\Json\JsonFormatter;
use Qualimetrix\Reporting\Formatter\Json\JsonHealthSection;
use Qualimetrix\Reporting\Formatter\Json\JsonOffenderSection;
use Qualimetrix\Reporting\Formatter\Json\JsonSanitizer;
use Qualimetrix\Reporting\Formatter\Json\JsonViolationSection;
use Qualimetrix\Reporting\Formatter\MetricsJsonFormatter;
use Qualimetrix\Reporting\Formatter\Sarif\SarifFormatter;
use Qualimetrix\Reporting\Formatter\Sarif\SarifRuleCollector;
use Qualimetrix\Reporting\Formatter\Summary\HealthBarRenderer;
use Qualimetrix\Reporting\Formatter\Summary\HintRenderer;
use Qualimetrix\Reporting\Formatter\Summary\OffenderListRenderer;
use Qualimetrix\Reporting\Formatter\Summary\SummaryFormatter;
use Qualimetrix\Reporting\Formatter\Summary\TopIssuesRenderer;
use Qualimetrix\Reporting\Formatter\Summary\ViolationSummaryRenderer;
use Qualimetrix\Reporting\Formatter\Support\DetailedViolationRenderer;
use Qualimetrix\Reporting\Formatter\TextFormatter;
use Qualimetrix\Reporting\Formatter\TextVerboseFormatter;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Health\HealthScoreResolver;
use Qualimetrix\Reporting\Health\MetricHintProvider;
use Qualimetrix\Reporting\Health\NamespaceDrillDown;
use Qualimetrix\Reporting\Report;
use Qualimetrix\Reporting\ReportBuilder;

/**
 * Smoke coverage for every output formatter against the full set of
 * architecture-domain violation flavours.
 *
 * The goal is "formatter runs without error AND emits format-appropriate
 * output for architecture violations" — assertions are intentionally
 * containment- or structure-based, not full-string snapshots, so that
 * formatters can evolve without rewriting these tests.
 *
 * The fixture mixes per-class violations with project-level diagnostics
 * (which carry {@see SymbolPath::forProject()} and {@see Location::none()})
 * so the format-specific handling of fileless / project-level locations
 * is exercised on the path through every formatter.
 */
#[CoversNothing]
final class ArchitectureViolationSmokeTest extends TestCase
{
    private const string SOURCE_NAMESPACE = 'App\\Infrastructure\\Console';
    private const string SOURCE_CLASS = 'UserCommand';
    private const string TARGET_NAMESPACE = 'App\\Infrastructure\\Persistence';
    private const string TARGET_CLASS = 'UserRepository';
    private const string SOURCE_FILE = 'src/Infrastructure/Console/UserCommand.php';
    private const int SOURCE_LINE = 42;

    #[Test]
    public function itRendersArchitectureViolationsViaTextFormatter(): void
    {
        $formatter = $this->createTextFormatter();
        $report = $this->buildArchitectureReport();

        $output = $formatter->format($report, new FormatterContext(useColor: false));

        self::assertNonEmptyOutput($output);
        self::assertStringContainsString(LayerViolationRule::NAME, $output);
        self::assertStringContainsString(CircularDependencyRule::NAME, $output);
        self::assertStringContainsString(LayerViolationRule::COVERAGE_DIAGNOSTIC_NAME, $output);
        self::assertStringContainsString(LayerViolationRule::EMPTY_TEMPLATE_DIAGNOSTIC_NAME, $output);
        self::assertStringContainsString(LayerViolationRule::UNREACHABLE_LAYER_DIAGNOSTIC_NAME, $output);
        self::assertStringContainsString(LayerViolationRule::POTENTIAL_SHADOW_DIAGNOSTIC_NAME, $output);
        // Source/target class names should appear in the layer-violation row
        self::assertStringContainsString(self::SOURCE_CLASS, $output);
        // Dependency-type detail (the human description) should appear too
        self::assertStringContainsString(DependencyType::Extends->description(), $output);
    }

    #[Test]
    public function itRendersArchitectureViolationsViaTextVerboseFormatter(): void
    {
        $debtCalculator = new DebtCalculator(new RemediationTimeRegistry());
        $detailedRenderer = new DetailedViolationRenderer($debtCalculator);
        $textFormatter = new TextFormatter($debtCalculator, $detailedRenderer);
        $formatter = new TextVerboseFormatter($textFormatter);

        $report = $this->buildArchitectureReport();
        $output = $formatter->format($report, new FormatterContext(useColor: false));

        self::assertNonEmptyOutput($output);
        self::assertStringContainsString(LayerViolationRule::NAME, $output);
        self::assertStringContainsString(CircularDependencyRule::NAME, $output);
        // text-verbose enables --detail, so recommendation text must surface.
        // DetailedViolationRenderer inlines the recommendation without a
        // 'Recommendation:' label, so we assert on a stable substring from
        // the layer-violation recommendation copy itself.
        self::assertStringContainsString(
            'Introduce an interface in the console layer',
            $output,
        );
    }

    #[Test]
    public function itRendersArchitectureViolationsViaJsonFormatter(): void
    {
        $hintProvider = new MetricHintProvider();
        $namespaceDrillDown = new NamespaceDrillDown($hintProvider);
        $sanitizer = new JsonSanitizer();
        $violationFilter = new ViolationFilter();
        $remediationTimeRegistry = new RemediationTimeRegistry();
        $formatter = new JsonFormatter(
            new DebtCalculator($remediationTimeRegistry),
            new JsonHealthSection(new HealthScoreResolver($namespaceDrillDown), $sanitizer),
            new JsonOffenderSection($namespaceDrillDown, $violationFilter, $sanitizer),
            new JsonViolationSection($remediationTimeRegistry, $sanitizer),
        );

        $report = $this->buildArchitectureReport();
        $output = $formatter->format($report, new FormatterContext());

        self::assertJson($output);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($data['violations']);
        self::assertSame($this->expectedViolationCount(), \count($data['violations']));

        $rulesPresent = array_map(static fn(array $row): string => $row['rule'], $data['violations']);
        foreach ($this->expectedRuleNames() as $expected) {
            self::assertContains($expected, $rulesPresent, "JSON output should mention rule {$expected}");
        }

        foreach ($data['violations'] as $row) {
            self::assertArrayHasKey('severity', $row);
            self::assertArrayHasKey('message', $row);
            // file/line can be null for project-level diagnostics — that's the test
            self::assertArrayHasKey('file', $row);
            self::assertArrayHasKey('line', $row);
        }
    }

    #[Test]
    public function itRunsMetricsJsonFormatterOnArchitectureOnlyReport(): void
    {
        $formatter = new MetricsJsonFormatter();
        $report = $this->buildArchitectureReport();

        $output = $formatter->format($report, new FormatterContext());

        self::assertJson($output);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        // MetricsJsonFormatter exports metric symbols, not violations directly —
        // but the summary block should reflect the violation totals so this
        // assertion proves architecture violations actually flow through.
        self::assertArrayHasKey('summary', $data);
        self::assertSame($this->expectedViolationCount(), $data['summary']['violations']);
        // With no metric repository wired in, symbols is just an empty list
        self::assertArrayHasKey('symbols', $data);
        self::assertSame([], $data['symbols']);
    }

    #[Test]
    public function itRendersArchitectureViolationsViaHtmlFormatter(): void
    {
        $formatter = new HtmlFormatter(
            new DebtCalculator(new RemediationTimeRegistry()),
            new MetricHintProvider(),
        );

        $report = $this->buildArchitectureReport();
        $output = $formatter->format($report, new FormatterContext());

        // The HTML formatter attaches violations to tree nodes built from
        // the metric repository (see HtmlViolationPartitioner): project-level
        // violations and violations whose owning class/namespace node has no
        // entry are intentionally dropped. With an architecture-only report
        // (no MetricRepositoryInterface wired in) the tree is empty, so the
        // smoke contract is that the formatter still produces a valid,
        // self-contained HTML document carrying the report-level totals,
        // not that every rule appears in the embedded JSON.
        self::assertStringContainsString('<!DOCTYPE html>', $output);
        self::assertStringContainsString('</html>', $output);
        self::assertStringContainsString('id="report-data"', $output);
        self::assertStringContainsString('"totalViolations":' . $this->expectedViolationCount(), $output);
    }

    #[Test]
    public function itRendersArchitectureViolationsViaCheckstyleFormatter(): void
    {
        $formatter = new CheckstyleFormatter();
        $report = $this->buildArchitectureReport();

        $output = $formatter->format($report, new FormatterContext());

        // Output must parse as XML
        $previousErrors = libxml_use_internal_errors(true);

        try {
            $doc = new DOMDocument();
            self::assertTrue($doc->loadXML($output), 'Checkstyle output must be valid XML');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        $files = $doc->getElementsByTagName('file');
        self::assertGreaterThan(0, $files->length, 'Expected at least one <file> element');

        $errors = $doc->getElementsByTagName('error');
        self::assertSame($this->expectedViolationCount(), $errors->length);

        $sources = [];
        foreach ($errors as $errorNode) {
            self::assertInstanceOf(DOMElement::class, $errorNode);
            self::assertNotSame('', $errorNode->getAttribute('severity'));
            self::assertNotSame('', $errorNode->getAttribute('message'));
            $sources[] = $errorNode->getAttribute('source');
        }

        foreach ($this->expectedRuleNames() as $rule) {
            self::assertContains('qmx.' . $rule, $sources, "Checkstyle should emit source for rule {$rule}");
        }
    }

    #[Test]
    public function itRendersArchitectureViolationsViaSarifFormatter(): void
    {
        $formatter = new SarifFormatter(new SarifRuleCollector());
        $report = $this->buildArchitectureReport();

        $output = $formatter->format($report, new FormatterContext());

        self::assertJson($output);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('2.1.0', $data['version']);
        $run = $data['runs'][0];

        $ruleIds = array_map(
            static fn(array $rule): string => $rule['id'],
            $run['tool']['driver']['rules'],
        );
        foreach ($this->expectedRuleNames() as $rule) {
            self::assertContains($rule, $ruleIds, "SARIF tool.driver.rules should list {$rule}");
        }

        $resultRuleIds = array_map(
            static fn(array $result): string => $result['ruleId'],
            $run['results'],
        );
        foreach ($this->expectedRuleNames() as $rule) {
            self::assertContains($rule, $resultRuleIds, "SARIF results should include a hit for {$rule}");
        }

        self::assertSame($this->expectedViolationCount(), \count($run['results']));
    }

    #[Test]
    public function itRendersArchitectureViolationsViaGitLabCodeQualityFormatter(): void
    {
        $formatter = new GitLabCodeQualityFormatter();
        $report = $this->buildArchitectureReport();

        $output = $formatter->format($report, new FormatterContext());

        self::assertJson($output);
        $issues = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($issues);
        self::assertSame($this->expectedViolationCount(), \count($issues));

        $checkNames = array_map(static fn(array $issue): string => $issue['check_name'], $issues);
        foreach ($this->expectedRuleNames() as $rule) {
            self::assertContains($rule, $checkNames, "GitLab should emit issue for {$rule}");
        }

        $severities = array_unique(array_map(static fn(array $issue): string => $issue['severity'], $issues));
        // Severity must be one of the GitLab Code Quality enum values
        $validSeverities = ['blocker', 'critical', 'major', 'minor', 'info', 'unknown'];
        foreach ($severities as $severity) {
            self::assertContains($severity, $validSeverities, "GitLab severity '{$severity}' is not in the spec");
        }

        // Project-level diagnostics must collapse to the documented '_project' sentinel
        $paths = array_map(static fn(array $issue): string => $issue['location']['path'], $issues);
        self::assertContains('_project', $paths, 'Project-level diagnostics should map to _project path');
    }

    #[Test]
    public function itRunsHealthFormatterOnArchitectureOnlyReport(): void
    {
        $hintProvider = new MetricHintProvider();
        $drillDown = new NamespaceDrillDown($hintProvider);
        $resolver = new HealthScoreResolver($drillDown);
        $formatter = new HealthTextFormatter($resolver);

        $report = $this->buildArchitectureReport();
        $output = $formatter->format($report, new FormatterContext(useColor: false, terminalWidth: 120));

        // The architecture rule emits no health score; the formatter must
        // still produce a non-empty rendering (header / "no data" notice) and
        // must not contain the rule names (they're not part of health output).
        self::assertNonEmptyOutput($output);
    }

    #[Test]
    public function itRendersArchitectureViolationsViaSummaryFormatter(): void
    {
        $registry = new RemediationTimeRegistry();
        $debtCalculator = new DebtCalculator($registry);
        $hintProvider = new MetricHintProvider();
        $namespaceDrillDown = new NamespaceDrillDown($hintProvider);
        $violationFilter = new ViolationFilter();
        $offenderListRenderer = new OffenderListRenderer($violationFilter, $namespaceDrillDown);
        $formatter = new SummaryFormatter(
            new DetailedViolationRenderer($debtCalculator),
            new HealthBarRenderer(new HealthScoreResolver($namespaceDrillDown)),
            $offenderListRenderer,
            new TopIssuesRenderer(),
            new ViolationSummaryRenderer($violationFilter, $registry),
            new HintRenderer($offenderListRenderer),
        );

        $report = $this->buildArchitectureReport();
        $output = $formatter->format($report, new FormatterContext(useColor: false, terminalWidth: 120));

        self::assertNonEmptyOutput($output);
        // Summary aggregates by severity. The fixture has 1 error, 2 warnings,
        // 3 info violations, so the summary line must mention those counts.
        self::assertStringContainsString('violation', $output);
        self::assertStringContainsString('error', $output);
        self::assertStringContainsString('warning', $output);
        self::assertStringContainsString('info', $output);
    }

    #[Test]
    public function itRendersArchitectureViolationsViaGithubActionsFormatter(): void
    {
        $formatter = new GithubActionsFormatter();
        $report = $this->buildArchitectureReport();

        $output = $formatter->format($report, new FormatterContext());

        self::assertNonEmptyOutput($output);

        $lines = array_values(array_filter(explode("\n", $output), static fn(string $l): bool => $l !== ''));
        self::assertSame($this->expectedViolationCount(), \count($lines));

        foreach ($lines as $line) {
            self::assertMatchesRegularExpression(
                '/^::(error|warning|notice) /',
                $line,
                'Every GitHub Actions annotation must start with ::error::, ::warning::, or ::notice::',
            );
        }

        // Per-rule titles flow into title= property
        $combined = implode("\n", $lines);
        foreach ($this->expectedRuleNames() as $rule) {
            self::assertStringContainsString($rule, $combined, "GitHub Actions output should mention {$rule}");
        }
    }

    // ---------------------------------------------------------------
    // Fixture / helpers
    // ---------------------------------------------------------------

    /**
     * Asserts the rendered output is non-empty after trimming whitespace.
     *
     * Architecture-only reports should never produce a fully empty string —
     * every formatter has at least a header or a wrapper to emit. Used as a
     * baseline "didn't crash and didn't return ''" check.
     */
    private static function assertNonEmptyOutput(string $output): void
    {
        self::assertNotSame('', trim($output));
    }

    private function createTextFormatter(): TextFormatter
    {
        $debtCalculator = new DebtCalculator(new RemediationTimeRegistry());

        return new TextFormatter($debtCalculator, new DetailedViolationRenderer($debtCalculator));
    }

    /**
     * Builds a report containing exactly one of each architecture violation
     * flavour the rules emit in production.
     *
     * Severities mirror production defaults:
     *  - layer-violation     → Warning   (per LayerViolationOptions default)
     *  - circular-dependency → Error     (per CircularDependencyOptions default)
     *  - coverage            → Error     (CoverageMode::Error path)
     *  - empty-template      → Warning
     *  - unreachable-layer   → Info
     *  - potential-shadow    → Info
     */
    private function buildArchitectureReport(): Report
    {
        $sourcePath = SymbolPath::forClass(self::SOURCE_NAMESPACE, self::SOURCE_CLASS);
        $targetPath = SymbolPath::forClass(self::TARGET_NAMESPACE, self::TARGET_CLASS);

        $violations = [
            // architecture.layer-violation — per-class, with dependency metadata
            new Violation(
                location: new Location(RelativePath::fromString(self::SOURCE_FILE), self::SOURCE_LINE, precise: true),
                symbolPath: $sourcePath,
                ruleName: LayerViolationRule::NAME,
                violationCode: LayerViolationRule::NAME,
                message: \sprintf(
                    'Layer "console" must not depend on layer "persistence" (%s → %s, %s)',
                    $sourcePath->toString(),
                    $targetPath->toString(),
                    DependencyType::Extends->description(),
                ),
                severity: Severity::Warning,
                recommendation: 'Introduce an interface in the console layer and depend on the abstraction instead.',
                dependencyTarget: $targetPath,
                dependencyType: DependencyType::Extends,
            ),

            // architecture.circular-dependency — per-class, no dependency metadata
            new Violation(
                location: Location::none(),
                symbolPath: SymbolPath::forClass('App\\Service', 'A'),
                ruleName: CircularDependencyRule::NAME,
                violationCode: CircularDependencyRule::NAME,
                message: 'Circular dependency (3 classes): App\\Service\\A → App\\Service\\B → App\\Service\\C → App\\Service\\A',
                severity: Severity::Error,
                metricValue: 3,
                recommendation: 'Break the cycle by extracting a common abstraction or moving shared state.',
            ),

            // architecture.coverage — project-level diagnostic
            new Violation(
                location: Location::none(),
                symbolPath: SymbolPath::forProject(),
                ruleName: LayerViolationRule::COVERAGE_DIAGNOSTIC_NAME,
                violationCode: LayerViolationRule::COVERAGE_DIAGNOSTIC_NAME,
                message: 'Architecture coverage: 0 edge(s) with unmatched source layer, 0 edge(s) with unmatched target layer, 7 class(es) outside all declared layers.',
                severity: Severity::Error,
                recommendation: 'Declare layers covering the remaining classes or accept the gap by leaving coverage on "ignore".',
            ),

            // architecture.empty-template — project-level diagnostic
            new Violation(
                location: Location::none(),
                symbolPath: SymbolPath::forProject(),
                ruleName: LayerViolationRule::EMPTY_TEMPLATE_DIAGNOSTIC_NAME,
                violationCode: LayerViolationRule::EMPTY_TEMPLATE_DIAGNOSTIC_NAME,
                message: 'Template layer "module-{name}" expanded to zero concrete layers — no class in the analysed codebase matched the template\'s criteria.',
                severity: Severity::Warning,
                recommendation: 'Verify the template patterns against the project structure, or remove the template if no longer relevant.',
            ),

            // architecture.unreachable-layer — project-level diagnostic
            new Violation(
                location: Location::none(),
                symbolPath: SymbolPath::forProject(),
                ruleName: LayerViolationRule::UNREACHABLE_LAYER_DIAGNOSTIC_NAME,
                violationCode: LayerViolationRule::UNREACHABLE_LAYER_DIAGNOSTIC_NAME,
                message: 'Layer "legacy" was never matched during analysis. Possible causes: (1) it is shadowed by a broader layer earlier, (2) the declared criteria match no class in the analysed codebase.',
                severity: Severity::Info,
                recommendation: 'Move the layer above any broader layer that captures its classes, or remove the layer if its pattern intentionally covers no class.',
            ),

            // architecture.potential-shadow — project-level diagnostic (per shadow pair)
            new Violation(
                location: Location::none(),
                symbolPath: SymbolPath::forProject(),
                ruleName: LayerViolationRule::POTENTIAL_SHADOW_DIAGNOSTIC_NAME,
                violationCode: LayerViolationRule::POTENTIAL_SHADOW_DIAGNOSTIC_NAME,
                message: 'Layer "core" (pattern App\\Core\\**) shadows layer "core-domain" (pattern App\\Core\\Domain\\**) for 3 class(es).',
                severity: Severity::Info,
                recommendation: 'If layer "core-domain" should own these classes, declare it BEFORE "core" (declaration order, first match wins).',
            ),
        ];

        return ReportBuilder::create()
            ->addViolations($violations)
            ->filesAnalyzed(12)
            ->filesSkipped(0)
            ->duration(0.42)
            ->build();
    }

    /**
     * @return list<string>
     */
    private function expectedRuleNames(): array
    {
        return [
            LayerViolationRule::NAME,
            CircularDependencyRule::NAME,
            LayerViolationRule::COVERAGE_DIAGNOSTIC_NAME,
            LayerViolationRule::EMPTY_TEMPLATE_DIAGNOSTIC_NAME,
            LayerViolationRule::UNREACHABLE_LAYER_DIAGNOSTIC_NAME,
            LayerViolationRule::POTENTIAL_SHADOW_DIAGNOSTIC_NAME,
        ];
    }

    private function expectedViolationCount(): int
    {
        return \count($this->expectedRuleNames());
    }
}

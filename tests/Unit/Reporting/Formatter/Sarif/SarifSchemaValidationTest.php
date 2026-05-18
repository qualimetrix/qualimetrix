<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter\Sarif;

use JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Formatter\Sarif\SarifFormatter;
use Qualimetrix\Reporting\Formatter\Sarif\SarifRuleCollector;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\ReportBuilder;

/**
 * Validates {@see SarifFormatter} output against the official SARIF 2.1.0
 * JSON Schema (OASIS sarif-spec).
 *
 * Complements {@see SarifFormatterTest}, which only inspects the structure
 * by hand. The schema is vendored under `tests/Fixtures/Schema/` so the
 * suite is hermetic (no network at test time).
 */
#[CoversClass(SarifFormatter::class)]
final class SarifSchemaValidationTest extends TestCase
{
    private const SCHEMA_FIXTURE = __DIR__ . '/../../../../Fixtures/Schema/sarif-2.1.0.schema.json';

    private SarifFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new SarifFormatter(new SarifRuleCollector());
    }

    #[Test]
    public function emptyReport_conformsToSarif2_1_0Schema(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(0)
            ->filesSkipped(0)
            ->duration(0.0)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());

        $this->assertOutputMatchesSarifSchema($output);
    }

    #[Test]
    public function reportWithMixedSeverityViolations_conformsToSarif2_1_0Schema(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location(RelativePath::fromString('src/Service/UserService.php'), 42),
                symbolPath: SymbolPath::forMethod('App\\Service', 'UserService', 'calculateDiscount'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic',
                message: 'Cyclomatic complexity of 25 exceeds threshold',
                severity: Severity::Error,
                metricValue: 25,
            ))
            ->addViolation(new Violation(
                location: new Location(RelativePath::fromString('src/Service/OrderService.php'), 120),
                symbolPath: SymbolPath::forMethod('App\\Service', 'OrderService', 'processOrder'),
                ruleName: 'design.lcom',
                violationCode: 'design.lcom',
                message: 'LCOM4 of 8 exceeds threshold',
                severity: Severity::Warning,
                metricValue: 8,
            ))
            ->addViolation(new Violation(
                location: new Location(RelativePath::fromString('src/Controller/HomeController.php'), 15),
                symbolPath: SymbolPath::forClass('App\\Controller', 'HomeController'),
                ruleName: 'architecture.coverage',
                violationCode: 'architecture.coverage',
                message: 'Class is not assigned to a layer',
                severity: Severity::Info,
            ))
            ->filesAnalyzed(42)
            ->filesSkipped(1)
            ->duration(0.23)
            ->build();

        $output = $this->formatter->format(
            $report,
            new FormatterContext(basePath: '/home/user/project'),
        );

        $this->assertOutputMatchesSarifSchema($output);
    }

    #[Test]
    public function reportWithRelatedLocations_conformsToSarif2_1_0Schema(): void
    {
        // Exercises the relatedLocations branch (duplication detector shape).
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location(RelativePath::fromString('src/Service/UserService.php'), 10),
                symbolPath: SymbolPath::forFile('src/Service/UserService.php'),
                ruleName: 'duplication.code-duplication',
                violationCode: 'duplication.code-duplication',
                message: 'Duplicated code block (20 lines, 3 occurrences)',
                severity: Severity::Warning,
                metricValue: 20,
                relatedLocations: [
                    new Location(RelativePath::fromString('src/Service/OrderService.php'), 42),
                    new Location(RelativePath::fromString('src/Service/PaymentService.php'), 88),
                ],
            ))
            ->addViolation(new Violation(
                location: Location::none(),
                symbolPath: SymbolPath::forNamespace('App'),
                ruleName: 'architecture.circular-dependency',
                violationCode: 'architecture.circular-dependency',
                message: 'Circular dependency detected',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(3)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format(
            $report,
            new FormatterContext(basePath: '/home/user/project'),
        );

        $this->assertOutputMatchesSarifSchema($output);
    }

    /**
     * Decodes the formatter output as an object tree (the shape the schema
     * library expects) and validates it against the SARIF 2.1.0 schema.
     */
    private function assertOutputMatchesSarifSchema(string $output): void
    {
        self::assertJson($output);

        $schemaJson = file_get_contents(self::SCHEMA_FIXTURE);
        self::assertNotFalse($schemaJson, 'SARIF schema fixture must be readable');

        $schema = json_decode($schemaJson, false, 512, \JSON_THROW_ON_ERROR);
        $data = json_decode($output, false, 512, \JSON_THROW_ON_ERROR);

        $validator = new Validator();
        $validator->validate($data, $schema);

        self::assertTrue(
            $validator->isValid(),
            'SARIF output failed schema validation: ' . self::formatErrors($validator->getErrors()),
        );
        self::assertEmpty(
            $validator->getErrors(),
            'SARIF output produced schema errors: ' . self::formatErrors($validator->getErrors()),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $errors
     */
    private static function formatErrors(array $errors): string
    {
        if ($errors === []) {
            return '(none)';
        }

        $lines = array_map(
            static fn(array $e): string => \sprintf(
                '[%s] %s',
                $e['property'] ?? '<root>',
                $e['message'] ?? 'unknown',
            ),
            $errors,
        );

        return "\n  - " . implode("\n  - ", $lines);
    }
}

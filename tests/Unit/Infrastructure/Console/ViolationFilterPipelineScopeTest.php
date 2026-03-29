<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Baseline\BaselineLoader;
use Qualimetrix\Baseline\Suppression\SuppressionFilter;
use Qualimetrix\Baseline\ViolationHasher;
use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Infrastructure\Console\ViolationFilterOptions;
use Qualimetrix\Infrastructure\Console\ViolationFilterPipeline;

/**
 * Tests for the analyze scope filter (step 5) in ViolationFilterPipeline.
 */
final class ViolationFilterPipelineScopeTest extends TestCase
{
    private ViolationFilterPipeline $pipeline;

    protected function setUp(): void
    {
        $configProvider = $this->createMock(ConfigurationProviderInterface::class);
        $configProvider->method('getConfiguration')
            ->willReturn(new AnalysisConfiguration());

        $this->pipeline = new ViolationFilterPipeline(
            new BaselineLoader(),
            new ViolationHasher(),
            new SuppressionFilter(),
            $configProvider,
        );
    }

    #[Test]
    public function violationsInScopeAreKept(): void
    {
        $violation = $this->makeViolation('src/Service/UserService.php');

        $options = $this->makeScopeOptions(['src/Service/UserService.php']);
        $result = $this->pipeline->filter([$violation], $options);

        self::assertCount(1, $result->violations);
        self::assertSame(0, $result->analyzeScopeFiltered);
    }

    #[Test]
    public function violationsOutOfScopeAreFiltered(): void
    {
        $violation = $this->makeViolation('src/Service/PaymentService.php');

        $options = $this->makeScopeOptions(['src/Service/UserService.php']);
        $result = $this->pipeline->filter([$violation], $options);

        self::assertCount(0, $result->violations);
        self::assertSame(1, $result->analyzeScopeFiltered);
    }

    #[Test]
    public function locationNoneViolationsAreFilteredInScopeMode(): void
    {
        $violation = new Violation(
            location: Location::none(),
            symbolPath: SymbolPath::forNamespace('App\\Service'),
            ruleName: 'architecture.circular-dependency',
            violationCode: 'architecture.circular-dependency',
            message: 'Circular dependency detected',
            severity: Severity::Error,
        );

        $options = $this->makeScopeOptions(['src/Service/UserService.php']);
        $result = $this->pipeline->filter([$violation], $options);

        self::assertCount(0, $result->violations);
        self::assertSame(1, $result->analyzeScopeFiltered);
    }

    #[Test]
    public function locationNoneViolationsPassWithoutScope(): void
    {
        $violation = new Violation(
            location: Location::none(),
            symbolPath: SymbolPath::forNamespace('App\\Service'),
            ruleName: 'architecture.circular-dependency',
            violationCode: 'architecture.circular-dependency',
            message: 'Circular dependency detected',
            severity: Severity::Error,
        );

        $options = $this->makeNoScopeOptions();
        $result = $this->pipeline->filter([$violation], $options);

        self::assertCount(1, $result->violations);
        self::assertSame(0, $result->analyzeScopeFiltered);
    }

    #[Test]
    public function mixedViolationsAreFilteredCorrectly(): void
    {
        $inScope = $this->makeViolation('src/Service/UserService.php');
        $outOfScope = $this->makeViolation('src/Controller/IndexController.php');
        $noLocation = new Violation(
            location: Location::none(),
            symbolPath: SymbolPath::forProject(),
            ruleName: 'computed.health',
            violationCode: 'computed.health',
            message: 'Health score below threshold',
            severity: Severity::Warning,
        );

        $options = $this->makeScopeOptions(['src/Service/UserService.php']);
        $result = $this->pipeline->filter([$inScope, $outOfScope, $noLocation], $options);

        self::assertCount(1, $result->violations);
        self::assertSame('src/Service/UserService.php', $result->violations[0]->location->file);
        self::assertSame(2, $result->analyzeScopeFiltered);
    }

    #[Test]
    public function relativePathsMatchCorrectly(): void
    {
        $violation = $this->makeViolation('src/Service/UserService.php');

        // Scope paths are relative (matching violation file format)
        $options = $this->makeScopeOptions(['src/Service/UserService.php']);
        $result = $this->pipeline->filter([$violation], $options);

        self::assertCount(1, $result->violations);
    }

    #[Test]
    public function noScopeFilterPassesAllViolations(): void
    {
        $v1 = $this->makeViolation('src/Service/UserService.php');
        $v2 = $this->makeViolation('src/Controller/IndexController.php');

        $options = $this->makeNoScopeOptions();
        $result = $this->pipeline->filter([$v1, $v2], $options);

        self::assertCount(2, $result->violations);
        self::assertSame(0, $result->analyzeScopeFiltered);
    }

    #[Test]
    public function emptyScopeFiltersAllViolations(): void
    {
        $violation = $this->makeViolation('src/Service/UserService.php');

        $options = $this->makeScopeOptions([]);
        $result = $this->pipeline->filter([$violation], $options);

        self::assertCount(0, $result->violations);
        self::assertSame(1, $result->analyzeScopeFiltered);
    }

    private function makeViolation(string $file): Violation
    {
        return new Violation(
            location: new Location($file, 10),
            symbolPath: SymbolPath::forFile($file),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.method',
            message: 'CCN too high',
            severity: Severity::Error,
        );
    }

    /**
     * @param list<string> $scopeFilePaths
     */
    private function makeScopeOptions(array $scopeFilePaths): ViolationFilterOptions
    {
        return new ViolationFilterOptions(
            baselinePath: null,
            ignoreStaleBaseline: false,
            disableSuppression: true,
            excludePaths: [],
            gitScope: null,
            scopeFilePaths: $scopeFilePaths,
        );
    }

    private function makeNoScopeOptions(): ViolationFilterOptions
    {
        return new ViolationFilterOptions(
            baselinePath: null,
            ignoreStaleBaseline: false,
            disableSuppression: true,
            excludePaths: [],
            gitScope: null,
            scopeFilePaths: null,
        );
    }
}

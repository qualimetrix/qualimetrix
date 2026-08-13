<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\CodeSmell;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\OccurrenceKey;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\CodeSmell\BooleanArgumentOptions;
use Qualimetrix\Rules\CodeSmell\BooleanArgumentRule;

#[CoversClass(BooleanArgumentRule::class)]
final class BooleanArgumentRuleTest extends TestCase
{
    #[Test]
    public function nameAndDescriptionAreCorrect(): void
    {
        $rule = new BooleanArgumentRule(new BooleanArgumentOptions());

        self::assertSame('code-smell.boolean-argument', $rule->getName());
        self::assertSame('Detects boolean arguments in method/function signatures', $rule->getDescription());
        self::assertSame(RuleCategory::CodeSmell, $rule->getCategory());
    }

    #[Test]
    public function requiresReturnsExpectedMetrics(): void
    {
        $rule = new BooleanArgumentRule(new BooleanArgumentOptions());

        self::assertSame(['codeSmell.boolean_argument'], $rule->requires());
    }

    #[Test]
    public function optionsClassIsCorrect(): void
    {
        self::assertSame(BooleanArgumentOptions::class, BooleanArgumentRule::getOptionsClass());
    }

    #[Test]
    public function disabledRuleReturnsNoViolations(): void
    {
        $rule = new BooleanArgumentRule(new BooleanArgumentOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function noSmellsProducesNoViolations(): void
    {
        $rule = new BooleanArgumentRule(new BooleanArgumentOptions());

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/Clean.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Clean.php'), null);

        $metricBag = new MetricBag();

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itFailsBeforeEntryProjectionWhenAFileSymbolHasNoContainerPath(): void
    {
        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/Missing.php'));
        $fileInfo = new SymbolInfo($symbolPath, null, null);
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([$fileInfo]);
        $repository->method('get')->willReturn(
            (new MetricBag())->withEntry('codeSmell.boolean_argument', ['line' => 10]),
        );

        self::expectException(LogicException::class);
        self::expectExceptionMessage('File symbol must carry a relative path');

        (new BooleanArgumentRule(new BooleanArgumentOptions()))->analyze(new AnalysisContext($repository));
    }

    /**
     * @param array<string, bool|float|int|string> $entry
     */
    #[Test]
    #[DataProvider('subjectEntries')]
    public function itPreservesEveryCollectorSubjectKind(array $entry, string $expectedSubject): void
    {
        $file = RelativePath::fromString('src/Subjects.php');
        $fileSymbol = SymbolPath::forFile($file);
        $fileInfo = new SymbolInfo($fileSymbol, $file, null);
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([$fileInfo]);
        $repository->method('get')->willReturn(
            (new MetricBag())->withEntry('codeSmell.boolean_argument', $entry),
        );

        $violations = (new BooleanArgumentRule(new BooleanArgumentOptions(allowedPrefixes: [])))
            ->analyze(new AnalysisContext($repository));

        self::assertCount(1, $violations);
        self::assertSame($expectedSubject, $violations[0]->subject->toCanonical());
    }

    /**
     * @return iterable<string, array{array<string, bool|float|int|string>, string}>
     */
    public static function subjectEntries(): iterable
    {
        yield 'file' => [
            ['subjectKind' => 'file', 'line' => 5],
            'file:src/Subjects.php',
        ];
        yield 'class' => [
            ['subjectKind' => 'declaration', 'logicalKind' => 'class', 'namespace' => 'App', 'class' => 'Subject', 'startFilePos' => 10, 'line' => 5],
            'declaration:class:App\\Subject@src/Subjects.php:10',
        ];
        yield 'method' => [
            [
                'subjectKind' => 'declaration',
                'logicalKind' => 'method',
                'namespace' => 'App',
                'class' => 'Subject',
                'member' => 'run',
                'startFilePos' => 20,
                'line' => 5,
            ],
            'declaration:callable:App\\Subject::run@src/Subjects.php:20',
        ];
        yield 'function' => [
            ['subjectKind' => 'declaration', 'logicalKind' => 'function', 'namespace' => 'App', 'member' => 'run', 'startFilePos' => 30, 'line' => 5],
            'declaration:func:App::run@src/Subjects.php:30',
        ];
    }

    #[Test]
    public function smellDetectedProducesViolation(): void
    {
        $rule = new BooleanArgumentRule(new BooleanArgumentOptions());

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/Smelly.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Smelly.php'), null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.boolean_argument', ['subjectKind' => 'file', 'line' => 10])
            ->withEntry('codeSmell.boolean_argument', ['subjectKind' => 'file', 'line' => 25]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(2, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertSame(10, $violations[0]->location->line);
        self::assertSame(25, $violations[1]->location->line);
        self::assertSame('Boolean argument detected - consider splitting methods or using enums', $violations[0]->message);
        self::assertSame('code-smell.boolean-argument', $violations[0]->ruleName);
        self::assertSame('code-smell.boolean-argument', $violations[0]->violationCode);
        self::assertSame(1.0, $violations[0]->metricValue);
        self::assertSame('file:src/Smelly.php', $violations[0]->subject->toCanonical());
        self::assertTrue($violations[0]->location->precise);
        self::assertSame('Replace boolean parameter with two explicit methods or use an enum.', $violations[0]->recommendation);
        self::assertSame(
            OccurrenceKey::semantic('boolean_argument', [
                'type' => 'boolean_argument',
                'extra' => '',
                'hasExtra' => false,
                'promoted' => false,
                'hasPromoted' => false,
            ])->value,
            $violations[0]->occurrenceKey?->value,
        );
    }

    #[Test]
    public function smellWithParamNameIncludesItInMessage(): void
    {
        $rule = new BooleanArgumentRule(new BooleanArgumentOptions());

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/Smelly.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Smelly.php'), null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.boolean_argument', ['subjectKind' => 'file', 'line' => 10, 'extra' => 'overwrite'])
            ->withEntry('codeSmell.boolean_argument', ['subjectKind' => 'file', 'line' => 25, 'extra' => 'silent']);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(2, $violations);
        self::assertSame('Boolean argument $overwrite detected - consider splitting methods or using enums', $violations[0]->message);
        self::assertSame('Boolean argument $silent detected - consider splitting methods or using enums', $violations[1]->message);
    }

    #[Test]
    public function smellWithoutParamNameFallsBackToGenericMessage(): void
    {
        $rule = new BooleanArgumentRule(new BooleanArgumentOptions());

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/Smelly.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Smelly.php'), null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.boolean_argument', ['subjectKind' => 'file', 'line' => 10]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame('Boolean argument detected - consider splitting methods or using enums', $violations[0]->message);
    }

    #[Test]
    public function allowedPrefixesFilterEntries(): void
    {
        $rule = new BooleanArgumentRule(new BooleanArgumentOptions());

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/Service.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service.php'), null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.boolean_argument', ['subjectKind' => 'file', 'line' => 10, 'extra' => 'isActive'])    // allowed
            ->withEntry('codeSmell.boolean_argument', ['subjectKind' => 'file', 'line' => 20, 'extra' => 'hasPermission']) // allowed
            ->withEntry('codeSmell.boolean_argument', ['subjectKind' => 'file', 'line' => 30, 'extra' => 'overwrite'])     // NOT allowed
            ->withEntry('codeSmell.boolean_argument', ['subjectKind' => 'file', 'line' => 40, 'extra' => 'island']);       // NOT allowed (no boundary)

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(2, $violations);
        self::assertSame(30, $violations[0]->location->line);
        self::assertSame(40, $violations[1]->location->line);
    }

    #[Test]
    public function emptyPrefixListReportsAllEntries(): void
    {
        $rule = new BooleanArgumentRule(new BooleanArgumentOptions(allowedPrefixes: []));

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/Service.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service.php'), null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.boolean_argument', ['subjectKind' => 'file', 'line' => 10, 'extra' => 'isActive'])
            ->withEntry('codeSmell.boolean_argument', ['subjectKind' => 'file', 'line' => 20, 'extra' => 'overwrite']);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(2, $violations);
    }

    #[Test]
    public function emptyExtraFallsBackToBaseTemplate(): void
    {
        $rule = new BooleanArgumentRule(new BooleanArgumentOptions());

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/Service.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service.php'), null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.boolean_argument', ['subjectKind' => 'file', 'line' => 10, 'extra' => '']);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(
            'Boolean argument detected - consider splitting methods or using enums',
            $violations[0]->message,
        );
    }

    #[Test]
    public function entryWithoutExtraIsAlwaysReported(): void
    {
        $rule = new BooleanArgumentRule(new BooleanArgumentOptions());

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/Service.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service.php'), null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.boolean_argument', ['subjectKind' => 'file', 'line' => 10]); // no extra

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
    }

    #[Test]
    public function itExcludesPromotedConstructorPropertyByDefault(): void
    {
        $rule = new BooleanArgumentRule(new BooleanArgumentOptions());

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/Service.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service.php'), null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.boolean_argument', ['subjectKind' => 'file', 'line' => 10, 'extra' => 'shortLabels', 'promoted' => true]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertSame([], $violations);
    }

    #[Test]
    public function itIncludesNonPromotedConstructorParameterByDefault(): void
    {
        $rule = new BooleanArgumentRule(new BooleanArgumentOptions());

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/Service.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service.php'), null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.boolean_argument', ['subjectKind' => 'file', 'line' => 10, 'extra' => 'expanded', 'promoted' => false]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
    }

    #[Test]
    public function itFlagsPromotedConstructorPropertyWhenOptionEnabled(): void
    {
        $rule = new BooleanArgumentRule(new BooleanArgumentOptions(flagPromotedProperties: true));

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/Service.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service.php'), null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.boolean_argument', ['subjectKind' => 'file', 'line' => 10, 'extra' => 'shortLabels', 'promoted' => true]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
    }

    #[Test]
    public function itExcludesPromotedPropertyEvenWhenAllowedPrefixMatches(): void
    {
        // A promoted `$isActive`-style property should still be excluded via the
        // promoted check, independent of the allowed-prefix filtering path.
        $rule = new BooleanArgumentRule(new BooleanArgumentOptions());

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/Service.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service.php'), null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.boolean_argument', ['subjectKind' => 'file', 'line' => 10, 'extra' => 'overwrite', 'promoted' => true]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertSame([], $violations);
    }
}

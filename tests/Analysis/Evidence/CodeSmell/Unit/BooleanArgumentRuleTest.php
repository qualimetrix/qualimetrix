<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CodeSmell\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\BooleanArgumentOptions;
use Qualimetrix\Analysis\Evidence\CodeSmell\BooleanArgumentRule;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;

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
    public function disabledRuleReturnsNoFindings(): void
    {
        $rule = new BooleanArgumentRule(new BooleanArgumentOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function noSmellsProducesNoFindings(): void
    {
        $rule = new BooleanArgumentRule(new BooleanArgumentOptions());

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/Clean.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Clean.php'), null);

        $metricBag = new MetricBag();

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolLevel $level) => $level === SymbolLevel::File ? [$fileInfo] : []);
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

        $findings = (new BooleanArgumentRule(new BooleanArgumentOptions(allowedPrefixes: [])))
            ->analyze(new AnalysisContext($repository));

        self::assertCount(1, $findings);
        self::assertSame($expectedSubject, $findings[0]->subject->toCanonical());
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
            ['subjectKind' => 'declaration', 'logicalKind' => 'class', 'namespace' => 'App', 'class' => 'Subject', 'line' => 5],
            'declaration:class:App\\Subject@src/Subjects.php',
        ];
        yield 'method' => [
            [
                'subjectKind' => 'declaration',
                'logicalKind' => 'method',
                'namespace' => 'App',
                'class' => 'Subject',
                'member' => 'run',
                'line' => 5,
            ],
            'declaration:callable:App\\Subject::run@src/Subjects.php',
        ];
        yield 'function' => [
            ['subjectKind' => 'declaration', 'logicalKind' => 'function', 'namespace' => 'App', 'member' => 'run', 'line' => 5],
            'declaration:func:App::run@src/Subjects.php',
        ];
    }

    #[Test]
    public function smellDetectedProducesFinding(): void
    {
        $rule = new BooleanArgumentRule(new BooleanArgumentOptions());

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/Smelly.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Smelly.php'), null);

        $metricBag = (new MetricBag())
            ->withEntry('codeSmell.boolean_argument', ['subjectKind' => 'file', 'line' => 10])
            ->withEntry('codeSmell.boolean_argument', ['subjectKind' => 'file', 'line' => 25]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolLevel $level) => $level === SymbolLevel::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(2, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame(10, $findings[0]->location->line);
        self::assertSame(25, $findings[1]->location->line);
        self::assertSame('Boolean argument detected - consider splitting methods or using enums', $findings[0]->message);
        self::assertSame('code-smell.boolean-argument', $findings[0]->ruleName);
        self::assertSame('code-smell.boolean-argument', $findings[0]->code);
        self::assertSame(1.0, $findings[0]->metricValue);
        self::assertSame('file:src/Smelly.php', $findings[0]->subject->toCanonical());
        self::assertTrue($findings[0]->location->precise);
        self::assertSame('Replace boolean parameter with two explicit methods or use an enum.', $findings[0]->recommendation);
        self::assertSame(
            OccurrenceKey::semantic('boolean_argument', [
                'type' => 'boolean_argument',
                'extra' => '',
                'hasExtra' => false,
                'promoted' => false,
                'hasPromoted' => false,
            ])->value,
            $findings[0]->occurrenceKey?->value,
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
            ->willReturnCallback(fn(SymbolLevel $level) => $level === SymbolLevel::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(2, $findings);
        self::assertSame('Boolean argument $overwrite detected - consider splitting methods or using enums', $findings[0]->message);
        self::assertSame('Boolean argument $silent detected - consider splitting methods or using enums', $findings[1]->message);
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
            ->willReturnCallback(fn(SymbolLevel $level) => $level === SymbolLevel::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame('Boolean argument detected - consider splitting methods or using enums', $findings[0]->message);
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
            ->willReturnCallback(fn(SymbolLevel $level) => $level === SymbolLevel::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(2, $findings);
        self::assertSame(30, $findings[0]->location->line);
        self::assertSame(40, $findings[1]->location->line);
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
            ->willReturnCallback(fn(SymbolLevel $level) => $level === SymbolLevel::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(2, $findings);
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
            ->willReturnCallback(fn(SymbolLevel $level) => $level === SymbolLevel::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(
            'Boolean argument detected - consider splitting methods or using enums',
            $findings[0]->message,
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
            ->willReturnCallback(fn(SymbolLevel $level) => $level === SymbolLevel::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
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
            ->willReturnCallback(fn(SymbolLevel $level) => $level === SymbolLevel::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertSame([], $findings);
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
            ->willReturnCallback(fn(SymbolLevel $level) => $level === SymbolLevel::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
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
            ->willReturnCallback(fn(SymbolLevel $level) => $level === SymbolLevel::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
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
            ->willReturnCallback(fn(SymbolLevel $level) => $level === SymbolLevel::File ? [$fileInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertSame([], $findings);
    }
}

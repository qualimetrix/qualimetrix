<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Parallel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Analysis\Policy\Inline\Contract\Threshold\ThresholdDiagnostic;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingFailureKind;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingResult;
use Qualimetrix\Analysis\Run\Contract\Collection\SuccessfulFileProcessing;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Parallel\FileProcessingTask;
use ReflectionProperty;

/**
 * Pins serialization round-trip stability for the worker-IPC types after
 * ADR 0015 Phase 1b. The VO wire format is `['value' => string]`, so renaming
 * a private property would break IPC unnoticed without this guard.
 *
 * Replaces the brittle `@requires extension parallel` integration tests with
 * pure PHP serialize / igbinary_serialize round-trips that can run on any
 * CI matrix.
 */
#[CoversClass(FileProcessingTask::class)]
#[CoversClass(FileProcessingResult::class)]
final class FileProcessingResultWireFormatTest extends TestCase
{
    private const string TRAVERSAL_PARTICIPANT_CLASS = 'Qualimetrix\\Analysis\\Evidence\\DependencyModel\\Extraction\\DependencyVisitor';

    #[Test]
    public function itRoundTripsFileProcessingTaskViaPhpSerialize(): void
    {
        $task = new FileProcessingTask(
            filePath: AbsolutePath::fromString('/tmp/x.php'),
            projectRoot: AbsolutePath::fromString('/tmp'),
            collectorClasses: [],
            dependencyTraversalParticipantClass: self::TRAVERSAL_PARTICIPANT_CLASS,
            cacheDir: AbsolutePath::fromString('/tmp/cache'),
        );

        $payload = serialize($task);

        // Pin the VO wire shape: AbsolutePath / RelativePath serialize as
        // ['value' => '...'] via __serialize. Renaming the private property
        // would silently break IPC without this assertion.
        self::assertStringContainsString('"value"', $payload);

        $restored = unserialize($payload);

        self::assertInstanceOf(FileProcessingTask::class, $restored);

        $filePathProperty = new ReflectionProperty($restored, 'filePath');
        $filePath = $filePathProperty->getValue($restored);
        self::assertInstanceOf(AbsolutePath::class, $filePath);
        self::assertSame('/tmp/x.php', $filePath->value());

        // Round-trip projectRoot + cacheDir (ADR 0015 Phase 5)
        $projectRootProperty = new ReflectionProperty($restored, 'projectRoot');
        $projectRoot = $projectRootProperty->getValue($restored);
        self::assertInstanceOf(AbsolutePath::class, $projectRoot);
        self::assertSame('/tmp', $projectRoot->value());

        $cacheDirProperty = new ReflectionProperty($restored, 'cacheDir');
        $cacheDir = $cacheDirProperty->getValue($restored);
        self::assertInstanceOf(AbsolutePath::class, $cacheDir);
        self::assertSame('/tmp/cache', $cacheDir->value());

        $participantClassProperty = new ReflectionProperty($restored, 'dependencyTraversalParticipantClass');
        self::assertSame(self::TRAVERSAL_PARTICIPANT_CLASS, $participantClassProperty->getValue($restored));
    }

    #[Test]
    public function itRoundTripsFileProcessingResultSuccessViaPhpSerialize(): void
    {
        $path = RelativePath::fromString('src/X.php');
        $subject = MetricSubject::declaration(new DeclarationPath(SymbolPath::forClass('One', 'Thing'), $path, 11));
        $callable = new CallableWithMetrics(
            new DeclarationPath(SymbolPath::forMethod('One', 'Thing', 'run'), $path, 17),
            CallableKind::Method,
            null,
            null,
            new LogicalClassPath(SymbolPath::forClass('One', 'Thing')),
            MetricBag::fromArray(['ccn' => 2]),
            17,
        );
        $dependency = new Dependency(
            new DeclarationPath(SymbolPath::forClass('One', 'Thing'), $path, 11),
            new LogicalClassPath(SymbolPath::forClass('Two', 'Port')),
            DependencyType::Implements,
            new Location($path, 11),
        );
        $suppression = new Suppression(
            'complexity',
            'fixture',
            12,
            SuppressionType::Symbol,
            subject: $subject,
            controlScope: ControlScope::Class_,
        );
        $override = new ThresholdOverride('complexity.cyclomatic', 10, 20, 13, $subject, ControlScope::Class_);
        $diagnostic = new ThresholdDiagnostic(14, $subject, 'invalid threshold');

        $result = FileProcessingResult::success(
            filePath: $path,
            payload: new SuccessfulFileProcessing(
                fileBag: (new MetricBag())
                    ->with('loc', 7)
                    ->withEntry('codeSmell.eval', ['subjectKind' => 'file', 'line' => 7]),
                callableMetrics: [$callable],
                classMetrics: ['class' => ['subject' => $subject, 'metrics' => MetricBag::fromArray(['wmc' => 4]), 'line' => 11]],
                namespaceMetrics: [
                    'namespace:One' => [
                        'symbolPath' => SymbolPath::forNamespace('One'),
                        'metrics' => MetricBag::fromArray(['loc' => 3]),
                        'line' => 2,
                    ],
                ],
                dependencies: [$dependency],
                suppressions: [$suppression],
                thresholdOverrides: [$override],
                thresholdDiagnostics: [$diagnostic],
            ),
        );

        $restored = unserialize(serialize($result));

        self::assertInstanceOf(FileProcessingResult::class, $restored);
        self::assertTrue($restored->isSuccessful());
        self::assertSame('src/X.php', $restored->filePath->value());
        self::assertSame(7, $restored->fileBag()->get('loc'));
        self::assertSame(['subjectKind' => 'file', 'line' => 7], $restored->fileBag()->entries('codeSmell.eval')[0]);
        self::assertEquals($callable, $restored->callableMetrics()[0]);
        self::assertSame(4, $restored->classMetrics()['class']['metrics']->get('wmc'));
        self::assertSame(3, $restored->namespaceMetrics()['namespace:One']['metrics']->get('loc'));
        self::assertEquals($dependency, $restored->dependencies()[0]);
        self::assertEquals($suppression, $restored->suppressions()[0]);
        self::assertEquals($override, $restored->thresholdOverrides()[0]);
        self::assertEquals($diagnostic, $restored->thresholdDiagnostics()[0]);
    }

    #[Test]
    public function itRoundTripsFileProcessingResultFailureViaPhpSerialize(): void
    {
        $result = FileProcessingResult::failure(
            filePath: RelativePath::fromString('src/Bad.php'),
            error: 'parse error',
            kind: FileProcessingFailureKind::Processing,
        );

        $restored = unserialize(serialize($result));

        self::assertInstanceOf(FileProcessingResult::class, $restored);
        self::assertFalse($restored->isSuccessful());
        self::assertSame('src/Bad.php', $restored->filePath->value());
        self::assertSame('parse error', $restored->error());
        self::assertSame(FileProcessingFailureKind::Processing, $restored->failureKind());
    }

    #[Test]
    #[RequiresPhpExtension('igbinary')]
    public function itRoundTripsFileProcessingResultViaIgbinary(): void
    {
        $result = FileProcessingResult::success(
            filePath: RelativePath::fromString('src/X.php'),
            payload: new SuccessfulFileProcessing(fileBag: MetricBag::fromArray(['loc' => 42])),
        );

        $payload = igbinary_serialize($result);
        self::assertNotNull($payload);

        $restored = igbinary_unserialize($payload);

        self::assertInstanceOf(FileProcessingResult::class, $restored);
        self::assertSame('src/X.php', $restored->filePath->value());
        self::assertSame(42, $restored->fileBag()->get('loc'));
    }
}

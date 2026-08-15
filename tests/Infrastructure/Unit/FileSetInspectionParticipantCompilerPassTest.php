<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Unit;

use Closure;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Run\Contract\FileSetInspectionParticipantInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\FileSetInspectionParticipantCompilerPass;
use ReflectionProperty;
use SplFileInfo;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(FileSetInspectionParticipantCompilerPass::class)]
final class FileSetInspectionParticipantCompilerPassTest extends TestCase
{
    private const string COMPOSITE_ID = 'qmx.analysis.run.file_set_inspection_composite';

    #[Test]
    public function itInjectsParticipantsInLexicalIdOrderIndependentOfRegistrationOrder(): void
    {
        $container = $this->containerWithComposite();
        $this->registerParticipant($container, 'z-service', ZetaParticipant::class);
        $this->registerParticipant($container, 'a-service', AlphaParticipant::class);

        (new FileSetInspectionParticipantCompilerPass())->process($container);

        $references = $container->getDefinition(self::COMPOSITE_ID)->getArgument('$participants');
        self::assertIsArray($references);
        self::assertContainsOnlyInstancesOf(Reference::class, $references);
        self::assertSame(['a-service', 'z-service'], array_map(static fn(Reference $reference): string => (string) $reference, $references));
    }

    #[Test]
    public function itRejectsAnEmptyParticipantId(): void
    {
        (new ReflectionProperty(EmptyIdParticipant::class, 'provider'))
            ->setValue(null, static fn(): string => '');
        $container = $this->containerWithComposite();
        $this->registerParticipant($container, 'empty-id', EmptyIdParticipant::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File-set inspection participant ' . EmptyIdParticipant::class . '::participantId() must return a non-empty string.');

        (new FileSetInspectionParticipantCompilerPass())->process($container);
    }

    #[Test]
    public function itRejectsAnEmptyProducerRuleName(): void
    {
        (new ReflectionProperty(EmptyRuleParticipant::class, 'provider'))
            ->setValue(null, static fn(): string => '');
        $container = $this->containerWithComposite();
        $this->registerParticipant($container, 'empty-rule', EmptyRuleParticipant::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File-set inspection participant ' . EmptyRuleParticipant::class . '::producerRuleName() must return a non-empty string.');

        (new FileSetInspectionParticipantCompilerPass())->process($container);
    }

    #[Test]
    public function itRejectsATaggedClassThatDoesNotImplementTheParticipantContract(): void
    {
        $container = $this->containerWithComposite();
        $container->setDefinition('not-participant', new Definition(NotAParticipant::class))
            ->addTag(FileSetInspectionParticipantCompilerPass::TAG);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf(
            'File-set inspection service "not-participant" (%s) must implement %s.',
            NotAParticipant::class,
            FileSetInspectionParticipantInterface::class,
        ));

        (new FileSetInspectionParticipantCompilerPass())->process($container);
    }

    #[Test]
    public function itRejectsAServiceWithoutAResolvableClass(): void
    {
        $container = $this->containerWithComposite();
        $container->setDefinition('missing-class', new Definition())
            ->addTag(FileSetInspectionParticipantCompilerPass::TAG);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File-set inspection service "missing-class" has no resolvable class.');

        (new FileSetInspectionParticipantCompilerPass())->process($container);
    }

    #[Test]
    public function itReportsTheDuplicateIdAndBothParticipantClasses(): void
    {
        $container = $this->containerWithComposite();
        $this->registerParticipant($container, 'first', AlphaParticipant::class);
        $this->registerParticipant($container, 'second', DuplicateAlphaParticipant::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf(
            'Duplicate file-set inspection participant id "alpha" declared by %s and %s.',
            AlphaParticipant::class,
            DuplicateAlphaParticipant::class,
        ));

        (new FileSetInspectionParticipantCompilerPass())->process($container);
    }

    #[Test]
    public function itReadsStaticMetadataWithoutInstantiatingParticipantServices(): void
    {
        ConstructorRejectingParticipant::$constructionAttempts = 0;
        $container = $this->containerWithComposite();
        $this->registerParticipant($container, 'constructor-rejecting', ConstructorRejectingParticipant::class);

        (new FileSetInspectionParticipantCompilerPass())->process($container);

        self::assertSame(0, ConstructorRejectingParticipant::$constructionAttempts);
    }

    private function containerWithComposite(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition(self::COMPOSITE_ID, new Definition(stdClass::class));

        return $container;
    }

    /** @param class-string<FileSetInspectionParticipantInterface> $class */
    private function registerParticipant(ContainerBuilder $container, string $id, string $class): void
    {
        $container->setDefinition($id, new Definition($class))
            ->addTag(FileSetInspectionParticipantCompilerPass::TAG);
    }
}

abstract class TestFileSetInspectionParticipant implements FileSetInspectionParticipantInterface
{
    public static function producerRuleName(): string
    {
        return 'test.rule';
    }

    public function resetForRun(): void {}

    /** @param list<SplFileInfo> $eligibleFiles */
    public function inspect(array $eligibleFiles, AbsolutePath $projectRoot): void {}
}

final class AlphaParticipant extends TestFileSetInspectionParticipant
{
    public static function participantId(): string
    {
        return 'alpha';
    }
}

final class ZetaParticipant extends TestFileSetInspectionParticipant
{
    public static function participantId(): string
    {
        return 'zeta';
    }
}

final class DuplicateAlphaParticipant extends TestFileSetInspectionParticipant
{
    public static function participantId(): string
    {
        return 'alpha';
    }
}

final class EmptyIdParticipant extends TestFileSetInspectionParticipant
{
    /** @var (Closure(): non-empty-string)|null */
    private static ?Closure $provider = null;

    public static function participantId(): string
    {
        self::$provider ??= static fn(): string => 'valid';

        return (self::$provider)();
    }
}

final class EmptyRuleParticipant extends TestFileSetInspectionParticipant
{
    /** @var (Closure(): non-empty-string)|null */
    private static ?Closure $provider = null;

    public static function participantId(): string
    {
        return 'empty-rule';
    }

    public static function producerRuleName(): string
    {
        self::$provider ??= static fn(): string => 'valid';

        return (self::$provider)();
    }
}

final class ConstructorRejectingParticipant extends TestFileSetInspectionParticipant
{
    public static int $constructionAttempts = 0;

    public function __construct()
    {
        self::$constructionAttempts++;
        throw new LogicException('Compiler pass must not instantiate participants.');
    }

    public static function participantId(): string
    {
        return 'constructor-rejecting';
    }
}

final class NotAParticipant {}

<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\ConfigurationValidatorInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Rule\RuleInterface;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Finding\RuleExecution;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ChannelDeclarationCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ConfigurationValidatorCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleRegistryCompilerPass;
use Qualimetrix\Infrastructure\Rule\ChannelUniverse;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * "This finding is about the configuration" is stamped in one place, and
 * nowhere else can reach it.
 *
 * The repository-topology half of this subject —
 * {@see \Qualimetrix\Governance\Channel\ConfigurationErrorClassificationTopologyTest} —
 * counts the production sites that can turn a declaration into a
 * configuration error. This half is the product test: it runs the real
 * compiler pass and executor against fixture rule/validator classes declared
 * below and proves the stamp follows the producing type.
 */
#[CoversClass(ChannelDeclaration::class)]
final class ConfigurationErrorClassificationTopologyTest extends TestCase
{
    /**
     * The stamp follows the producing type and nothing else: two channels of
     * the same shape, declared by a rule and by a validator, come out of the
     * pass classified differently.
     */
    #[Test]
    public function itStampsTheAssemblyWithExactlyWhatAValidatorDeclares(): void
    {
        $container = self::containerWith(new StampRule(), new StampValidator());
        (new ChannelDeclarationCompilerPass())->process($container);

        /** @var array<string, ChannelDeclaration> $declarations */
        $declarations = $container->getDefinition(ChannelUniverse::class)->getArgument('$staticDeclarations');

        self::assertFalse($declarations['stamp.rule']->isConfigurationError());
        self::assertTrue($declarations['stamp.diagnostic']->isConfigurationError());
    }

    /**
     * Negative control on the transfer, build side: a validator that borrows
     * a producer name no rule answers to would give its channels a
     * description, a documentation page and a remediation estimate resolved
     * from nothing.
     */
    #[Test]
    public function itFailsTheBuildWhenAValidatorNamesAProducerThatIsNotARule(): void
    {
        $container = self::containerWith(new StampRule(), new OrphanedValidator());

        self::expectException(LogicException::class);
        self::expectExceptionMessage('is not a registered rule');

        (new ChannelDeclarationCompilerPass())->process($container);
    }

    /**
     * Negative control on the transfer, build side: a channel cannot be
     * declared by a rule and a validator at once, because that is exactly the
     * state in which "configuration error" would depend on which producer the
     * pass happened to read last.
     */
    #[Test]
    public function itFailsTheBuildWhenAChannelIsDeclaredByBothProducerKinds(): void
    {
        $container = self::containerWith(new StampRule(), new PoachingValidator());

        self::expectException(LogicException::class);
        self::expectExceptionMessage('Duplicate channel declaration');

        (new ChannelDeclarationCompilerPass())->process($container);
    }

    /**
     * Negative control on the transfer, run side: a validator emitting on a
     * channel it does not declare would publish a configuration-error
     * producer's finding under a channel classified as ordinary debt —
     * acceptable by the ratchet, silenceable by a directive, gated by
     * `fail_on`. The executor refuses.
     */
    #[Test]
    public function itEndsTheRunWhenAValidatorEmitsOnAChannelItDoesNotDeclare(): void
    {
        $execution = new RuleExecution(
            [new StampRule()],
            self::createStub(ProfilerInterface::class),
            new RuleOptionsRegistry(),
            null,
            [new TrespassingValidator()],
        );

        self::expectException(LogicException::class);
        self::expectExceptionMessage('which it does not declare');

        $execution->execute(new AnalysisContext(
            self::createStub(\Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface::class),
        ));
    }

    private static function containerWith(RuleInterface $rule, ConfigurationValidatorInterface $validator): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(ChannelUniverse::class)
            ->setClass(ChannelUniverse::class)
            ->setArguments([
                '$staticDeclarations' => [],
                '$staticChannelKeysByProducer' => [],
                '$thresholdOverrideSupportByRule' => [],
                '$computedMetricRuleName' => '',
            ]);
        $container->register($rule::class)->setClass($rule::class)->addTag(RuleRegistryCompilerPass::TAG);
        $container->register($validator::class)
            ->setClass($validator::class)
            ->addTag(ConfigurationValidatorCompilerPass::TAG);

        return $container;
    }
}

/** A rule owning one ordinary channel plus the name the validators borrow. */
final class StampRule implements RuleInterface
{
    public const string NAME = 'stamp.rule';
    public const string DOCS_PAGE = 'rules/stamp.md';
    public const int REMEDIATION_MINUTES = 5;

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Fixture rule.';
    }

    public static function shape(): ChannelShape
    {
        return ChannelShape::Occurrence;
    }

    /**
     * A double with no producers of its own: an empty activity declares
     * nothing, and absence is not disablement.
     *
     * @return array<string, array<string, bool>>
     */
    public function levelActivity(): array
    {
        return [];
    }

    public function analyze(AnalysisContext $context): array
    {
        return [];
    }

    /** @return class-string<RuleOptionsInterface> */
    public static function getOptionsClass(): string
    {
        return StampOptions::class;
    }

    /** @return array<string, ChannelDeclaration> */
    public static function channelDeclarations(): array
    {
        return ['stamp.rule' => ChannelDeclaration::occurrence(SymbolLevel::Project)];
    }
}

final readonly class StampOptions implements RuleOptionsInterface
{
    public static function fromArray(array $config): self
    {
        return new self();
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        return null;
    }

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([]);
    }
}

/** Same shape as the rule's channel; the pass classifies it differently. */
final class StampValidator implements ConfigurationValidatorInterface
{
    public static function producerRuleName(): string
    {
        return StampRule::NAME;
    }

    public static function shape(): ChannelShape
    {
        return ChannelShape::Occurrence;
    }

    public static function channelDeclarations(): array
    {
        return ['stamp.diagnostic' => ChannelDeclaration::occurrence(SymbolLevel::Project)];
    }

    public function validate(AnalysisContext $context): array
    {
        return [];
    }
}

final class OrphanedValidator implements ConfigurationValidatorInterface
{
    public static function producerRuleName(): string
    {
        return 'stamp.nobody';
    }

    public static function shape(): ChannelShape
    {
        return ChannelShape::Occurrence;
    }

    public static function channelDeclarations(): array
    {
        return ['stamp.nobody' => ChannelDeclaration::occurrence(SymbolLevel::Project)];
    }

    public function validate(AnalysisContext $context): array
    {
        return [];
    }
}

final class PoachingValidator implements ConfigurationValidatorInterface
{
    public static function producerRuleName(): string
    {
        return StampRule::NAME;
    }

    public static function shape(): ChannelShape
    {
        return ChannelShape::Occurrence;
    }

    public static function channelDeclarations(): array
    {
        return ['stamp.rule' => ChannelDeclaration::occurrence(SymbolLevel::Project)];
    }

    public function validate(AnalysisContext $context): array
    {
        return [];
    }
}

final class TrespassingValidator implements ConfigurationValidatorInterface
{
    public static function producerRuleName(): string
    {
        return StampRule::NAME;
    }

    public static function shape(): ChannelShape
    {
        return ChannelShape::Occurrence;
    }

    public static function channelDeclarations(): array
    {
        return ['stamp.diagnostic' => ChannelDeclaration::occurrence(SymbolLevel::Project)];
    }

    public function validate(AnalysisContext $context): array
    {
        $subject = MetricSubject::aggregate(SymbolPath::forProject());

        return [new Finding(
            location: Location::none(),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: StampRule::NAME,
            code: StampRule::NAME,
            message: 'A finding on the rule-owned channel.',
            severity: Severity::Error,
        )];
    }
}

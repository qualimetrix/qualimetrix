<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use FilesystemIterator;
use LogicException;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\ConfigurationValidatorInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Rule\RuleInterface;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Finding\RuleExecution;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ChannelDeclarationCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ConfigurationValidatorCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleRegistryCompilerPass;
use Qualimetrix\Infrastructure\Rule\ChannelUniverse;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * "This finding is about the configuration" is stamped in one place, and
 * nowhere else can reach it.
 *
 * Before the split, every producer authored the answer for its own channels,
 * which is how a rule came to hold five channels it could never baseline
 * beside two it could. The answer is now a consequence of the producing type,
 * and a consequence is only as trustworthy as the number of places that can
 * produce it — so this file counts them.
 *
 * Three halves, and all are needed. The first counts calls spelled literally:
 * it parses production sources and finds every call whose method name is the
 * wither, whatever receiver it is called on — a receiver is unconstrained, a
 * dynamic method name is not, which is why there is a second. The second pins
 * the name itself: no production file outside the assembly point and its own
 * documentation may so much as mention it, which is what an indirect call
 * would have to do. The third closes the way around the wither entirely — the
 * constructor is private, so no caller can hand the flag in directly, and the
 * two public factories both yield `false`.
 */
#[CoversClass(ChannelDeclaration::class)]
final class ConfigurationErrorClassificationTopologyTest extends TestCase
{
    private const string WITHER = 'asConfigurationError';

    #[Test]
    public function exactlyOneProductionSiteTurnsADeclarationIntoAConfigurationError(): void
    {
        $sites = [];

        foreach (self::productionFiles() as $file) {
            $finder = new NodeFinder();
            $ast = self::parse($file);

            /** @var list<Node\Expr\MethodCall|Node\Expr\StaticCall|Node\Expr\NullsafeMethodCall> $calls */
            $calls = $finder->find($ast, static function (Node $node): bool {
                if (!$node instanceof Node\Expr\MethodCall
                    && !$node instanceof Node\Expr\StaticCall
                    && !$node instanceof Node\Expr\NullsafeMethodCall
                ) {
                    return false;
                }

                return $node->name instanceof Node\Identifier && $node->name->toString() === self::WITHER;
            });

            foreach ($calls as $call) {
                $sites[] = \sprintf('%s:%d', self::relative($file), $call->getStartLine());
            }
        }

        self::assertCount(
            1,
            $sites,
            'The classification must be applied where the channel registry is assembled and the declaring'
            . ' producer type is known, and nowhere else. Sites found: ' . implode(', ', $sites),
        );
        self::assertStringContainsString('ChannelDeclarationCompilerPass', $sites[0]);
    }

    /**
     * What the parse above cannot see, closed by text.
     *
     * The finder matches a literal method name, so `$declaration->{$m}()`,
     * `[$declaration, 'asConfigurationError']()` and `call_user_func(...)`
     * would all be invisible to it. Every one of them has to spell the name
     * somewhere, so the name itself is what is pinned: outside the class that
     * declares the wither and the pass that calls it, no production file may
     * mention it at all.
     *
     * Residual, stated rather than hidden: a name assembled from fragments at
     * run time defeats both halves. Nothing in this codebase builds method
     * names that way, and the `@internal` marker on the wither says the same
     * thing to a reader.
     */
    #[Test]
    public function noOtherProductionFileEvenNamesTheWither(): void
    {
        $allowed = [
            'src/Analysis/Finding/Contract/ChannelDeclaration.php',
            'src/Analysis/Finding/Contract/ConfigurationValidatorInterface.php',
            'src/Infrastructure/DependencyInjection/CompilerPass/ChannelDeclarationCompilerPass.php',
        ];

        $mentions = [];

        foreach (self::productionFiles() as $file) {
            $relative = self::relative($file);

            if (\in_array($relative, $allowed, true)) {
                continue;
            }

            $contents = file_get_contents($file);

            if ($contents === false) {
                throw new RuntimeException(\sprintf('Could not read %s.', $file));
            }

            // Word-boundary on the left so `hasConfigurationError` and friends
            // are not mistaken for a reference to the wither.
            if (preg_match('/(?<![A-Za-z0-9_$])' . self::WITHER . '/', $contents) === 1) {
                $mentions[] = $relative;
            }
        }

        self::assertSame(
            [],
            $mentions,
            'The classification wither is registry-assembly internal. A production file that names it is either a'
            . ' second assembly site the AST count cannot see, or a reference that will become one. Files: '
            . implode(', ', $mentions),
        );
    }

    #[Test]
    public function noProductionSiteCanHandTheFlagToTheConstructorInstead(): void
    {
        $constructor = (new ReflectionClass(ChannelDeclaration::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue(
            $constructor->isPrivate(),
            'A public constructor taking the flag would be a second assembly site the parse above cannot see.',
        );
        self::assertFalse(ChannelDeclaration::occurrence(SymbolLevel::Project)->isConfigurationError());
        self::assertFalse(
            ChannelDeclaration::magnitude(
                \Qualimetrix\Core\Observation\WorseDirection::Higher,
                SymbolLevel::Project,
            )->isConfigurationError(),
        );
    }

    /**
     * The stamp follows the producing type and nothing else: two channels of
     * the same shape, declared by a rule and by a validator, come out of the
     * pass classified differently.
     */
    #[Test]
    public function theAssemblyStampsExactlyWhatAValidatorDeclares(): void
    {
        $container = self::containerWith(new StampRule(), new StampValidator());
        (new ChannelDeclarationCompilerPass())->process($container);

        /** @var array<string, ChannelDeclaration> $declarations */
        $declarations = $container->getDefinition(ChannelUniverse::class)->getArgument('$staticDeclarations');

        self::assertFalse($declarations['stamp.rule#stamp.rule']->isConfigurationError());
        self::assertTrue($declarations['stamp.rule#stamp.diagnostic']->isConfigurationError());
    }

    /**
     * Negative control on the transfer, build side: a validator that borrows
     * a producer name no rule answers to would give its channels a
     * description, a documentation page and a remediation estimate resolved
     * from nothing.
     */
    #[Test]
    public function aValidatorNamingAProducerThatIsNotARuleFailsTheBuild(): void
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
    public function aChannelDeclaredByBothAProducerKindsFailsTheBuild(): void
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
    public function aValidatorEmittingOnAChannelItDoesNotDeclareEndsTheRun(): void
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

    /**
     * @return list<string>
     */
    private static function productionFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::sourceRoot(), FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return array<Node>
     */
    private static function parse(string $file): array
    {
        $contents = file_get_contents($file);

        if ($contents === false) {
            throw new RuntimeException(\sprintf('Could not read %s.', $file));
        }

        return (new ParserFactory())->createForHostVersion()->parse($contents) ?? [];
    }

    private static function sourceRoot(): string
    {
        return \dirname(__DIR__, 4) . '/src';
    }

    private static function relative(string $file): string
    {
        return 'src' . substr($file, \strlen(self::sourceRoot()));
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

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Architecture;
    }

    public static function shape(): ChannelShape
    {
        return ChannelShape::Occurrence;
    }

    public function requires(): array
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
        return ['stamp.rule#stamp.rule' => ChannelDeclaration::occurrence(SymbolLevel::Project)];
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
        return ['stamp.rule#stamp.diagnostic' => ChannelDeclaration::occurrence(SymbolLevel::Project)];
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
        return ['stamp.nobody#stamp.nobody' => ChannelDeclaration::occurrence(SymbolLevel::Project)];
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
        return ['stamp.rule#stamp.rule' => ChannelDeclaration::occurrence(SymbolLevel::Project)];
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
        return ['stamp.rule#stamp.diagnostic' => ChannelDeclaration::occurrence(SymbolLevel::Project)];
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

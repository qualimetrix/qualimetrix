<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit\RuleConfiguration;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\CodeSmellOptions;
use Qualimetrix\Analysis\Evidence\CodeSmell\GotoRule;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\ConfigurationValidatorInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsBuild;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Finding\RuleExecution;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

final class RuleOptionsBuildTest extends TestCase
{
    #[Test]
    public function itDefersConstructionAndReplacesEveryMaterializedObjectWithTheInvocationSnapshot(): void
    {
        $registry = new RuleOptionsRegistry();
        $observed = new class {
            /** @var list<GotoRule> */
            public array $rules = [];
            /** @var list<CodeSmellOptions> */
            public array $options = [];
            public int $validators = 0;
        };
        $execution = new RuleExecution(
            [[
                'metadata' => new RuleMetadata(GotoRule::NAME, CodeSmellOptions::class, GotoRule::getDescription(), [], false),
                'create' => static function () use ($registry, $observed): GotoRule {
                    $current = $registry->optionsFor(GotoRule::NAME, CodeSmellOptions::class);
                    if (!$current instanceof CodeSmellOptions) {
                        throw new LogicException('Wrong options class.');
                    }
                    $observed->options[] = $current;
                    return $observed->rules[] = new GotoRule($current);
                },
            ]],
            self::createStub(ProfilerInterface::class),
            $registry,
            configurationValidators: [[
                'producer' => GotoRule::NAME,
                'create' => static function () use ($observed): ConfigurationValidatorInterface {
                    ++$observed->validators;
                    return new class implements ConfigurationValidatorInterface {
                        public static function producerRuleName(): string
                        {
                            return GotoRule::NAME;
                        }
                        public static function shape(): ChannelShape
                        {
                            return ChannelShape::Occurrence;
                        }
                        public static function channelDeclarations(): array
                        {
                            return [];
                        }
                        public function validate(AnalysisContext $context): array
                        {
                            return [];
                        }
                    };
                },
            ]],
        );
        self::assertCount(1, $execution->allRules());
        self::assertSame([], $observed->rules);
        self::assertSame(0, $observed->validators);
        $builder = new RuleOptionsBuild($execution);
        $first = ResolvedOptionsFixture::authoredConfiguration(FindingConfiguration::none(), $execution->allRules());
        $first = $first->withResolvedOptions($builder->build($first));
        self::assertSame([], $observed->rules);
        $registry->replace($first);
        $context = new AnalysisContext(self::createStub(MetricRepositoryInterface::class));
        $execution->execute($context);
        $execution->execute($context);
        self::assertCount(1, $observed->rules);
        self::assertSame(1, $observed->validators);
        self::assertSame($first->resolvedOptions?->for(GotoRule::NAME), $observed->options[0]);
        $registry->resetRuntimeState();
        try {
            $execution->levelActivity();
            self::fail('Activity read an unconfigured invocation.');
        } catch (LogicException $error) {
            self::assertSame('Rule options are unavailable before analysis preflight.', $error->getMessage());
        }
        self::assertCount(1, $execution->allRules());
        self::assertCount(1, $observed->rules);
        $second = ResolvedOptionsFixture::authoredConfiguration(FindingConfiguration::none()->withRuleOptions([GotoRule::NAME => ['enabled' => false]]), $execution->allRules());
        $second = $second->withResolvedOptions($builder->build($second));
        $registry->replace($second);
        self::assertFalse($execution->levelActivity()->toMap()[GotoRule::NAME]['callable']);
        self::assertCount(2, $observed->rules);
        self::assertNotSame($observed->rules[0], $observed->rules[1]);
        self::assertNotSame($observed->options[0], $observed->options[1]);
        self::assertSame($second->resolvedOptions?->for(GotoRule::NAME), $observed->options[1]);
    }

    #[Test]
    public function itRefusesOptionsLookupBeforePreflight(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('Rule options are unavailable before analysis preflight.');
        (new RuleOptionsRegistry())->optionsFor(GotoRule::NAME, CodeSmellOptions::class);
    }
}

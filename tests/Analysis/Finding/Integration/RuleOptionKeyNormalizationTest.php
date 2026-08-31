<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\LongParameterListOptions;
use Qualimetrix\Analysis\Evidence\CodeSmell\LongParameterListRule;
use Qualimetrix\Analysis\Evidence\Design\TypeCoverage\ParamTypeCoverageRule;
use Qualimetrix\Analysis\Evidence\Design\TypeCoverage\ReturnTypeCoverageRule;
use Qualimetrix\Analysis\Evidence\Design\TypeCoverage\TypeCoverageOptions;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsParser;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsParserFactory;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Tests\TestSupport\Logging\Support\RecordingLogger;

/**
 * Regression test: composite (multi-word) rule option names — `vo-warning` /
 * `vo-error` / `vo-threshold` for {@see LongParameterListRule}, and
 * bare `warning` / `error` / `threshold` on each of the three type-coverage
 * rules
 * — must apply identically no matter which of the three channels sets them:
 *
 * 1. `qmx.yaml` / preset config-file options
 *    ({@see RuleOptionsRegistry::setConfigFileOptions()}), normalized to
 *    camelCase by {@see RuleOptionsFactory}'s internal key normalization.
 * 2. `--rule-opt=rule:option=value` ({@see RuleOptionsParser::parseRuleOptions()}),
 *    normalized to camelCase by `RuleOptionsParser::normalizeOptionName()`.
 * 3. The rule's dedicated CLI flag (`#[CliAlias(...)]`), resolved through
 *    {@see RuleOptionsParser::parseShortAlias()}.
 *
 * Before the fix:
 * - Channel 3 applied the threshold correctly but logged a false "Unknown
 *   option" warning — the alias's raw kebab/snake_case string bypassed
 *   normalization, so it never matched the (camelCase) known-key set built
 *   from the Options class's constructor parameters.
 * - Channels 1 and 2 normalized the key to camelCase, which `ThresholdParser`
 *   inside `fromArray()` did not recognize (it only queried the
 *   kebab/snake_case spelling), so the threshold was silently dropped —
 *   worst case: the exact spelling the false warning recommended.
 */
#[CoversClass(RuleOptionsFactory::class)]
#[CoversClass(RuleOptionsParser::class)]
#[CoversClass(LongParameterListOptions::class)]
#[CoversClass(TypeCoverageOptions::class)]
#[Group('regression')]
final class RuleOptionKeyNormalizationTest extends TestCase
{
    private RuleOptionsRegistry $registry;

    private RuleOptionsFactory $factory;

    private RecordingLogger $logger;

    private RuleOptionsParser $ruleOptionsParser;

    protected function setUp(): void
    {
        $this->registry = new RuleOptionsRegistry();
        $this->logger = new RecordingLogger();
        $this->factory = new RuleOptionsFactory($this->registry, $this->logger);
        $this->ruleOptionsParser = (new RuleOptionsParserFactory())->createFromClasses([
            LongParameterListRule::class,
            ParamTypeCoverageRule::class,
            ReturnTypeCoverageRule::class,
        ]);
    }

    // -- LongParameterListRule: vo-error --------------------------------------

    #[Test]
    public function itAppliesVoErrorViaConfigFileKebabKey(): void
    {
        $this->registry->setConfigFileOptions([
            'code-smell.long-parameter-list' => ['vo-error' => 3],
        ]);

        /** @var LongParameterListOptions $options */
        $options = $this->factory->create('code-smell.long-parameter-list', LongParameterListOptions::class);

        self::assertSame(3, $options->voError);
        self::assertSame([], $this->logger->records, 'qmx.yaml kebab-case "vo-error" must not trigger an Unknown option warning');
    }

    #[Test]
    public function itAppliesVoErrorViaRuleOptKebabSpelling(): void
    {
        $this->applyCliOptions($this->ruleOptionsParser->parseRuleOptions([
            'code-smell.long-parameter-list:vo-error=3',
        ]));

        /** @var LongParameterListOptions $options */
        $options = $this->factory->create('code-smell.long-parameter-list', LongParameterListOptions::class);

        self::assertSame(3, $options->voError);
        self::assertSame([], $this->logger->records);
    }

    #[Test]
    public function itAppliesVoErrorViaRuleOptCamelSpelling(): void
    {
        $this->applyCliOptions($this->ruleOptionsParser->parseRuleOptions([
            'code-smell.long-parameter-list:voError=3',
        ]));

        /** @var LongParameterListOptions $options */
        $options = $this->factory->create('code-smell.long-parameter-list', LongParameterListOptions::class);

        self::assertSame(3, $options->voError);
        self::assertSame([], $this->logger->records);
    }

    #[Test]
    public function itAppliesVoErrorViaDedicatedCliFlagWithoutFalseWarning(): void
    {
        $parsed = $this->ruleOptionsParser->parseShortAlias('long-parameter-list-vo-error', 3);
        self::assertNotNull($parsed);

        $this->registry->setCliOptions($parsed['rule'], [$parsed['option'] => $parsed['value']]);

        /** @var LongParameterListOptions $options */
        $options = $this->factory->create('code-smell.long-parameter-list', LongParameterListOptions::class);

        self::assertSame(3, $options->voError);
        self::assertSame(
            [],
            $this->logger->records,
            'The dedicated --long-parameter-list-vo-error flag must not trigger a false Unknown option warning',
        );
    }

    #[Test]
    public function itStillWarnsAboutAGenuinelyUnknownOption(): void
    {
        $this->registry->setConfigFileOptions([
            'code-smell.long-parameter-list' => ['not_a_real_option' => 3],
        ]);

        $this->factory->create('code-smell.long-parameter-list', LongParameterListOptions::class);

        self::assertCount(1, $this->logger->records);
        self::assertStringContainsString(
            'Unknown option "not-a-real-option"',
            $this->logger->records[0]['message'],
            'The offending key must be shown in the canonical kebab-case spelling the user can actually type, not the internal camelCase form',
        );
        self::assertStringContainsString(
            'Available options: enabled, warning, error, vo-warning, vo-error',
            $this->logger->records[0]['message'],
            'The suggested spelling must be the canonical (kebab-case) one the user can actually type',
        );
    }

    // -- the three type-coverage rules: bare warning / error ------------------
    //
    // Note: the `threshold` shorthand is not exercised through
    // RuleOptionsFactory in this file — that path is already covered by
    // RuleOptionsFactoryTest::itDoesNotWarnAboutTheThresholdShorthandOnTypeCoverage().
    // A previous version of RuleOptionsFactory::create() always seeded
    // $merged with every constructor default before fromArray() ran, which
    // made ThresholdParser's mixing-conflict check see "warning"/"error" as
    // present even when the user only set `threshold` — that bug has since
    // been fixed (RuleOptionsFactory now passes through only what the user
    // actually configured).

    #[Test]
    public function itAppliesErrorViaDedicatedCliFlagWithoutFalseWarning(): void
    {
        $parsed = $this->ruleOptionsParser->parseShortAlias('param-type-coverage-error', 90.0);
        self::assertNotNull($parsed);

        $this->registry->setCliOptions($parsed['rule'], [$parsed['option'] => $parsed['value']]);

        /** @var TypeCoverageOptions $options */
        $options = $this->factory->create('design.type-coverage.param', TypeCoverageOptions::class);

        self::assertSame(90.0, $options->error);
        self::assertSame([], $this->logger->records);
    }

    /**
     * One rule's option must not reach its siblings: they share the Options
     * class, and configuration is keyed by producer, not by class.
     */
    #[Test]
    public function itAppliesErrorViaRuleOptToOneDimensionOnly(): void
    {
        $this->applyCliOptions($this->ruleOptionsParser->parseRuleOptions([
            'design.type-coverage.return:error=85',
        ]));

        /** @var TypeCoverageOptions $configured */
        $configured = $this->factory->create('design.type-coverage.return', TypeCoverageOptions::class);
        /** @var TypeCoverageOptions $untouched */
        $untouched = $this->factory->create('design.type-coverage.param', TypeCoverageOptions::class);

        self::assertSame(85.0, $configured->error);
        self::assertSame(50.0, $untouched->error);
        self::assertSame([], $this->logger->records);
    }

    /**
     * @param array<string, array<string, mixed>> $cliOptionsByRule
     */
    private function applyCliOptions(array $cliOptionsByRule): void
    {
        foreach ($cliOptionsByRule as $ruleName => $options) {
            $this->registry->setCliOptions($ruleName, $options);
        }
    }
}

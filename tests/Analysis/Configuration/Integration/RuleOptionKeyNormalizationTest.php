<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\RuleOptionsFactory;
use Qualimetrix\Configuration\RuleOptionsParser;
use Qualimetrix\Configuration\RuleOptionsParserFactory;
use Qualimetrix\Configuration\RuleOptionsRegistry;
use Qualimetrix\Rules\CodeSmell\LongParameterListOptions;
use Qualimetrix\Rules\CodeSmell\LongParameterListRule;
use Qualimetrix\Rules\Design\TypeCoverageOptions;
use Qualimetrix\Rules\Design\TypeCoverageRule;
use Qualimetrix\Tests\Support\Logger\RecordingLogger;

/**
 * Regression test: composite (multi-word) rule option names — `vo-warning` /
 * `vo-error` / `vo-threshold` for {@see LongParameterListRule}, and
 * `param_warning` / `param_error` / `param_threshold` (plus the `return_*` /
 * `property_*` equivalents) for {@see \Qualimetrix\Rules\Design\TypeCoverageRule}
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
            TypeCoverageRule::class,
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

    // -- TypeCoverageRule: param_* / return_* / property_* --------------------
    //
    // Note: the per-dimension `param_threshold`/`return_threshold`/
    // `property_threshold` unified shorthand is not exercised through
    // RuleOptionsFactory in this file — that path is already covered by
    // RuleOptionsFactoryTest::itDoesNotWarnAboutTheParamThresholdShorthandOnTypeCoverage().
    // A previous version of RuleOptionsFactory::create() always seeded
    // $merged with every constructor default (paramWarning/paramError/etc.)
    // before fromArray() ran, which made ThresholdParser's mixing-conflict
    // check see "warning"/"error" as present even when the user only set
    // `threshold` — that bug has since been fixed (RuleOptionsFactory now
    // passes through only what the user actually configured). See
    // TypeCoverageOptionsTest::itAcceptsCamelCaseThresholdShorthand() for the
    // case-normalization regression test at the Options::fromArray() level,
    // which does not go through RuleOptionsFactory.

    #[Test]
    public function itAppliesParamErrorViaDedicatedCliFlagWithoutFalseWarning(): void
    {
        $parsed = $this->ruleOptionsParser->parseShortAlias('type-coverage-param-error', 90.0);
        self::assertNotNull($parsed);

        $this->registry->setCliOptions($parsed['rule'], [$parsed['option'] => $parsed['value']]);

        /** @var TypeCoverageOptions $options */
        $options = $this->factory->create('design.type-coverage', TypeCoverageOptions::class);

        self::assertSame(90.0, $options->paramError);
        self::assertSame([], $this->logger->records);
    }

    #[Test]
    public function itAppliesReturnErrorViaRuleOptSnakeCaseSpelling(): void
    {
        $this->applyCliOptions($this->ruleOptionsParser->parseRuleOptions([
            'design.type-coverage:return_error=85',
        ]));

        /** @var TypeCoverageOptions $options */
        $options = $this->factory->create('design.type-coverage', TypeCoverageOptions::class);

        self::assertSame(85.0, $options->returnError);
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

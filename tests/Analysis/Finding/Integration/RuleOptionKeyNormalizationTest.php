<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\CodeSmell\LongParameterListOptions;
use Qualimetrix\Analysis\Evidence\CodeSmell\LongParameterListRule;
use Qualimetrix\Analysis\Evidence\Design\TypeCoverage\ParamTypeCoverageRule;
use Qualimetrix\Analysis\Evidence\Design\TypeCoverage\ReturnTypeCoverageRule;
use Qualimetrix\Analysis\Evidence\Design\TypeCoverage\TypeCoverageOptions;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\Configuration\RuleOptionsBuild;
use Qualimetrix\Analysis\Finding\Contract\ResolvedRuleOptions;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\RuleConfiguration\OptionForms\RuleOptionDocumentForms;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsParser;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsParserFactory;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Infrastructure\Console\CliOptionsParser;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

/** The file, rule-opt and alias doors resolve the same declared producer option. */
#[CoversClass(RuleOptionsBuild::class)]
#[CoversClass(RuleOptionsParser::class)]
#[CoversClass(LongParameterListOptions::class)]
#[CoversClass(TypeCoverageOptions::class)]
#[Group('regression')]
final class RuleOptionKeyNormalizationTest extends TestCase
{
    private RuleOptionsRegistry $registry;

    private ResolvedOptionsFixture $factory;

    private RuleOptionsParser $ruleOptionsParser;

    protected function setUp(): void
    {
        $this->registry = new RuleOptionsRegistry();
        $this->factory = new ResolvedOptionsFixture($this->registry);
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
        $this->factory->inputs(['rules' => [
            'code-smell.long-parameter-list' => ['vo-warning' => 2, 'vo-error' => 3],
        ]]);

        /** @var LongParameterListOptions $options */
        $options = $this->factory->create('code-smell.long-parameter-list', LongParameterListOptions::class);

        self::assertSame(3, $options->voError);
    }

    #[Test]
    public function itAppliesVoErrorViaRuleOptKebabSpelling(): void
    {
        $resolved = $this->resolvedCliOptions(['--rule-opt' => ['code-smell.long-parameter-list:vo-warning=2', 'code-smell.long-parameter-list:vo-error=3']]);

        /** @var LongParameterListOptions $options */
        $options = $resolved->for('code-smell.long-parameter-list');

        self::assertSame(3, $options->voError);
    }

    #[Test]
    public function itAppliesVoErrorViaRuleOptCamelSpelling(): void
    {
        $resolved = $this->resolvedCliOptions(['--rule-opt' => ['code-smell.long-parameter-list:voWarning=2', 'code-smell.long-parameter-list:voError=3']]);

        /** @var LongParameterListOptions $options */
        $options = $resolved->for('code-smell.long-parameter-list');

        self::assertSame(3, $options->voError);
    }

    #[Test]
    public function itAppliesVoErrorViaDedicatedCliFlag(): void
    {
        $resolved = $this->resolvedCliOptions(['--long-parameter-list-vo-error' => '3', '--rule-opt' => ['code-smell.long-parameter-list:vo-warning=2']]);

        /** @var LongParameterListOptions $options */
        $options = $resolved->for('code-smell.long-parameter-list');

        self::assertSame(3, $options->voError);
    }

    /** The file door prints the folded unknown key and the writable spellings. */
    #[Test]
    public function itRefusesAGenuinelyUnknownOption(): void
    {
        $this->factory->inputs(['rules' => [
            'code-smell.long-parameter-list' => ['not_a_real_option' => 3],
        ]]);

        try {
            $this->factory->create('code-smell.long-parameter-list', LongParameterListOptions::class);
            self::fail('An unknown rule option must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame(
                'Unknown key "rules.code-smell.long-parameter-list.not_a_real_option" in configuration file "/project/qmx.yaml". Accepted keys: error, vo-error, vo-warning, warning, enabled, suppress-namespace-channels, suppress-namespaces, suppress-paths, threshold, vo-threshold.',
                $refusal->getMessage(),
            );
            self::assertStringContainsString(
                'Accepted keys: error, vo-error, vo-warning, warning, enabled, suppress-namespace-channels,'
                . ' suppress-namespaces, suppress-paths, threshold, vo-threshold.',
                $refusal->getMessage(),
                'The printed set must name every key the user may write here, in the kebab spelling they type',
            );
        }
    }

    // -- the three type-coverage rules: bare warning / error ------------------

    #[Test]
    public function itAppliesErrorViaDedicatedCliFlag(): void
    {
        $resolved = $this->resolvedCliOptions(['--param-type-coverage-error' => '90.0', '--rule-opt' => ['design.type-coverage.param:warning=95']]);

        /** @var TypeCoverageOptions $options */
        $options = $resolved->for('design.type-coverage.param');

        self::assertSame(90.0, $options->error);
    }

    /**
     * One rule's option must not reach its siblings: they share the Options
     * class, and configuration is keyed by producer, not by class.
     */
    #[Test]
    public function itAppliesErrorViaRuleOptToOneDimensionOnly(): void
    {
        $resolved = $this->resolvedCliOptions(['--rule-opt' => ['design.type-coverage.return:warning=95', 'design.type-coverage.return:error=85']]);

        /** @var TypeCoverageOptions $configured */
        $configured = $resolved->for('design.type-coverage.return');
        /** @var TypeCoverageOptions $untouched */
        $untouched = $resolved->for('design.type-coverage.param');

        self::assertSame(85.0, $configured->error);
        self::assertSame(50.0, $untouched->error);
    }

    /** @param array<string, string|list<string>> $inputOptions */
    private function resolvedCliOptions(array $inputOptions): ResolvedRuleOptions
    {
        $definition = new InputDefinition([
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('long-parameter-list-vo-error', null, InputOption::VALUE_REQUIRED),
            new InputOption('param-type-coverage-error', null, InputOption::VALUE_REQUIRED),
        ]);
        $writes = (new CliOptionsParser(new RuleOptionDocumentForms(), $this->ruleOptionsParser))->pathWrites(new ArrayInput($inputOptions, $definition));
        $metadata = [
            new RuleMetadata('code-smell.long-parameter-list', LongParameterListOptions::class, '', [], false),
            new RuleMetadata('design.type-coverage.param', TypeCoverageOptions::class, '', [], false),
            new RuleMetadata('design.type-coverage.return', TypeCoverageOptions::class, '', [], false),
        ];

        return ResolvedOptionsFixture::build(FindingConfiguration::none(), $metadata, $writes);
    }
}

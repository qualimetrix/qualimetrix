<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Console;

use AiMessDetector\Configuration\AnalysisConfiguration;
use AiMessDetector\Configuration\RuleOptionsParser;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Parses CLI options from Symfony Console InputInterface.
 */
final readonly class CliOptionsParser
{
    public function __construct(
        private RuleOptionsParser $ruleOptionsParser,
    ) {}

    /**
     * Parse CLI input into AnalysisConfiguration overrides.
     *
     * @return array<string, mixed>
     */
    public function parseConfigOverrides(InputInterface $input): array
    {
        $overrides = [];

        // Cache options
        if ($input->getOption('no-cache')) {
            $overrides['cache']['enabled'] = false;
        }

        $cacheDir = $input->getOption('cache-dir');
        if ($cacheDir !== null && $cacheDir !== AnalysisConfiguration::DEFAULT_CACHE_DIR) {
            $overrides['cache']['dir'] = $cacheDir;
        }

        // Format option
        $format = $input->getOption('format');
        if ($format !== null && $format !== AnalysisConfiguration::DEFAULT_FORMAT) {
            $overrides['format'] = $format;
        }

        // Rule filtering
        $disableRule = $input->getOption('disable-rule');
        if ($disableRule !== null && $disableRule !== []) {
            $overrides['disabled_rules'] = $disableRule;
        }

        $onlyRule = $input->getOption('only-rule');
        if ($onlyRule !== null && $onlyRule !== []) {
            $overrides['only_rules'] = $onlyRule;
        }

        return $overrides;
    }

    /**
     * Parse CLI input into rule options.
     *
     * @return array<string, array<string, mixed>>
     */
    public function parseRuleOptions(InputInterface $input): array
    {
        $ruleOptions = [];

        // Parse generic --rule-opt options
        $genericOptions = $input->getOption('rule-opt');
        if (\is_array($genericOptions)) {
            /** @var list<string> $genericOptions */
            $ruleOptions = $this->ruleOptionsParser->parseRuleOptions($genericOptions);
        }

        // Parse short aliases
        $shortAliases = ['cyclomatic-warning', 'cyclomatic-error', 'class-count-warning', 'class-count-error'];
        foreach ($shortAliases as $alias) {
            $value = $input->getOption($alias);
            if ($value === null) {
                continue;
            }

            $parsed = $this->ruleOptionsParser->parseShortAlias($alias, (int) $value);
            if ($parsed === null) {
                continue;
            }

            $ruleName = $parsed['rule'];
            $optionName = $parsed['option'];

            if (!isset($ruleOptions[$ruleName])) {
                $ruleOptions[$ruleName] = [];
            }

            // Short aliases have lower priority than --rule-opt
            if (!isset($ruleOptions[$ruleName][$optionName])) {
                $ruleOptions[$ruleName][$optionName] = $parsed['value'];
            }
        }

        return $ruleOptions;
    }
}

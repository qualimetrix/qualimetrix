<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Finding\Contract\Rule\FrameworkOptionKeys;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What `bin/qmx rules` looks like.
 *
 * @phpstan-type RuleRow array{name: string, group: string, description: string, options: list<string>, optionsAtLevel: array<string, list<string>>, aliases: array<string, string>, judged: array<string, non-empty-list<string>>}
 *
 * Separate from the command for the reason the command's own metrics named:
 * deciding which producers are listed and saying what a producer looks like are
 * two subjects, and one class holding both shared no state between the halves.
 * It sits beside `DirectiveAuditPresenter` and `ResultPresenter`, which are the
 * same shape for their own commands.
 *
 * Every option a rule accepts is printed, whether or not a CLI alias reaches
 * it. The listing is where `check --help` sends a reader for "all available
 * rules and their options", so an option named only in a refusal was named
 * nowhere a reader looks.
 */
final readonly class RuleListingPresenter
{
    /**
     * @param list<RuleRow> $rules
     */
    public function present(OutputInterface $output, array $rules): void
    {
        if ($rules === []) {
            $output->writeln('<comment>No rules found</comment>');

            return;
        }

        $output->writeln(\sprintf('<info>%d rules available</info>', \count($rules)));
        $output->writeln('');

        $currentGroup = '';

        foreach ($rules as $rule) {
            if ($rule['group'] !== $currentGroup) {
                $currentGroup = $rule['group'];
                $output->writeln(\sprintf('<comment>%s</comment>', ucfirst($currentGroup)));
            }

            $this->writeRule($output, $rule);
        }

        $output->writeln('');
        $output->writeln(\sprintf(
            '<info>Every rule also takes:</info> %s',
            implode(', ', FrameworkOptionKeys::all()),
        ));
        $output->writeln('');
        $output->writeln('<info>Usage:</info> bin/qmx check --disable-rule=<name> | --only-rule=<name>');
        $output->writeln('        bin/qmx check --rule-opt=<name>:<option>=<value>');
    }

    /**
     * One producer's block, in the order a reader meets it: what the rule is,
     * what it judges, what it accepts at each depth, and the flags that reach
     * some of those options.
     *
     * @param RuleRow $rule
     */
    private function writeRule(OutputInterface $output, array $rule): void
    {
        $output->writeln(\sprintf('  %-40s %s', $rule['name'], $rule['description']));

        $this->writeJudgedMetrics($output, $rule['judged']);
        $this->writeAcceptedOptions($output, $rule['options'], $rule['optionsAtLevel']);
        $this->writeAliases($output, $rule['name'], $rule['aliases']);
    }

    /**
     * The catalog metrics each of this producer's channels judges. A rule that
     * reports a number of its own making — a cycle's member count, a count of
     * matched criteria — judges no metric and prints no line.
     *
     * @param array<string, non-empty-list<string>> $judged
     */
    private function writeJudgedMetrics(OutputInterface $output, array $judged): void
    {
        foreach ($judged as $channelCode => $metricKeys) {
            $output->writeln(\sprintf(
                '    <comment>%s</comment> judges %s',
                $channelCode,
                implode(', ', $metricKeys),
            ));
        }
    }

    /**
     * What may be written under this rule, at its own depth and inside each
     * level slot.
     *
     * A rule with no substantive option of its own prints no `options:` line. A
     * slot always prints its line, even were it ever to accept nothing: the
     * slot name is itself the information that this rule may be addressed one
     * level down, and silence there hides the depth rather than tidying it.
     *
     * @param list<string> $options
     * @param array<string, list<string>> $optionsAtLevel
     */
    private function writeAcceptedOptions(OutputInterface $output, array $options, array $optionsAtLevel): void
    {
        if ($options !== []) {
            $output->writeln(\sprintf('    options: %s', implode(', ', $options)));
        }

        foreach ($optionsAtLevel as $level => $keys) {
            $output->writeln(\sprintf('    options at %s: %s', $level, implode(', ', $keys)));
        }
    }

    /**
     * The flags that reach some of those options, each with the long
     * `--rule-opt` form it expands to.
     *
     * @param array<string, string> $aliases CLI alias => option target, canonical spelling
     */
    private function writeAliases(OutputInterface $output, string $ruleName, array $aliases): void
    {
        foreach ($aliases as $alias => $optionName) {
            $output->writeln(\sprintf(
                '    <info>--%s</info> <comment>(--rule-opt=%s:%s=...)</comment>',
                $alias,
                $ruleName,
                $optionName,
            ));
        }
    }
}

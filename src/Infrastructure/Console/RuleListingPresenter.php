<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Finding\Contract\Rule\FrameworkOptionKeys;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What `bin/qmx rules` looks like.
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
     * @param list<array{name: string, group: string, description: string, options: list<string>, optionsAtLevel: array<string, list<string>>, aliases: array<string, string>, judged: array<string, non-empty-list<string>>}> $rules
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
     * One producer's block: what it judges, what it accepts at each depth, and
     * the flags that reach some of those options.
     *
     * @param array{name: string, group: string, description: string, options: list<string>, optionsAtLevel: array<string, list<string>>, aliases: array<string, string>, judged: array<string, non-empty-list<string>>} $rule
     */
    private function writeRule(OutputInterface $output, array $rule): void
    {
        $output->writeln(\sprintf('  %-40s %s', $rule['name'], $rule['description']));

        foreach ($rule['judged'] as $channelCode => $metricKeys) {
            $output->writeln(\sprintf(
                '    <comment>%s</comment> judges %s',
                $channelCode,
                implode(', ', $metricKeys),
            ));
        }

        if ($rule['options'] !== []) {
            $output->writeln(\sprintf('    options: %s', implode(', ', $rule['options'])));
        }

        // A slot always gets its line, even were it ever to accept nothing:
        // the slot name is itself the information that this rule may be
        // addressed one level down, and silence there hides the depth rather
        // than tidying the listing.
        foreach ($rule['optionsAtLevel'] as $level => $keys) {
            $output->writeln(\sprintf('    options at %s: %s', $level, implode(', ', $keys)));
        }

        foreach ($rule['aliases'] as $alias => $optionName) {
            $output->writeln(\sprintf(
                '    <info>--%s</info> <comment>(--rule-opt=%s:%s=...)</comment>',
                $alias,
                $rule['name'],
                $optionName,
            ));
        }
    }
}

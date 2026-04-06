<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists all available rules with their options and CLI aliases.
 */
#[AsCommand(
    name: 'rules',
    description: 'List all available analysis rules',
)]
final class RulesCommand extends Command
{
    public function __construct(
        private readonly RuleRegistryInterface $ruleRegistry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'group',
            'g',
            InputOption::VALUE_REQUIRED,
            'Filter by rule group (e.g., complexity, coupling, code-smell)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string|null $groupFilter */
        $groupFilter = $input->getOption('group');

        $rules = [];
        foreach ($this->ruleRegistry->getAll() as $rule) {
            $name = $rule->getName();
            $group = $rule->getCategory()->value;

            if ($groupFilter !== null && $group !== $groupFilter) {
                continue;
            }

            $aliases = $rule::getCliAliases();

            $rules[] = [
                'name' => $name,
                'group' => $group,
                'description' => $rule->getDescription(),
                'aliases' => $aliases,
            ];
        }

        usort($rules, static fn(array $a, array $b): int => ($a['group'] <=> $b['group']) !== 0 ? ($a['group'] <=> $b['group']) : ($a['name'] <=> $b['name']));

        if ($rules === []) {
            $output->writeln($groupFilter !== null
                ? \sprintf('<comment>No rules found in group "%s"</comment>', $groupFilter)
                : '<comment>No rules found</comment>');

            return self::SUCCESS;
        }

        $output->writeln(\sprintf('<info>%d rules available</info>', \count($rules)));
        $output->writeln('');

        $currentGroup = '';

        foreach ($rules as $rule) {
            if ($rule['group'] !== $currentGroup) {
                $currentGroup = $rule['group'];
                $output->writeln(\sprintf('<comment>%s</comment>', ucfirst($currentGroup)));
            }

            $output->writeln(\sprintf('  %-40s %s', $rule['name'], $rule['description']));

            if ($rule['aliases'] !== []) {
                foreach ($rule['aliases'] as $alias => $optionName) {
                    $output->writeln(\sprintf(
                        '    <info>--%s</info> %s',
                        $alias,
                        \sprintf('<comment>(--rule-opt=%s:%s=...)</comment>', $rule['name'], $optionName),
                    ));
                }
            }
        }

        $output->writeln('');
        $output->writeln('<info>Usage:</info> bin/qmx check --disable-rule=<name> | --only-rule=<name>');
        $output->writeln('        bin/qmx check --rule-opt=<name>:<option>=<value>');

        return self::SUCCESS;
    }
}

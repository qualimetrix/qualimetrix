<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleChannelRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists all available rules with their options and CLI aliases.
 *
 * Rules arrive as container-built instances (injected by
 * {@see \Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleCompilerPass}),
 * never as hand-constructed objects: a rule may declare constructor
 * dependencies beyond its Options object that only the container can resolve.
 */
#[AsCommand(
    name: 'rules',
    description: 'List all available analysis rules',
)]
final class RulesCommand extends Command
{
    public function __construct(
        private readonly RuleExecutionInterface $ruleExecution,
        private readonly RuleChannelRegistryInterface $channels,
        private readonly ChannelDeclarationRegistryInterface $declarations,
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

        if ($groupFilter !== null && !\in_array($groupFilter, $this->families(), true)) {
            $output->writeln(\sprintf(
                '<error>No rule group "%s". Groups: %s</error>',
                $groupFilter,
                implode(', ', $this->families()),
            ));

            return self::FAILURE;
        }

        $rules = $this->rulesIn($groupFilter);

        if ($rules === []) {
            $output->writeln('<comment>No rules found</comment>');

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

            foreach ($rule['judged'] as $channelCode => $metricKeys) {
                $output->writeln(\sprintf(
                    '    <comment>%s</comment> judges %s',
                    $channelCode,
                    implode(', ', $metricKeys),
                ));
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

        $output->writeln('');
        $output->writeln('<info>Usage:</info> bin/qmx check --disable-rule=<name> | --only-rule=<name>');
        $output->writeln('        bin/qmx check --rule-opt=<name>:<option>=<value>');

        return self::SUCCESS;
    }

    /**
     * The families the listing prints a heading for, sorted, so `--group` is
     * judged against the same set the reader sees.
     *
     * @return list<string>
     */
    private function families(): array
    {
        $families = [];

        foreach ($this->ruleExecution->allRules() as $rule) {
            $families[$rule->family] = true;
        }

        $sorted = array_keys($families);
        sort($sorted);

        return $sorted;
    }

    /**
     * @return list<array{name: string, group: string, description: string, aliases: array<string, string>, judged: array<string, non-empty-list<string>>}>
     */
    private function rulesIn(?string $groupFilter): array
    {
        $rules = [];

        foreach ($this->ruleExecution->allRules() as $rule) {
            if ($groupFilter !== null && $rule->family !== $groupFilter) {
                continue;
            }

            // The catalog metrics each of this producer's channels judges,
            // keyed by channel code — read per channel rather than per rule
            // because that is the pair the declaration makes: a rule may
            // publish several channels and only some of them read their
            // number out of the metric catalog. The keys keep the author's
            // declared order, which is the order the producing rule's own body
            // considers them in.
            $judged = [];
            foreach ($this->channels->channelsProducedBy($rule->name) as $channel) {
                $judges = $this->declarations->declarationFor($channel)?->judges;

                if ($judges !== null) {
                    $judged[$channel->code] = $judges->keys;
                }
            }

            $rules[] = [
                'name' => $rule->name,
                'group' => $rule->family,
                'description' => $rule->description,
                'aliases' => $rule->aliases,
                'judged' => $judged,
            ];
        }

        usort(
            $rules,
            static fn(array $a, array $b): int => [$a['group'], $a['name']] <=> [$b['group'], $b['name']],
        );

        return $rules;
    }
}

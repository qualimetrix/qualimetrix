<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\FrameworkOptionKeys;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleChannelRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionSurface;
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
            // With no machine format or catch ladder of its own, the carrier
            // is left to fly past this
            // command into the first clause of `Application`'s ladder, which
            // gives it exit code 3.
            throw ConfigurationRefusal::aboutCommandLineInput(
                '--group',
                \sprintf(
                    'No rule group "%s". Groups: %s',
                    $groupFilter,
                    implode(', ', $this->families()),
                ),
            );
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

            if ($rule['options'] !== []) {
                $output->writeln(\sprintf('    options: %s', implode(', ', $rule['options'])));
            }

            // A slot always gets its line, even were it ever to accept nothing:
            // the slot name is itself the information that this rule may be
            // addressed one level down, and silence there hides the depth
            // rather than tidying the listing.
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

        $output->writeln('');
        $output->writeln(\sprintf(
            '<info>Every rule also takes:</info> %s',
            implode(', ', FrameworkOptionKeys::all()),
        ));
        $output->writeln('');
        $output->writeln('<info>Usage:</info> bin/qmx check --disable-rule=<name> | --only-rule=<name>');
        $output->writeln('        bin/qmx check --rule-opt=<name>:<option>=<value>');

        return self::SUCCESS;
    }

    /**
     * The alias targets restated in the spelling the declarations use.
     *
     * A target is authored by hand in a CLI attribute, so it arrives in kebab,
     * snake or camel depending on who wrote it — 34 of the 80 were not kebab —
     * while every refusal about those keys prints canonical kebab. Printing
     * both spellings of one key is the same defect this listing exists to end,
     * one column over.
     *
     * A target nothing accepts keeps the spelling its author gave it: the
     * listing is not the place to discover that, and inventing a canonical form
     * for a key no declaration has would be a guess printed as a fact.
     *
     * @param array<string, string> $aliases CLI alias => target as authored
     *
     * @return array<string, string> CLI alias => target in canonical spelling
     */
    private static function aliasesInCanonicalSpelling(array $aliases, RuleOptionSurface $surface): array
    {
        $canonical = [];

        foreach ($aliases as $alias => $target) {
            $address = $surface->locate($target);

            $canonical[$alias] = $address === null
                ? $target
                : ($address->level === null ? $address->key : $address->level . '.' . $address->key);
        }

        return $canonical;
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
     * @return list<array{name: string, group: string, description: string, options: list<string>, optionsAtLevel: array<string, list<string>>, aliases: array<string, string>, judged: array<string, non-empty-list<string>>}>
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

            // What the rule actually accepts, asked of the one place that
            // answers it — the same declaration the refusal reads. Advertising
            // only the options that happen to carry a CLI alias hid 54 of 132.
            $surface = RuleOptionSurface::of($rule->optionsClass);

            $optionsAtLevel = [];
            foreach ($surface->levels() as $level) {
                $optionsAtLevel[$level] = $surface->writableAt($level);
            }

            $rules[] = [
                'name' => $rule->name,
                'group' => $rule->family,
                'description' => $rule->description,
                // The slot names and the framework keys are both writable here
                // and both are printed elsewhere — one per level line, the
                // three in the footer — so this line carries what is left.
                'options' => array_values(array_diff(
                    $surface->writableAt(null),
                    $surface->levels(),
                    FrameworkOptionKeys::all(),
                )),
                'optionsAtLevel' => $optionsAtLevel,
                'aliases' => self::aliasesInCanonicalSpelling($rule->aliases, $surface),
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

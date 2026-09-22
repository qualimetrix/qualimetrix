<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\FrameworkOptionKeys;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleChannelRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionSurface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Core\ProductIdentity;
use Qualimetrix\Infrastructure\Console\RuleListingPresenter;
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
        private readonly RuleListingPresenter $presenter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'group',
                'g',
                InputOption::VALUE_REQUIRED,
                'Filter by rule group (e.g., complexity, coupling, code-smell)',
            )
            ->setHelp(\sprintf('Docs: %s', ProductIdentity::llmsTxtUrl()));
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

        $this->presenter->present($output, $this->rulesIn($groupFilter));

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
                // Restated in the spelling the declarations use: an alias
                // target is authored by hand in an attribute, and 34 of the 80
                // were snake or camel while every refusal about those keys
                // printed canonical kebab. A target nothing accepts keeps the
                // spelling its author gave it — inventing a canonical form for
                // a key no declaration has would be a guess printed as a fact.
                'aliases' => array_map(
                    static fn(string $target): string => $surface->locate($target)?->written() ?? $target,
                    $rule->aliases,
                ),
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

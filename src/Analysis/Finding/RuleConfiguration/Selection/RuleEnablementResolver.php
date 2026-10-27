<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration\Selection;

use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedDocument;
use Qualimetrix\Analysis\Finding\Contract\Channel\ChannelPresentationInterface;
use Qualimetrix\Analysis\Finding\Contract\Channel\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\EnablementDecision;
use Qualimetrix\Analysis\Finding\Contract\Filter\ChannelFileScope;
use Qualimetrix\Analysis\Finding\Contract\ResolvedRuleOptions;
use Qualimetrix\Analysis\Finding\Contract\RuleEnablement;
use Qualimetrix\Analysis\Finding\Contract\Selection\AuthoredCellDecision;
use Qualimetrix\Analysis\Finding\Contract\Selection\CellAdmission;
use Qualimetrix\Analysis\Finding\Contract\Selection\CellSwitch;
use Qualimetrix\Analysis\Finding\Contract\Selection\SelectionCellAddress;
use Qualimetrix\Analysis\Finding\Contract\Selection\StatedEnablement;
use Qualimetrix\Analysis\Finding\Selection\AuthoredSelection;
use Qualimetrix\Analysis\Finding\Selection\SelectionRefusals;
use Qualimetrix\Analysis\Finding\Selection\SelectionSpecificity;

/** Resolves authored cell statements, then adds final option activity. */
final readonly class RuleEnablementResolver
{
    public function __construct(
        private ChannelFileScope $fileScope,
        private ChannelPresentationInterface $presentation,
    ) {}

    public function decide(ResolvedDocument $document, ChannelUniverseInterface $channels): StatedEnablement
    {
        $diagnostics = AuthoredSelection::diagnostics($document, $channels, $this->projectOnlyProducerDocsPages($channels));
        $statements = AuthoredSelection::statements($document);
        $filter = AuthoredSelection::filter($document);
        $cells = [];
        foreach (SelectionSpecificity::addresses($channels) as $address) {
            $applicable = SelectionSpecificity::applicableStatements($address, $statements);
            SelectionRefusals::contradictions($applicable, $address->producer);
            $cells[] = SelectionSpecificity::decision($address, $applicable, $filter);
        }
        return new StatedEnablement($cells, $filter, $diagnostics);
    }

    /**
     * Per run, not per instance: the channel universe differs between runs.
     *
     * @return array<string, ?string> producer whose every channel is project-scoped => its docs page
     */
    private function projectOnlyProducerDocsPages(ChannelUniverseInterface $channels): array
    {
        $pages = [];
        foreach ($channels->ruleNames() as $producer) {
            $produced = $channels->channelsProducedBy($producer);
            if ($produced === [] || array_any($produced, $this->fileScope->isFileScoped(...))) {
                continue;
            }
            $pages[$producer] = null;
            foreach ($produced as $channel) {
                $page = $this->presentation->presentationFor($channel->code)?->docsPage;
                if ($page !== null) {
                    $pages[$producer] = $page;
                    break;
                }
            }
        }
        return $pages;
    }

    public function conclude(StatedEnablement $stated, ResolvedRuleOptions $options): RuleEnablement
    {
        $cells = [];
        foreach ($stated->decisions() as $decision) {
            $cells[] = new EnablementDecision(
                new SelectionCellAddress($decision->producer, $decision->channel, $decision->level, $decision->role),
                new AuthoredCellDecision(
                    $decision->on ? CellSwitch::On : CellSwitch::Off,
                    $decision->direct ? CellAdmission::Direct : CellAdmission::Filtered,
                    $decision->decisiveStatements,
                ),
                $options->activityOf($decision->producer, $decision->level),
            );
        }
        $final = new RuleEnablement($cells, $stated->filter());
        SelectionRefusals::conclude($final, $options);
        return $final;
    }

}

<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\SuppressionBinding;

use Closure;
use Qualimetrix\Analysis\Finding\Contract\Channel\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Channel\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;
use Qualimetrix\Analysis\Finding\Contract\Population\JudgedPopulation;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
use Qualimetrix\Analysis\Finding\Population\PopulationSession;
use Qualimetrix\Core\SourceText\SourceBytes;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * The judged population of one {@see UnboundSuppressionAudit::auditResult()}
 * call: every published configured value, each identified by its occurrence
 * and gated on whether the run could judge it.
 *
 * @phpstan-import-type ConfiguredValue from ConfiguredValueJudge
 */
final readonly class UnboundSuppressionPopulation
{
    private PopulationSession $session;

    /**
     * @param Closure $publishes the publication's `publishes()`
     * @param array<string, ChannelDeclaration> $declarations
     */
    public function __construct(Closure $publishes, private array $declarations)
    {
        $this->session = new PopulationSession($publishes);
    }

    /**
     * @param ConfiguredValue $value
     * @param list<GateInput> $inputs one per declared gate of the value's channel, in order
     */
    public function record(array $value, int $ordinal, array $inputs): void
    {
        $channel = new FindingChannel($value['channel']);
        $identity = PopulationIdentity::occurrence(json_encode([$value['channel'], $value['rule'], $value['option'], SourceBytes::framed($value['pattern']->definition->display())], \JSON_THROW_ON_ERROR), $ordinal, 'configured-suppression-value-occurrence');
        $declaration = $this->declarations[$channel->code];
        $failed = $declaration->populationFailure($channel, SymbolLevel::Project, $identity, $inputs);
        $this->session->record(UnboundSuppressionRule::NAME, $channel, SymbolLevel::Project, $identity, $declaration, $failed['gate'] ?? null, $failed['reason'] ?? null, $failed['dataGap'] ?? true);
    }

    public function freeze(): JudgedPopulation
    {
        return $this->session->freeze();
    }
}

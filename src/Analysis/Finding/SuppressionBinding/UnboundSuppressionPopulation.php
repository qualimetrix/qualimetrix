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
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\SourceText\SourceBytes;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * The judged population of one {@see UnboundSuppressionAudit::auditResult()}
 * call: every published configured value, each identified by its occurrence
 * and gated on whether the run could judge it.
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
     * @param array{channel: string, rule: ?string, option: string, pattern: PathPattern|NamespacePattern} $value
     * @param GateInput $input whether the run could judge the value
     */
    public function record(array $value, int $ordinal, GateInput $input): void
    {
        $channel = new FindingChannel($value['channel']);
        $identity = PopulationIdentity::occurrence(json_encode([$value['channel'], $value['rule'], $value['option'], SourceBytes::framed($value['pattern']->definition->display())], \JSON_THROW_ON_ERROR), $ordinal, 'configured-suppression-value-occurrence');
        $declaration = $this->declarations[$channel->code];
        $inputs = $channel->code === UnboundSuppressionOptions::UNMATCHED_RULE_LEDGER ? [$input, $input] : [$input];
        $failed = $declaration->populationFailure($channel, SymbolLevel::Project, $identity, $inputs);
        $this->session->record(UnboundSuppressionRule::NAME, $channel, SymbolLevel::Project, $identity, $declaration, $failed['gate'] ?? null, $failed['reason'] ?? null, $failed['dataGap'] ?? true);
    }

    public function freeze(): JudgedPopulation
    {
        return $this->session->freeze();
    }
}

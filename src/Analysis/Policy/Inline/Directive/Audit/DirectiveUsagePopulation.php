<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive\Audit;

use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelPublication;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Population\ContextGuard;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;
use Qualimetrix\Analysis\Finding\Contract\Population\JudgedPopulation;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationGate;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveVerdict;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\InlineDirectivePolicyInterface;
use Qualimetrix\Core\SourceText\SourceBytes;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * Selected judgement of the native authored suppression-site verdicts.
 *
 * @qmx-threshold coupling.instability warning=0.85 -- The captured directive-site projection joins verdict and publication contracts for its owning audit.
 */
final class DirectiveUsagePopulation
{
    public static function declaration(): ChannelDeclaration
    {
        $name = InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME;
        return ChannelDeclaration::occurrence(SymbolLevel::File)->readingRunEvidence()
            ->describedAs('Reports a valid inline directive that suppressed or overrode nothing in this run.')
            ->withGates(new PopulationGate('directive-scope', new FindingChannel($name), SymbolLevel::File, 'directive-site', new ContextGuard('directiveScopeMeasured'), 'The addressed directive population was not measured.'));
    }

    public static function selected(ChannelPublication $publication): bool
    {
        return $publication->publishes(InlineDirectivePolicyInterface::PRODUCER_RULE_NAME, new FindingChannel(InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME), SymbolLevel::File);
    }

    /** @param iterable<DirectiveVerdict> $verdicts */
    public static function measure(ChannelPublication $publication, iterable $verdicts): JudgedPopulation
    {
        $members = (static function () use ($verdicts): iterable {
            foreach ($verdicts as $verdict) {
                $site = $verdict->site;
                yield [
                    'identity' => PopulationIdentity::selector(json_encode([SourceBytes::framed($site->file->value()), $site->line, $site->position, SourceBytes::framed($site->form), SourceBytes::framed($site->target)], \JSON_THROW_ON_ERROR), 'directive-site'),
                    'inputs' => [GateInput::context('directiveScopeMeasured', $verdict->reason === null)],
                ];
                unset($site, $verdict);
            }
        })();
        return $publication->measure(InlineDirectivePolicyInterface::PRODUCER_RULE_NAME, new FindingChannel(InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME), SymbolLevel::File, self::declaration(), $members);
    }
}

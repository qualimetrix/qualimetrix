<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelPresentation;
use Qualimetrix\Analysis\Finding\Contract\ChannelPresentationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;

/**
 * Composes {@see ChannelPresentation} from the two facts this capability
 * owns — the producing rule ({@see ChannelIdentityInterface::producerOf()})
 * and that rule's own {@see RuleMetadata} (description, and, via
 * `$docsPageByRule`, its declared documentation page) — see
 * {@see ChannelPresentationInterface} for why the join lives here rather than
 * on {@see \Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface} or on the
 * Reporting-side consumer.
 *
 * The third fact the full join needs — a configured `computed.*` /
 * `health.*` channel's own description, preferred over the producer's
 * generic one — is **not** read here: `ComputedMetricDefinition` is owned by
 * `Analysis\Evidence\ComputedMetrics`, which itself depends on this
 * capability for `RuleInterface`, so importing it here would close a cycle
 * (`analysis-finding -> analysis-evidence-computedmetrics -> analysis-finding`,
 * caught by `composer architecture:check`). That override is layered on top
 * by {@see \Qualimetrix\Infrastructure\Rule\ComputedMetricChannelPresentation}, an
 * Infrastructure-owned decorator — Infrastructure already depends on both
 * capabilities.
 *
 * `$docsPageByRule` is handed in rather than read by this class: a rule's
 * `DOCS_PAGE` constant is only safe to read by reflection on the class-string,
 * never by instantiating (mirrors {@see \Qualimetrix\Analysis\Finding\Contract\Rule\RuleNameReader}'s
 * reasoning), so {@see \Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ChannelDeclarationCompilerPass}
 * builds the map at the same pass that already walks every tagged rule
 * service for its name, and injects it here as a constructor argument.
 */
final class ChannelPresentationView implements ChannelPresentationInterface
{
    /** @var array<string, string>|null rule name => description, populated on first use */
    private ?array $descriptionByRule = null;

    /**
     * @param array<string, string> $docsPageByRule every registered rule name => its declared
     *                                              documentation page, relative to `website/docs/`
     */
    public function __construct(
        private readonly ChannelIdentityInterface $identity,
        private readonly RuleExecutionInterface $ruleExecution,
        private readonly array $docsPageByRule,
    ) {}

    public function presentationFor(string $violationCode): ?ChannelPresentation
    {
        $producerRuleName = $this->identity->producerOf($violationCode);

        if ($producerRuleName === null) {
            return null;
        }

        $docsPage = $this->docsPageByRule[$producerRuleName]
            ?? throw new LogicException(\sprintf(
                'Rule "%s" produces channel "%s" but declares no DOCS_PAGE in the map'
                . ' ChannelDeclarationCompilerPass builds — the rule registry and the compiler'
                . ' pass have drifted apart.',
                $producerRuleName,
                $violationCode,
            ));

        $description = $this->descriptionsByRule()[$producerRuleName] ?? null;

        if ($description === null || $description === '') {
            return null;
        }

        return new ChannelPresentation($description, $docsPage);
    }

    /** @return array<string, string> rule name => description */
    private function descriptionsByRule(): array
    {
        if ($this->descriptionByRule !== null) {
            return $this->descriptionByRule;
        }

        $descriptions = [];
        foreach ($this->ruleExecution->allRules() as $rule) {
            \assert($rule instanceof RuleMetadata);
            $descriptions[$rule->name] = $rule->description;
        }

        return $this->descriptionByRule = $descriptions;
    }
}

<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\UnassignedClass;

use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Population\ContextGuard;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationGate;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitectureChannels;
use Qualimetrix\Analysis\Policy\Architecture\Observation\LayerEvidenceCollector;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * How much of the analysed code no declared layer claims.
 *
 * A rule of its own rather than a second channel on
 * {@see \Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule}, because the two answer different questions about
 * different subjects: a forbidden edge is a fact about one dependency, this is
 * a fact about the run. They were one rule only because they read one walk,
 * and they still do — {@see LayerEvidenceCollector} is shared, so splitting
 * the rule did not split the traversal.
 *
 * Off by default and turned on deliberately: see
 * {@see UnassignedClassOptions::$mode}. Its findings are ordinary debt a
 * project rolling a layer policy out can accept in the ratchet and pay down,
 * which is why this is a rule rather than one of the configuration-error
 * diagnostics {@see \Qualimetrix\Analysis\Policy\Architecture\LayerDeclaration\LayerDeclarationValidator} owns.
 *
 * **Statelessness:** nothing this rule computes survives an `analyze()` call.
 * The shared per-run structure lives in the collector, keyed weakly by the
 * run's own {@see AnalysisContext}.
 */
#[CliAlias('unassigned-class-mode', 'mode')]
final class UnassignedClassRule extends AbstractRule
{
    public const string NAME = ArchitectureChannels::UNASSIGNED_CLASS_DIAGNOSTIC_NAME;

    /**
     * How much of the analysed code is unclaimed is a real measured count,
     * unlike the sibling rule's occurrence-shaped forbidden edges.
     */
    public const ChannelShape SHAPE = ChannelShape::Magnitude;

    /**
     * The collector is injected by {@see \Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleOptionsCompilerPass::resolveExtraDependencies()}.
     * Rules cannot use plain constructor autowiring (Critical Rule 7) so the
     * compiler-pass injection is the supported flow.
     */
    public function __construct(
        RuleOptionsInterface $options,
        private readonly LayerEvidenceCollector $evidence,
    ) {
        parent::__construct($options);
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public static function getDescription(): string
    {
        return 'Counts analysed class-like declarations that no declared layer claims.';
    }

    /**
     * @return class-string<UnassignedClassOptions>
     */
    public static function getOptionsClass(): string
    {
        return UnassignedClassOptions::class;
    }

    /**
     * The shape is a judgement call rather than a consequence, and the
     * argument for it lives with the code that emits it, in
     * {@see UnassignedClassSummary::unassignedClassChannel()}.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            self::NAME => UnassignedClassSummary::unassignedClassChannel()->withGates(
                new PopulationGate('prepared-evidence', new FindingChannel(self::NAME), SymbolLevel::Project, 'logical-class', new ContextGuard('preparedEvidenceAvailable'), 'Prepared layer evidence is unavailable.', 'invocation'),
            ),
        ];
    }

    /**
     * @return list<Finding>
     */
    public function analyze(AnalysisContext $context): array
    {
        \assert($this->options instanceof UnassignedClassOptions);

        // Own gate, stated rather than delegated to the summary below: the
        // shared walk runs for either producer, so "may this rule report" is
        // a question only this rule's `mode` answers.
        if (!$this->options->isEnabled()) {
            return [];
        }

        $evidence = $this->evidence->collect($context);

        $declaration = self::channelDeclarations()[self::NAME];
        $channel = new FindingChannel(self::NAME);
        if ($evidence === null) {
            $context->admit(self::NAME, $channel, SymbolLevel::Project, PopulationIdentity::invocation(self::NAME), $declaration, [GateInput::context('preparedEvidenceAvailable', false)]);
            return [];
        }
        foreach ($context->metrics->allLogicalClasses() as $class) {
            $context->admit(self::NAME, $channel, SymbolLevel::Project, PopulationIdentity::selector($class->symbolPath->toCanonical(), 'logical-class'), $declaration, [GateInput::context('preparedEvidenceAvailable', true)]);
        }

        return UnassignedClassSummary::unassignedClasses(
            $this->options->mode,
            $evidence->uncoveredClasses(),
            $evidence->analysedDeclarations(),
        );
    }

    public const string DOCS_PAGE = 'rules/architecture.md';

    public const int REMEDIATION_MINUTES = 15;
}

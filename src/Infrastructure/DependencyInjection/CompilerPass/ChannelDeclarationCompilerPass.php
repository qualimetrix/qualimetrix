<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\CompilerPass;

use LogicException;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Finding\ComputedMetricChannelFamily;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\RemediationTimeRegistry;
use Qualimetrix\Analysis\Finding\ChannelPresentationView;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelDeclarationReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDocsPageReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleNameReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleRemediationMinutesReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleShapeReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdOverrideSupportReader;
use Qualimetrix\Infrastructure\Rule\ChannelUniverse;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Assembles the static half of the channel universe from every tagged rule
 * service and injects it into {@see ChannelUniverse}. Also builds the rule
 * name => documentation page map and injects it into
 * {@see ChannelPresentationView}, the composing service that joins a
 * channel's producer to that producer's own description and declared page,
 * and the rule name => remediation minutes map into
 * {@see RemediationTimeRegistry}, which no longer keeps that fact itself.
 *
 * Six facts are read off each rule class, none of which instantiates it: its
 * name ({@see RuleNameReader}), the channels it declares
 * ({@see ChannelDeclarationReader}), whether it declares support for
 * `@qmx-threshold` ({@see ThresholdOverrideSupportReader}), its declared
 * documentation page ({@see RuleDocsPageReader}), its declared remediation
 * estimate ({@see RuleRemediationMinutesReader}), and its declared
 * {@see ChannelShape} ({@see RuleShapeReader}).
 *
 * `$minutesByRule` additionally inherits an entry for every channel's own
 * `ruleName` half, not
 * only the producer's — the architecture and annotation diagnostics are
 * emitted under their own identity (`architecture.coverage`,
 * `annotation.unused-directive`, …), distinct from the rule class's own
 * `NAME`, and there is no separate class for
 * {@see RuleRemediationMinutesReader} to read a constant off. Mirrors
 * {@see RuleRegistryCompilerPass}, which walks the same `qmx.rule`-tagged
 * services and likewise hands the container a finished map.
 *
 * A rule that declares no channels still contributes its name and its
 * threshold-override answer: names exist independently of channels, and
 * `computed.health` — whose channels are entirely run-time — would otherwise
 * be absent from the universe as a rule.
 *
 * Each rule's `channelDeclarations()` already returns full channel keys
 * (`ruleName#violationCode`, per {@see ChannelDeclarationReader}), so this pass
 * does no pairing of its own. It enforces two integrity properties instead:
 * no key declared twice, and no finding code declared by two different
 * producers. The second is what makes the reverse lookup
 * ({@see \Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface::producerOf()})
 * a function at all — without it, a "did you mean" answer would depend on
 * service iteration order.
 *
 * **Two more properties guard {@see ChannelShape} (ADR 0031), which moved off
 * {@see ChannelDeclaration} onto the producer.** First: a producer's declared
 * shape must agree with whether its own channels carry a
 * {@see \Qualimetrix\Core\Observation\WorseDirection} — `magnitude` channels
 * have one, `occurrence` channels do not, and a producer that declares one
 * shape while building the other kind of channel is refused. Second: a
 * validator borrows its producer rule's name (see
 * {@see collectValidatorChannels()}), so two distinct classes — the rule and
 * its validator — answer `shape()` under one producer name, and a
 * disagreement between them is refused before either class's channels are
 * even inspected. Both checks read `$shapeByRule`, built alongside
 * `$minutesByRule` in the same first pass over every rule.
 *
 * The capability-owned channel family contract supplies the computed-metric
 * producer name as a plain string constructor argument. The pass never imports
 * the internal rule, and `Infrastructure\Rule` remains independent of
 * capability internals.
 *
 * **Accepted, not fixed: this file's own `RuleShapeReader::read()` call
 * (ADR 0031) pushed `ns:Infrastructure\DependencyInjection\CompilerPass`'s
 * instability from under warning to Ca=3, Ce=28 (I=0.903226), recorded in
 * `qmx-baseline.json`.** Namespace-scoped channels have no `@qmx-threshold`
 * anchor — {@see \Qualimetrix\Analysis\Finding\Contract\Control\ControlScope}
 * has no `Namespace_` case — so a directive cannot state this the way
 * {@see \Qualimetrix\Analysis\Evidence\CodeSmell\AbstractCodeSmellRule}'s
 * `coupling.cbo` override states its own class-scoped cost. The read itself
 * is not misplaced: it is one more call in the same first pass over every
 * rule that already calls {@see RuleNameReader}, {@see RuleDocsPageReader},
 * {@see RuleRemediationMinutesReader} and {@see ThresholdOverrideSupportReader}
 * for the same reason (a fact every rule must answer, gathered before any
 * validator needs it), and the cross-producer shape check
 * ({@see collectValidatorChannels()}) can only run where both a rule's and
 * its validator's declarations are both in hand — registry assembly, not a
 * single class's own reader. What would actually undo this entry is a step
 * that collapses those five per-rule reader calls into one aggregate
 * "rule metadata" read, cutting this file's own efferent count rather than
 * moving the read elsewhere; no such step is planned.
 */
final class ChannelDeclarationCompilerPass implements CompilerPassInterface
{
    private const string RULE_INTERFACE = 'Qualimetrix\\Analysis\\Finding\\Rule\\RuleInterface';

    private const string VALIDATOR_INTERFACE = 'Qualimetrix\\Analysis\\Finding\\Contract\\ConfigurationValidatorInterface';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ChannelUniverse::class)) {
            return;
        }

        $ruleClasses = $this->taggedClasses($container, RuleRegistryCompilerPass::TAG, self::RULE_INTERFACE);
        $validatorClasses = $this->taggedClasses($container, ConfigurationValidatorCompilerPass::TAG, self::VALIDATOR_INTERFACE);

        /** @var array<string, bool> $thresholdOverrideSupport */
        $thresholdOverrideSupport = [];
        /** @var array<string, string> $docsPageByRule */
        $docsPageByRule = [];
        /** @var array<string, int> $minutesByRule */
        $minutesByRule = [];
        /** @var array<string, ChannelShape> $shapeByRule */
        $shapeByRule = [];

        foreach ($ruleClasses as $class) {
            $producerRuleName = RuleNameReader::read($class);
            $thresholdOverrideSupport[$producerRuleName] = ThresholdOverrideSupportReader::read($class);
            $docsPageByRule[$producerRuleName] = RuleDocsPageReader::read($class);
            $minutesByRule[$producerRuleName] = RuleRemediationMinutesReader::read($class);
            $shapeByRule[$producerRuleName] = RuleShapeReader::read($class);
        }

        /** @var array<string, ChannelDeclaration> $declarations */
        $declarations = [];
        /** @var array<string, list<string>> $channelKeysByProducer */
        $channelKeysByProducer = [];
        /** @var array<string, string> $producerByCode */
        $producerByCode = [];

        foreach ($container->getDefinitions() as $id => $definition) {
            if (isset($ruleClasses[$id])) {
                $this->collectRuleChannels(
                    $ruleClasses[$id],
                    $declarations,
                    $channelKeysByProducer,
                    $producerByCode,
                    $minutesByRule,
                    $shapeByRule,
                );

                continue;
            }

            if (isset($validatorClasses[$id])) {
                $this->collectValidatorChannels(
                    $validatorClasses[$id],
                    $declarations,
                    $channelKeysByProducer,
                    $producerByCode,
                    $minutesByRule,
                    $shapeByRule,
                );
            }
        }

        $container->getDefinition(ChannelUniverse::class)
            ->setArgument('$staticDeclarations', $declarations)
            ->setArgument('$staticChannelKeysByProducer', $channelKeysByProducer)
            ->setArgument('$thresholdOverrideSupportByRule', $thresholdOverrideSupport)
            ->setArgument('$computedMetricRuleName', ComputedMetricChannelFamily::PRODUCER_RULE_NAME);

        if ($container->hasDefinition(ChannelPresentationView::class)) {
            $container->getDefinition(ChannelPresentationView::class)
                ->setArgument('$docsPageByRule', $docsPageByRule);
        }

        if ($container->hasDefinition(RemediationTimeRegistry::class)) {
            $container->getDefinition(RemediationTimeRegistry::class)
                ->setArgument('$minutesByRule', $minutesByRule);
        }
    }

    /**
     * The class behind every service carrying `$tag`, in container definition
     * order, checked against `$interface`.
     *
     * A tagged service whose definition names no class is refused rather than
     * skipped: every other integrity failure in this pass throws, and a
     * producer silently absent from the universe declares nothing, runs never
     * and says so nowhere.
     *
     * @return array<string, class-string> service id => its class
     */
    private function taggedClasses(ContainerBuilder $container, string $tag, string $interface): array
    {
        $classes = [];

        foreach (array_keys($container->findTaggedServiceIds($tag)) as $id) {
            $class = $container->getDefinition($id)->getClass();

            if ($class === null) {
                throw new LogicException(\sprintf(
                    'Service "%s" is tagged "%s" but its definition names no class. A producer is read by'
                    . ' reflection off its class; without one it would contribute nothing to the channel'
                    . ' universe and nothing would report the loss.',
                    $id,
                    $tag,
                ));
            }

            if (!is_a($class, $interface, true)) {
                throw new LogicException(\sprintf(
                    'Service "%s" is tagged "%s" but its class %s does not implement %s.',
                    $id,
                    $tag,
                    $class,
                    $interface,
                ));
            }

            /** @var class-string $class */
            $classes[$id] = $class;
        }

        return $classes;
    }

    /**
     * @param class-string $class
     * @param array<string, ChannelDeclaration> $declarations
     * @param array<string, list<string>> $channelKeysByProducer
     * @param array<string, string> $producerByCode
     * @param array<string, int> $minutesByRule
     * @param array<string, ChannelShape> $shapeByRule
     */
    private function collectRuleChannels(
        string $class,
        array &$declarations,
        array &$channelKeysByProducer,
        array &$producerByCode,
        array &$minutesByRule,
        array $shapeByRule,
    ): void {
        $producerRuleName = RuleNameReader::read($class);

        foreach (ChannelDeclarationReader::read($class) as $key => $declaration) {
            if (isset($declarations[$key])) {
                throw new LogicException(\sprintf(
                    'Duplicate channel declaration for "%s" — declared by more than one rule class (last seen: %s).',
                    $key,
                    $class,
                ));
            }

            $this->assertShapeAgreesWithDirection($key, $class, $producerRuleName, $shapeByRule[$producerRuleName], $declaration);

            $channel = FindingChannel::fromKey($key);
            $code = $channel->code;

            // A channel's own ruleName half does not always equal its producer's
            // NAME: the architecture and annotation diagnostics are emitted under
            // their own identity (e.g. "architecture.coverage") by a producer whose
            // own name is different ("architecture.layer-violation"). Every such
            // identity a Finding can carry must resolve to a remediation
            // estimate, so it inherits the declaring rule's minutes here rather
            // than needing its own constant on a class that does not exist.
            $minutesByRule[$channel->ruleName] ??= $minutesByRule[$producerRuleName];

            if (isset($producerByCode[$code])) {
                throw new LogicException(\sprintf(
                    'Violation code "%s" is declared by two producers ("%s" and "%s"). A code names exactly one'
                    . ' channel, so that a diagnostic can answer which rule produces it.',
                    $code,
                    $producerByCode[$code],
                    $producerRuleName,
                ));
            }

            $producerByCode[$code] = $producerRuleName;
            $declarations[$key] = $declaration;
            $channelKeysByProducer[$producerRuleName][] = $key;
        }
    }

    /**
     * The second producer kind. Its channels are collected in the same ordered
     * walk as the rules', so that the position of a channel in
     * {@see \Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface::channels()}
     * is a function of the order the container was told about the producers —
     * not of which producer *kind* it is. That order is published: a "did you
     * mean" answer breaks ties between equidistant names by it, so a producer
     * kind sorted as a block would move the text of a finding.
     *
     * **This is the single point where a channel becomes a configuration
     * error.** The classification is not authored anywhere: a rule has no
     * factory that can express it, and this method applies
     * {@see ChannelDeclaration::asConfigurationError()} to everything a
     * {@see \Qualimetrix\Analysis\Finding\Contract\ConfigurationValidatorInterface}
     * declares and to nothing else. A topology test counts the call sites.
     *
     * The channels are registered under the *producer rule's* name, not under
     * some identity of the validator's own, because that name is what
     * `--disable-rule`, `only_rules`, `exclude_paths`, the channel
     * description, the documentation page and the remediation estimate all
     * resolve through today. The producer's own metadata is read in a first
     * pass over every rule, so a validator may be registered before the rule
     * whose name it borrows.
     *
     * @param class-string $class
     * @param array<string, ChannelDeclaration> $declarations
     * @param array<string, list<string>> $channelKeysByProducer
     * @param array<string, string> $producerByCode
     * @param array<string, int> $minutesByRule
     * @param array<string, ChannelShape> $shapeByRule
     */
    private function collectValidatorChannels(
        string $class,
        array &$declarations,
        array &$channelKeysByProducer,
        array &$producerByCode,
        array &$minutesByRule,
        array $shapeByRule,
    ): void {
        /** @var class-string<\Qualimetrix\Analysis\Finding\Contract\ConfigurationValidatorInterface> $class */
        $producerRuleName = $class::producerRuleName();

        if (!isset($minutesByRule[$producerRuleName])) {
            throw new LogicException(\sprintf(
                'Configuration validator %s names producer "%s", which is not a registered rule. A validator'
                . ' borrows its producer\'s name for selection, exclusion, options and presentation, so the'
                . ' name must exist.',
                $class,
                $producerRuleName,
            ));
        }

        $shape = $class::shape();

        if ($shape !== $shapeByRule[$producerRuleName]) {
            throw new LogicException(\sprintf(
                'Configuration validator %s declares shape "%s" for producer "%s", but the rule declares "%s".'
                . ' A validator and the rule it belongs to are one producer and must agree on what their'
                . ' findings mean for baseline purposes.',
                $class,
                $shape->value,
                $producerRuleName,
                $shapeByRule[$producerRuleName]->value,
            ));
        }

        $validatorDeclarations = $class::channelDeclarations();

        if ($validatorDeclarations === []) {
            throw new LogicException(\sprintf(
                'Configuration validator %s declares no channels. A validator exists to own diagnostics; one'
                . ' with none is a producer nothing can address.',
                $class,
            ));
        }

        foreach ($validatorDeclarations as $key => $declaration) {
            $this->assertUnclaimed($key, $class, $declarations, $producerByCode, $producerRuleName);
            $this->assertShapeAgreesWithDirection($key, $class, $producerRuleName, $shape, $declaration);

            $channel = FindingChannel::fromKey($key);
            $minutesByRule[$channel->ruleName] ??= $minutesByRule[$producerRuleName];
            $producerByCode[$channel->code] = $producerRuleName;
            $declarations[$key] = $declaration->asConfigurationError();
            $channelKeysByProducer[$producerRuleName][] = $key;
        }
    }

    /**
     * The per-channel half of the shape guarantee: a `magnitude` producer's
     * channel must carry a direction, an `occurrence` producer's channel must
     * not. Shared by both producer kinds so the message and the rule are one
     * thing, checked once.
     *
     * @param class-string $class
     */
    private function assertShapeAgreesWithDirection(
        string $key,
        string $class,
        string $producerRuleName,
        ChannelShape $declaredShape,
        ChannelDeclaration $declaration,
    ): void {
        $channelIsMagnitude = $declaration->direction !== null;
        $shapeIsMagnitude = $declaredShape === ChannelShape::Magnitude;

        if ($channelIsMagnitude !== $shapeIsMagnitude) {
            throw new LogicException(\sprintf(
                'Channel "%s" declared by %s carries %s, but producer "%s" declares shape "%s". A magnitude'
                . ' producer\'s channels must all carry a WorseDirection, and an occurrence producer\'s must'
                . ' carry none.',
                $key,
                $class,
                $channelIsMagnitude ? 'a direction' : 'no direction',
                $producerRuleName,
                $declaredShape->value,
            ));
        }
    }

    /**
     * @param array<string, ChannelDeclaration> $declarations
     * @param array<string, string> $producerByCode
     */
    private function assertUnclaimed(
        string $key,
        string $class,
        array $declarations,
        array $producerByCode,
        string $producerRuleName,
    ): void {
        if (isset($declarations[$key])) {
            throw new LogicException(\sprintf(
                'Duplicate channel declaration for "%s" — declared by more than one producer (last seen: %s).',
                $key,
                $class,
            ));
        }

        $code = FindingChannel::fromKey($key)->code;

        if (isset($producerByCode[$code])) {
            throw new LogicException(\sprintf(
                'Violation code "%s" is declared by two producers ("%s" and "%s"). A code names exactly one'
                . ' channel, so that a diagnostic can answer which rule produces it.',
                $code,
                $producerByCode[$code],
                $producerRuleName,
            ));
        }
    }
}

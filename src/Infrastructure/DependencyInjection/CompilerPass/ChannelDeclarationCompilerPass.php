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
use Qualimetrix\Analysis\Finding\Contract\ProducerDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelDeclarationReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\ProducerName;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDocsPageReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleFamily;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleNameReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleRemediationMinutesReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleShapeReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdOverrideSupportReader;
use Qualimetrix\Infrastructure\Rule\ChannelUniverse;
use Qualimetrix\Infrastructure\Rule\KnownRuleNamesAdapter;
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
 * `$minutesByRule` additionally inherits an entry for every declared channel
 * name, not only for the producers' — the architecture and annotation
 * diagnostics are emitted under their own identity (`architecture.coverage`,
 * `annotation.unused-directive`, …), which is published as the finding's `rule`
 * and is distinct from the rule class's own `NAME`, and there is no separate
 * class for {@see RuleRemediationMinutesReader} to read a constant off. Mirrors
 * {@see RuleRegistryCompilerPass}, which walks the same `qmx.rule`-tagged
 * services and likewise hands the container a finished map.
 *
 * A rule that declares no channels still contributes its name and its
 * threshold-override answer: names exist independently of channels, and the
 * computed-metric family — whose channels are entirely run-time — would
 * otherwise be absent from the universe as a producer.
 *
 * A second pass adds the producers that have no class at all
 * ({@see collectClasslessProducers()}). It fills the same four maps from
 * {@see ComputedMetricChannelFamily} and hands
 * the finding executor their declarations, so
 * that "every registered rule" means the same set of names wherever it is
 * asked.
 *
 * Each rule's `channelDeclarations()` already returns full channel keys
 * (the channel's own name, per {@see ChannelDeclarationReader}), so this pass
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
 * **A third pair guards the judged metric a channel declares (ADR 0046).**
 * A key a channel names must exist in the metric catalog, and only a
 * `magnitude` producer may name one at all — see
 * {@see JudgedMetricDeclarationGuard::assertDeclarable()}, which also names the
 * six channels the check says nothing about. It lives beside this pass for the
 * same reason the shape checks do: {@see ChannelDeclaration} would otherwise
 * have to import the metric catalog across a capability boundary to assert it.
 *
 * The capability-owned channel family contract supplies the names and the
 * facts of the classless producers. The pass never imports the internal rule
 * or its Options class — the latter is read off the one producer of the family
 * that does have a class — and `Infrastructure\Rule` remains independent of
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

    /** Named by literal, not imported: it is a Finding internal, as in {@see RuleCompilerPass}. */
    private const string RULE_EXECUTION = 'Qualimetrix\\Analysis\\Finding\\RuleExecution';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ChannelUniverse::class)) {
            return;
        }

        $ruleClasses = $this->taggedClasses($container, RuleRegistryCompilerPass::TAG, self::RULE_INTERFACE);
        $validatorClasses = $this->taggedClasses($container, ConfigurationValidatorCompilerPass::TAG, self::VALIDATOR_INTERFACE);

        [$thresholdOverrideSupport, $docsPageByRule, $minutesByRule, $shapeByRule] = $this->readProducerFacts($ruleClasses);

        $classlessProducers = $this->collectClasslessProducers(
            $ruleClasses,
            $thresholdOverrideSupport,
            $docsPageByRule,
            $minutesByRule,
            $shapeByRule,
        );

        self::refuseMalformedProducers(array_keys($thresholdOverrideSupport));

        /** @var array<string, ChannelDeclaration> $declarations */
        $declarations = [];
        /** @var array<string, list<string>> $channelKeysByProducer */
        $channelKeysByProducer = [];

        $this->collectChannels(
            $container,
            $ruleClasses,
            $validatorClasses,
            $declarations,
            $channelKeysByProducer,
            $minutesByRule,
            $shapeByRule,
        );

        $container->getDefinition(ChannelUniverse::class)
            ->setArgument('$staticDeclarations', $declarations)
            ->setArgument('$staticChannelKeysByProducer', $channelKeysByProducer)
            ->setArgument('$thresholdOverrideSupportByRule', $thresholdOverrideSupport);

        if ($container->hasDefinition(KnownRuleNamesAdapter::class)) {
            $container->getDefinition(KnownRuleNamesAdapter::class)
                ->setArgument('$ruleNames', array_keys($thresholdOverrideSupport));
        }

        if ($container->hasDefinition(self::RULE_EXECUTION)) {
            $container->getDefinition(self::RULE_EXECUTION)
                ->setArgument('$classlessProducers', $classlessProducers);
        }

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
     * Refuses, at container build, a producer whose name yields no family.
     *
     * The family is the heading `qmx rules` lists a producer under
     * ({@see RuleFamily}), and since it is derived rather than declared, a
     * name that yields none would otherwise reach the listing as an empty
     * heading — a producer displayed under nothing at all. Checked here
     * because this is the one place both halves of "every registered
     * producer" are in hand: `$thresholdOverrideSupport` is keyed by every
     * rule class's name and by every classless producer's, which is also why
     * {@see KnownRuleNamesAdapter} is handed its keys.
     *
     * **This refuses one shape of bad name, not a name grammar.** Rejected:
     * an empty name and one starting with the separator — exactly the two
     * that do not obey the producer grammar. Until Ш5e3 this refused only a
     * name with no family at all, because `computed.branch_load` was a legal
     * producer a strict pattern would have refused; with every producer name
     * lower-case kebab the whole form is held here. What that closes is
     * measured: `Complexity.Foo` used to register, print under a heading of its
     * own, and then not be found by `--group=complexity` — the filter being
     * case-sensitive — and a trailing separator, a doubled separator and a
     * segment with a space in it did the same.
     *
     * @param list<string> $producerRuleNames
     */
    private static function refuseMalformedProducers(array $producerRuleNames): void
    {
        foreach ($producerRuleNames as $producerRuleName) {
            ProducerName::assertWellFormed($producerRuleName);
        }
    }

    /**
     * The first pass: the four facts every rule class declares about itself,
     * gathered before any validator can borrow its producer's name.
     *
     * @param array<string, class-string> $ruleClasses
     *
     * @return array{array<string, bool>, array<string, string>, array<string, int>, array<string, ChannelShape>}
     */
    private function readProducerFacts(array $ruleClasses): array
    {
        $thresholdOverrideSupport = [];
        $docsPageByRule = [];
        $minutesByRule = [];
        $shapeByRule = [];

        foreach ($ruleClasses as $class) {
            $producerRuleName = RuleNameReader::read($class);
            $thresholdOverrideSupport[$producerRuleName] = ThresholdOverrideSupportReader::read($class);
            $docsPageByRule[$producerRuleName] = RuleDocsPageReader::read($class);
            $minutesByRule[$producerRuleName] = RuleRemediationMinutesReader::read($class);
            $shapeByRule[$producerRuleName] = RuleShapeReader::read($class);
        }

        return [$thresholdOverrideSupport, $docsPageByRule, $minutesByRule, $shapeByRule];
    }

    /**
     * The ordered walk over both producer kinds, in container definition order
     * — see {@see collectValidatorChannels()} for why the order is published
     * behaviour rather than an implementation detail.
     *
     * @param array<string, class-string> $ruleClasses
     * @param array<string, class-string> $validatorClasses
     * @param array<string, ChannelDeclaration> $declarations
     * @param array<string, list<string>> $channelKeysByProducer
     * @param array<string, int> $minutesByRule
     * @param array<string, ChannelShape> $shapeByRule
     */
    private function collectChannels(
        ContainerBuilder $container,
        array $ruleClasses,
        array $validatorClasses,
        array &$declarations,
        array &$channelKeysByProducer,
        array &$minutesByRule,
        array $shapeByRule,
    ): void {
        /** @var array<string, string> $producerByCode */
        $producerByCode = [];

        foreach (array_keys($container->getDefinitions()) as $id) {
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
    }

    /**
     * The producers the computed-metric capability owns without a rule class,
     * and the four class-keyed maps they have to appear in.
     *
     * Missing any one of the four is a different kind of wrong, and only the
     * first is invisible: `$thresholdOverrideSupport`'s keys **are** the set of
     * addressable rule names ({@see \Qualimetrix\Infrastructure\Rule\ChannelUniverse::ruleNames()}),
     * so a producer absent from it is absent from selection, from option-owner
     * validation and from `@qmx-threshold` diagnostics while every other map
     * looks complete. The other three end a run: an unmapped producer throws
     * out of {@see \Qualimetrix\Analysis\Finding\ChannelPresentationView::presentationFor()}
     * and {@see RemediationTimeRegistry::getBaseMinutes()}, and an unmapped
     * shape out of this pass's own validator check.
     *
     * The seven names, and each fact about them, come from
     * {@see ComputedMetricChannelFamily} — the capability declares them, this
     * pass only reads them, exactly as it reads a rule class's constants.
     *
     * @param array<string, class-string> $ruleClasses
     * @param array<string, bool> $thresholdOverrideSupport
     * @param array<string, string> $docsPageByRule
     * @param array<string, int> $minutesByRule
     * @param array<string, ChannelShape> $shapeByRule
     *
     * @return list<ProducerDeclaration>
     */
    private function collectClasslessProducers(
        array $ruleClasses,
        array &$thresholdOverrideSupport,
        array &$docsPageByRule,
        array &$minutesByRule,
        array &$shapeByRule,
    ): array {
        $optionsClass = $this->openProducerOptionsClass($ruleClasses);
        $declarations = [];

        foreach (ComputedMetricChannelFamily::HEALTH_PRODUCER_RULE_NAMES as $producerRuleName) {
            if (isset($thresholdOverrideSupport[$producerRuleName])) {
                throw new LogicException(\sprintf(
                    'Computed-metric producer "%s" is also a registered rule class. Exactly one name of the'
                    . ' family, "%s", may be a class; a name that is both would be read twice with no rule'
                    . ' about which reading wins.',
                    $producerRuleName,
                    ComputedMetricChannelFamily::OPEN_PRODUCER_RULE_NAME,
                ));
            }

            if ($optionsClass === null) {
                // The family's one class is not in this container — a fixture
                // container in a unit test. Its classless producers exist only
                // alongside it, so there is nothing to declare and nothing to
                // silently lose.
                continue;
            }

            $thresholdOverrideSupport[$producerRuleName] = ComputedMetricChannelFamily::SUPPORTS_THRESHOLD_OVERRIDE;
            $docsPageByRule[$producerRuleName] = ComputedMetricChannelFamily::DOCS_PAGE;
            $minutesByRule[$producerRuleName] = ComputedMetricChannelFamily::REMEDIATION_MINUTES;
            $shapeByRule[$producerRuleName] = ComputedMetricChannelFamily::SHAPE;

            $declarations[] = new ProducerDeclaration(
                name: $producerRuleName,
                hostRuleName: ComputedMetricChannelFamily::OPEN_PRODUCER_RULE_NAME,
                optionsClass: $optionsClass,
                description: ComputedMetricChannelFamily::descriptionOf($producerRuleName),
                aliases: ComputedMetricChannelFamily::CLI_ALIASES,
            );
        }

        return $declarations;
    }

    /**
     * The Options class the whole family is configured through, asked of the
     * one producer that has a class.
     *
     * Not spelled here: the six classless producers share the open producer's
     * options by construction (one class runs all seven), so reading it off
     * that class keeps a single authority and adds no import of a capability
     * internal to this pass.
     *
     * @param array<string, class-string> $ruleClasses
     *
     * @return class-string<RuleOptionsInterface>|null null when the family is not registered at all
     */
    private function openProducerOptionsClass(array $ruleClasses): ?string
    {
        foreach ($ruleClasses as $class) {
            if (RuleNameReader::read($class) !== ComputedMetricChannelFamily::OPEN_PRODUCER_RULE_NAME) {
                continue;
            }

            /** @var class-string<RuleDefinitionInterface> $class */
            $optionsClass = $class::getOptionsClass();

            /** @var class-string<RuleOptionsInterface> $optionsClass */
            return $optionsClass;
        }

        return null;
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

            JudgedMetricDeclarationGuard::assertDeclarable($key, $class, $producerRuleName, $shapeByRule[$producerRuleName], $declaration);
            $this->assertShapeAgreesWithDirection($key, $class, $producerRuleName, $shapeByRule[$producerRuleName], $declaration);

            $channel = new FindingChannel($key);
            $code = $channel->code;

            // A finding's published `rule` does not always equal its producer's
            // NAME: the architecture and annotation diagnostics are emitted under
            // their own identity (e.g. "architecture.coverage") by a producer
            // whose own name is different ("architecture.layer-violation"), and
            // that identity is the channel's name. Every name a Finding can carry
            // as its `rule` must resolve to a remediation estimate, so the
            // channel name inherits the declaring rule's minutes here rather than
            // needing its own constant on a class that does not exist.
            $minutesByRule[$code] ??= $minutesByRule[$producerRuleName];

            if (isset($producerByCode[$code])) {
                throw new LogicException(\sprintf(
                    'Channel "%s" is declared by two producers ("%s" and "%s"). A channel name is its identity,'
                    . ' so that a diagnostic can answer which rule produces it.',
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
     * `--disable-rule`, `only_rules`, `suppress_paths`, the channel
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
            JudgedMetricDeclarationGuard::assertDeclarable($key, $class, $producerRuleName, $shape, $declaration);
            $this->assertShapeAgreesWithDirection($key, $class, $producerRuleName, $shape, $declaration);

            $channel = new FindingChannel($key);
            $minutesByRule[$channel->code] ??= $minutesByRule[$producerRuleName];
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

        $code = new FindingChannel($key)->code;

        if (isset($producerByCode[$code])) {
            throw new LogicException(\sprintf(
                'Channel "%s" is declared by two producers ("%s" and "%s"). A channel name is its identity,'
                . ' so that a diagnostic can answer which rule produces it.',
                $code,
                $producerByCode[$code],
                $producerRuleName,
            ));
        }
    }
}

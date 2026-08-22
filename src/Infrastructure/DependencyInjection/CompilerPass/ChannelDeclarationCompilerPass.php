<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\CompilerPass;

use LogicException;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Finding\ComputedMetricChannelFamily;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\RemediationTimeRegistry;
use Qualimetrix\Analysis\Finding\ChannelPresentationView;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelDeclarationReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDocsPageReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleNameReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleRemediationMinutesReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdOverrideSupportReader;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
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
 * Five facts are read off each rule class by reflection, none of which
 * instantiates it: its name ({@see RuleNameReader}), the channels it declares
 * ({@see ChannelDeclarationReader}), whether it declares support for
 * `@qmx-threshold` ({@see ThresholdOverrideSupportReader}), its declared
 * documentation page ({@see RuleDocsPageReader}), and its declared
 * remediation estimate ({@see RuleRemediationMinutesReader}). `$minutesByRule`
 * additionally inherits an entry for every channel's own `ruleName` half, not
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
 * no key declared twice, and no violation code declared by two different
 * producers. The second is what makes the reverse lookup
 * ({@see \Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface::producerOf()})
 * a function at all — without it, a "did you mean" answer would depend on
 * service iteration order.
 *
 * The capability-owned channel family contract supplies the computed-metric
 * producer name as a plain string constructor argument. The pass never imports
 * the internal rule, and `Infrastructure\Rule` remains independent of
 * capability internals.
 */
final class ChannelDeclarationCompilerPass implements CompilerPassInterface
{
    private const string RULE_INTERFACE = 'Qualimetrix\\Analysis\\Finding\\Rule\\RuleInterface';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ChannelUniverse::class)) {
            return;
        }

        /** @var array<string, ChannelDeclaration> $declarations */
        $declarations = [];
        /** @var array<string, list<string>> $channelKeysByProducer */
        $channelKeysByProducer = [];
        /** @var array<string, bool> $thresholdOverrideSupport */
        $thresholdOverrideSupport = [];
        /** @var array<string, string> $producerByViolationCode */
        $producerByViolationCode = [];
        /** @var array<string, string> $docsPageByRule */
        $docsPageByRule = [];
        /** @var array<string, int> $minutesByRule */
        $minutesByRule = [];

        foreach ($container->findTaggedServiceIds(RuleRegistryCompilerPass::TAG) as $id => $tags) {
            $class = $container->getDefinition($id)->getClass();

            if ($class === null) {
                continue;
            }

            $this->assertRuleClass($id, $class);

            $producerRuleName = RuleNameReader::read($class);
            $thresholdOverrideSupport[$producerRuleName] = ThresholdOverrideSupportReader::read($class);
            $docsPageByRule[$producerRuleName] = RuleDocsPageReader::read($class);
            $minutesByRule[$producerRuleName] = RuleRemediationMinutesReader::read($class);

            foreach (ChannelDeclarationReader::read($class) as $key => $declaration) {
                if (isset($declarations[$key])) {
                    throw new LogicException(\sprintf(
                        'Duplicate channel declaration for "%s" — declared by more than one rule class (last seen: %s).',
                        $key,
                        $class,
                    ));
                }

                $channel = ViolationChannel::fromKey($key);
                $violationCode = $channel->violationCode;

                // A channel's own ruleName half does not always equal its producer's
                // NAME: the architecture and annotation diagnostics are emitted under
                // their own identity (e.g. "architecture.coverage") by a producer whose
                // own name is different ("architecture.layer-violation"). Every such
                // identity a Violation can carry must resolve to a remediation
                // estimate, so it inherits the declaring rule's minutes here rather
                // than needing its own constant on a class that does not exist.
                $minutesByRule[$channel->ruleName] ??= $minutesByRule[$producerRuleName];

                if (isset($producerByViolationCode[$violationCode])) {
                    throw new LogicException(\sprintf(
                        'Violation code "%s" is declared by two producers ("%s" and "%s"). A code names exactly one'
                        . ' channel, so that a diagnostic can answer which rule produces it.',
                        $violationCode,
                        $producerByViolationCode[$violationCode],
                        $producerRuleName,
                    ));
                }

                $producerByViolationCode[$violationCode] = $producerRuleName;
                $declarations[$key] = $declaration;
                $channelKeysByProducer[$producerRuleName][] = $key;
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
     * Validate the exact rule contract before reading metadata. Unlike
     * RuleOptionsCompilerPass, a failing check here throws rather than skips:
     * every service reaching this loop is already tagged qmx.rule, which
     * autoconfiguration only applies to RuleInterface implementers — a
     * mismatch would mean the tag and the class have drifted apart, and the
     * rule's declarations should not silently vanish from the universe
     * because of it.
     *
     * @phpstan-assert class-string $class
     */
    private function assertRuleClass(string $id, string $class): void
    {
        if (!is_a($class, self::RULE_INTERFACE, true)) {
            throw new LogicException(\sprintf(
                'Service "%s" is tagged "%s" but its class %s does not implement %s.',
                $id,
                RuleRegistryCompilerPass::TAG,
                $class,
                self::RULE_INTERFACE,
            ));
        }
    }
}

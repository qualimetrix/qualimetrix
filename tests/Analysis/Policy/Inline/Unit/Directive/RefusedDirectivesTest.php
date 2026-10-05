<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Unit\Directive;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\LevelActivity;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveEffect;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveSite;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveVerdict;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveVerdictRefusal;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Analysis\Policy\Inline\Contract\Threshold\ThresholdDiagnostic;
use Qualimetrix\Analysis\Policy\Inline\Directive\Audit\DirectiveUsage;
use Qualimetrix\Analysis\Policy\Inline\Directive\DirectiveRefusalChannel;
use Qualimetrix\Analysis\Policy\Inline\Directive\InlineDirectivePolicy;
use Qualimetrix\Analysis\Policy\Inline\Directive\InlineDirectiveValidator;
use Qualimetrix\Analysis\Policy\Inline\Directive\RefusedDirectives;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Rule\ChannelUniverse;

#[CoversClass(RefusedDirectives::class)]
final class RefusedDirectivesTest extends TestCase
{
    #[Test]
    public function itClassifiesUnresolvedUnsupportedAndInvalidDirectivesOnce(): void
    {
        $classifier = self::classifier();
        $file = RelativePath::fromString('src/Example.php');
        $suppression = $classifier->suppression($file, new Suppression('missing.rule', null, 4, SuppressionType::File, position: 28));
        $unknown = $classifier->threshold($file, new ThresholdOverride('missing.rule', 10, 20, 6, self::subject(), ControlScope::Class_));
        $unsupported = $classifier->threshold($file, new ThresholdOverride('known.rule', 10, 20, 8, self::subject(), ControlScope::Class_));
        $diagnostic = $classifier->diagnostic($file, new ThresholdDiagnostic(10, self::subject(), 'known.rule', 'Invalid payload.', 160, hint: 'Fix the payload.'));

        self::assertNotNull($suppression);
        self::assertNotNull($unknown);
        self::assertNotNull($unsupported);
        self::assertSame(DirectiveRefusalChannel::Unresolved, $suppression->channel);
        self::assertSame(DirectiveRefusalChannel::Unresolved, $unknown->channel);
        self::assertNull($suppression->addressedProducer);
        self::assertNull($unknown->addressedProducer);
        self::assertSame(28, $suppression->site->position);
        self::assertNull($unknown->site->position);
        self::assertSame(DirectiveRefusalChannel::Unsupported, $unsupported->channel);
        self::assertSame('known.rule', $unsupported->addressedProducer);
        self::assertNull($unsupported->site->position);
        self::assertSame(DirectiveRefusalChannel::Invalid, $diagnostic->channel);
        self::assertSame('known.rule', $diagnostic->addressedProducer);
        self::assertSame(160, $diagnostic->site->position);
        self::assertSame('Fix the payload.', $diagnostic->hint);
        self::assertStringContainsString('Invalid payload.', $diagnostic->message);
        self::assertNull($classifier->suppression($file, new Suppression('*', null, 12, SuppressionType::File, position: 200)));
    }

    #[Test]
    public function itProjectsTheSameRefusalsIntoFindingsAndVerdicts(): void
    {
        $universe = self::universe();
        $classifier = new RefusedDirectives($universe);
        $policy = new InlineDirectivePolicy(new DirectiveUsage($universe, new RuleOptionsRegistry(), $universe, $classifier), $classifier);
        $policy->prepare(
            ['src/Example.php' => [new Suppression('missing.rule', null, 4, SuppressionType::File, position: 28)]],
            ['src/Example.php' => [new ThresholdOverride('known.rule', 10, 20, 8, self::subject(), ControlScope::Class_)]],
            ['src/Example.php' => [new ThresholdDiagnostic(10, self::subject(), 'known.rule', 'Invalid payload.', 160)]],
        );
        $findings = (new InlineDirectiveValidator($policy, $classifier))->validate(new AnalysisContext(new InMemoryMetricRepository()));
        $verdicts = $policy->directiveVerdicts([], LevelActivity::empty());

        self::assertCount(3, $findings);
        self::assertCount(3, $verdicts);
        foreach ($verdicts as $index => $verdict) {
            self::assertSame(DirectiveEffect::Refused, $verdict->effect);
            self::assertCount(1, $verdict->refusals);
            self::assertNotNull($findings[$index]->location->file);
            self::assertSame($findings[$index]->location->file->value(), $verdict->site->file->value());
            self::assertSame($findings[$index]->location->line, $verdict->site->line);
            self::assertSame($findings[$index]->code, $verdict->refusals[0]->channel->code);
            self::assertSame($findings[$index]->message, $verdict->refusals[0]->message);
            self::assertSame($findings[$index]->addressedProducer, $verdict->refusals[0]->addressedProducer);
        }
        self::assertSame([28, null, 160], array_map(static fn(DirectiveVerdict $verdict): ?int => $verdict->site->position, $verdicts));
    }

    #[Test]
    public function itRequiresRefusalsExactlyForTheRefusedVerdict(): void
    {
        $site = new DirectiveSite(RelativePath::fromString('src/Example.php'), 4, 'file', '', 28);
        $refusal = new DirectiveVerdictRefusal(new FindingChannel('annotation.unresolved-directive'), 'Refused.', null);
        foreach ([[DirectiveEffect::Refused, []], [DirectiveEffect::Inert, [$refusal]]] as [$effect, $refusals]) {
            try {
                new DirectiveVerdict($site, $effect, refusals: $refusals);
                self::fail('The verdict/refusals implication must be enforced.');
            } catch (InvalidArgumentException $error) {
                self::assertSame('Refused directives require refusals, and no other effect may carry them', $error->getMessage());
            }
        }
        self::assertCount(1, (new DirectiveVerdict($site, DirectiveEffect::Refused, refusals: [$refusal]))->refusals);
        self::assertSame([], (new DirectiveVerdict($site, DirectiveEffect::Inert))->refusals);
    }

    private static function classifier(): RefusedDirectives
    {
        return new RefusedDirectives(self::universe());
    }

    private static function universe(): ChannelUniverse
    {
        return new ChannelUniverse(
            ['known.channel' => ChannelDeclaration::occurrence(SymbolLevel::File)],
            ['known.rule' => ['known.channel']],
            ['known.rule' => false],
            new ResolvedComputedMetricDefinitions([]),
            ...self::unusedReachPorts(),
        );
    }

    private static function subject(): MetricSubject
    {
        return MetricSubject::declaration(DeclarationPath::of(
            SymbolPath::forClass('Fixture', 'Example'),
            RelativePath::fromString('src/Example.php'),
            DeclarationOrdinal::fromRank(0),
        ));
    }

    /** @return array{\Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReachCatalogInterface, \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricReachInterface} */
    private static function unusedReachPorts(): array
    {
        return [
            new class implements \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReachCatalogInterface {
                public function metricReach(string $metricKey): \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReach
                {
                    throw new LogicException('This fixture does not query measured-metric reach.');
                }
            },
            new class implements \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricReachInterface {
                public function reachAt(
                    string $metricName,
                    \Qualimetrix\Core\Symbol\SymbolLevel $level,
                    \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface $definitions,
                ): \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReach {
                    throw new LogicException('This fixture does not query computed-metric reach.');
                }
            },
        ];
    }
}

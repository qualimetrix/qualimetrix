<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Cohesion\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Cohesion\Configuration\LcomCollectionConfigurationResolver;
use Qualimetrix\Analysis\Evidence\Cohesion\LcomOptions;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\ResolvedRuleOptions;
use Qualimetrix\Analysis\Finding\Contract\RuleSuppression;
use Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionOptions;

final class LcomCollectionConfigurationResolverTest extends TestCase
{
    #[Test]
    public function itProjectsOnlyTheReadyTypedOptionsPreservingAuthoredMethodSpelling(): void
    {
        $resolver = new LcomCollectionConfigurationResolver();
        $raw = \Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture::authoredConfiguration(
            ['rules' => ['cohesion.lcom' => ['exclude_methods' => ['raw-file']]]],
            [new \Qualimetrix\Analysis\Finding\Contract\RuleMetadata('cohesion.lcom', LcomOptions::class, '', [], false)],
            cliOptions: ['cohesion.lcom' => ['excludeMethods' => ['raw-cli']]],
        );
        self::assertSame(['raw-cli'], $raw->document->get('rules', 'cohesion.lcom', 'exclude-methods')?->plain());
        foreach ([new LcomOptions(excludeMethods: ['BRIDGE', 'bridge']), new LcomOptions()] as $options) {
            $configuration = $raw->withResolvedOptions(new ResolvedRuleOptions(
                ['cohesion.lcom' => $options],
                ['cohesion.lcom' => new RuleSuppression()],
            ));
            self::assertSame($options->excludeMethods ?? [], $resolver->resolve($configuration)->excludedMethods);
        }
    }

    #[Test]
    public function itRefusesToGuessFromRawOptionsWhenTheSnapshotIsMissing(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('LCOM collection configuration requires resolved rule options.');
        (new LcomCollectionConfigurationResolver())->resolve(FindingConfiguration::none());
    }

    #[Test]
    public function itRefusesAnOptionsObjectFromAnotherProducer(): void
    {
        $configuration = FindingConfiguration::none()->withResolvedOptions(new ResolvedRuleOptions(
            ['cohesion.lcom' => new UnboundSuppressionOptions()],
            ['cohesion.lcom' => new RuleSuppression()],
        ));
        self::expectException(LogicException::class);
        self::expectExceptionMessage('The LCOM producer requires LcomOptions.');
        (new LcomCollectionConfigurationResolver())->resolve($configuration);
    }
}

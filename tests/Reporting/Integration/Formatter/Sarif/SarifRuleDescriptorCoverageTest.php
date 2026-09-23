<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Integration\Formatter\Sarif;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ChannelPresentationInterface;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Reporting\Formatter\Sarif\SarifRuleCollector;

/**
 * The repository-topology half of this subject —
 * {@see \Qualimetrix\Governance\Channel\SarifRuleDescriptorCoverageTest} —
 * sweeps every real channel of the universe against its producer's
 * description and documentation anchor. This half stays a product test: the
 * humanised fallback and repository URL for a code the universe does not
 * declare at all.
 */
#[CoversClass(SarifRuleCollector::class)]
final class SarifRuleDescriptorCoverageTest extends TestCase
{
    #[Test]
    public function itKeepsTheHumanisedFallbackAndTheRepositoryUrlForAnUnknownCode(): void
    {
        $container = (new ContainerFactory())->create();
        $presentationView = $container->get(ChannelPresentationInterface::class);
        \assert($presentationView instanceof ChannelPresentationInterface);

        $collector = new SarifRuleCollector($presentationView);
        $rules = $collector->collectRules([self::finding('custom.made-up-rule', 'custom.made-up-rule')]);

        self::assertCount(1, $rules);
        self::assertSame('Custom made up rule', $rules[0]['shortDescription']['text']);
        self::assertSame(SarifRuleCollector::FALLBACK_HELP_URI, $rules[0]['helpUri']);
    }

    private static function finding(string $ruleName, string $code): \Qualimetrix\Analysis\Finding\Contract\Finding
    {
        $symbolPath = SymbolPath::forProject();

        return new \Qualimetrix\Analysis\Finding\Contract\Finding(
            location: Location::none(),
            subject: MetricSubject::aggregate($symbolPath),
            symbolPath: $symbolPath,
            ruleName: $ruleName,
            code: $code,
            message: 'fixture',
            severity: Severity::Warning,
        );
    }
}

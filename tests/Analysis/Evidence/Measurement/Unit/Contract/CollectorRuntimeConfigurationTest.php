<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Measurement\Unit\Contract;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CollectorRuntimeConfigurableInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CollectorRuntimeConfiguration;
use Qualimetrix\Analysis\Evidence\Measurement\Runtime\CollectorRuntimeConfigurationStore;

#[CoversClass(CollectorRuntimeConfiguration::class)]
#[CoversClass(CollectorRuntimeConfigurationStore::class)]
final class CollectorRuntimeConfigurationTest extends TestCase
{
    #[Test]
    public function itNormalizesAnImmutablePayload(): void
    {
        $configuration = CollectorRuntimeConfiguration::fromPayload([
            'lcom_excluded_methods' => ['__construct', '__invoke', '__construct'],
        ]);

        self::assertSame(['__construct', '__invoke'], $configuration->lcomExcludedMethods);
    }

    #[Test]
    public function itUsesExplicitDefaultsWithoutProcessStaticState(): void
    {
        $store = new CollectorRuntimeConfigurationStore();

        self::assertSame([], $store->current()->lcomExcludedMethods);
        self::assertNotSame(CollectorRuntimeConfiguration::empty(), CollectorRuntimeConfiguration::empty());
    }

    #[Test]
    public function itKeepsConfigurationsIsolatedAcrossTwoRuns(): void
    {
        $firstCollector = new class implements CollectorRuntimeConfigurableInterface {
            /** @var list<CollectorRuntimeConfiguration> */
            public array $applied = [];

            public function applyRuntimeConfiguration(CollectorRuntimeConfiguration $configuration): void
            {
                $this->applied[] = $configuration;
            }
        };
        $secondCollector = clone $firstCollector;
        $store = new CollectorRuntimeConfigurationStore([$firstCollector, $secondCollector]);
        $first = CollectorRuntimeConfiguration::fromPayload(['lcom_excluded_methods' => ['first']]);
        $second = CollectorRuntimeConfiguration::fromPayload(['lcom_excluded_methods' => ['second']]);

        $store->replace($first);
        self::assertSame($first, $store->current());
        self::assertSame($first, $firstCollector->applied[0]);
        self::assertSame($first, $secondCollector->applied[0]);

        $store->replace($second);
        self::assertSame($second, $store->current());
        self::assertSame($second, $firstCollector->applied[1]);
        self::assertSame($second, $secondCollector->applied[1]);

        $store->reset();
        self::assertSame([], $store->current()->lcomExcludedMethods);
        self::assertSame($store->current(), $firstCollector->applied[2]);
        self::assertSame($store->current(), $secondCollector->applied[2]);
        self::assertNotSame($second, $firstCollector->applied[2]);
    }

    #[Test]
    public function itRejectsMalformedExcludedMethodsAtConstruction(): void
    {
        $constructors = [
            static fn(): CollectorRuntimeConfiguration => new CollectorRuntimeConfiguration(['ok', 42]),
            static fn(): CollectorRuntimeConfiguration => CollectorRuntimeConfiguration::fromPayload([
                'lcom_excluded_methods' => ['ok', 42],
            ]),
        ];

        foreach ($constructors as $construct) {
            try {
                $construct();
                self::fail('Malformed excluded methods must fail in both main and worker construction.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function itExposesTheCompleteImmutableWorkerPayload(): void
    {
        self::assertSame([
            'lcom_excluded_methods' => ['__construct'],
        ], (new CollectorRuntimeConfiguration(['__construct']))->toPayload());
    }
}

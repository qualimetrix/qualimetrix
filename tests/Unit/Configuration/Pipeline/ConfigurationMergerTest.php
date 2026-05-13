<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\Pipeline\ConfigurationMerger;

#[CoversClass(ConfigurationMerger::class)]
final class ConfigurationMergerTest extends TestCase
{
    #[Test]
    public function scalarOverlayReplacesBase(): void
    {
        $base = ['format' => 'text', 'workers' => 4];
        $overlay = ['format' => 'json'];

        $result = ConfigurationMerger::merge($base, $overlay);

        self::assertSame('json', $result['format']);
        self::assertSame(4, $result['workers']);
    }

    #[Test]
    public function mergeableListKeysAreUnionedAndDeduplicated(): void
    {
        $base = [
            'disabled_rules' => ['complexity.cyclomatic', 'size.loc'],
            'exclude_paths' => ['tests/'],
            'excludes' => ['vendor'],
            'exclude_health' => ['complexity'],
        ];
        $overlay = [
            'disabled_rules' => ['size.loc', 'coupling.cbo'],
            'exclude_paths' => ['tests/', 'generated/'],
            'excludes' => ['vendor', 'node_modules'],
            'exclude_health' => ['complexity', 'coupling'],
        ];

        $result = ConfigurationMerger::merge($base, $overlay);

        self::assertSame(['complexity.cyclomatic', 'size.loc', 'coupling.cbo'], $result['disabled_rules']);
        self::assertSame(['tests/', 'generated/'], $result['exclude_paths']);
        self::assertSame(['vendor', 'node_modules'], $result['excludes']);
        self::assertSame(['complexity', 'coupling'], $result['exclude_health']);
    }

    #[Test]
    public function rulesAreDeepMerged(): void
    {
        $base = [
            'rules' => [
                'complexity.cyclomatic' => ['warning' => 10, 'error' => 20],
                'size.loc' => ['warning' => 200],
            ],
        ];
        $overlay = [
            'rules' => [
                'complexity.cyclomatic' => ['error' => 30],
                'coupling.cbo' => ['warning' => 5],
            ],
        ];

        $result = ConfigurationMerger::merge($base, $overlay);

        // Deep merge: warning preserved from base, error overridden by overlay
        self::assertSame(10, $result['rules']['complexity.cyclomatic']['warning']);
        self::assertSame(30, $result['rules']['complexity.cyclomatic']['error']);
        // Untouched rule preserved
        self::assertSame(['warning' => 200], $result['rules']['size.loc']);
        // New rule added
        self::assertSame(['warning' => 5], $result['rules']['coupling.cbo']);
    }

    #[Test]
    public function rulesListValuesAreReplacedNotMergedByIndex(): void
    {
        $base = [
            'rules' => [
                'coupling.cbo' => ['exclude_namespaces' => ['App\\Tests', 'App\\Fixtures']],
            ],
        ];
        $overlay = [
            'rules' => [
                'coupling.cbo' => ['exclude_namespaces' => ['App\\Generated']],
            ],
        ];

        $result = ConfigurationMerger::merge($base, $overlay);

        // Lists within rules are replaced entirely, not merged
        self::assertSame(['App\\Generated'], $result['rules']['coupling.cbo']['exclude_namespaces']);
    }

    #[Test]
    public function onlyRulesOverlayReplacesBase(): void
    {
        $base = ['only_rules' => ['complexity.cyclomatic', 'size.loc']];
        $overlay = ['only_rules' => ['coupling.cbo']];

        $result = ConfigurationMerger::merge($base, $overlay);

        // only_rules is NOT in MERGEABLE_LIST_KEYS — overlay replaces base
        self::assertSame(['coupling.cbo'], $result['only_rules']);
    }

    #[Test]
    public function emptyBaseReturnsOverlay(): void
    {
        $overlay = [
            'format' => 'json',
            'disabled_rules' => ['size.loc'],
            'rules' => ['complexity.cyclomatic' => ['warning' => 10]],
        ];

        $result = ConfigurationMerger::merge([], $overlay);

        self::assertSame($overlay, $result);
    }

    #[Test]
    public function emptyOverlayReturnsBase(): void
    {
        $base = [
            'format' => 'text',
            'disabled_rules' => ['size.loc'],
            'rules' => ['complexity.cyclomatic' => ['warning' => 10]],
        ];

        $result = ConfigurationMerger::merge($base, []);

        self::assertSame($base, $result);
    }

    #[Test]
    public function mixedMergeStrategiesInSingleCall(): void
    {
        $base = [
            'format' => 'text',
            'workers' => 4,
            'disabled_rules' => ['complexity.cyclomatic'],
            'exclude_paths' => ['tests/'],
            'only_rules' => ['complexity.cyclomatic', 'size.loc'],
            'rules' => [
                'complexity.cyclomatic' => ['warning' => 10, 'error' => 20],
                'size.loc' => ['warning' => 200],
            ],
        ];
        $overlay = [
            'format' => 'json',
            'disabled_rules' => ['coupling.cbo'],
            'exclude_paths' => ['generated/'],
            'only_rules' => ['coupling.cbo'],
            'rules' => [
                'complexity.cyclomatic' => ['error' => 30],
            ],
            'new_key' => 'new_value',
        ];

        $result = ConfigurationMerger::merge($base, $overlay);

        // Scalar override
        self::assertSame('json', $result['format']);
        // Untouched scalar preserved
        self::assertSame(4, $result['workers']);
        // Union for mergeable list keys
        self::assertSame(['complexity.cyclomatic', 'coupling.cbo'], $result['disabled_rules']);
        self::assertSame(['tests/', 'generated/'], $result['exclude_paths']);
        // Override for only_rules (not in MERGEABLE_LIST_KEYS)
        self::assertSame(['coupling.cbo'], $result['only_rules']);
        // Deep merge for rules
        self::assertSame(10, $result['rules']['complexity.cyclomatic']['warning']);
        self::assertSame(30, $result['rules']['complexity.cyclomatic']['error']);
        self::assertSame(['warning' => 200], $result['rules']['size.loc']);
        // New key added
        self::assertSame('new_value', $result['new_key']);
    }

    #[Test]
    public function architectureLayersListIsReplacedWholesaleByOverlay(): void
    {
        // ADR 0006: `architecture.layers` is an ordered list under
        // declaration-order matching. Order is meaningful and must not be
        // silently reshuffled by deep-merge — when overlay defines `layers`,
        // it replaces the base list entirely.
        $base = [
            'architecture' => [
                'layers' => [
                    ['name' => 'a', 'patterns' => ['App\\A']],
                    ['name' => 'b', 'patterns' => ['App\\B']],
                ],
            ],
        ];
        $overlay = [
            'architecture' => [
                'layers' => [
                    ['name' => 'c', 'patterns' => ['App\\C']],
                ],
            ],
        ];

        $result = ConfigurationMerger::merge($base, $overlay);

        // Overlay's layers list wins outright; base's a/b are NOT carried over.
        self::assertCount(1, $result['architecture']['layers']);
        self::assertSame('c', $result['architecture']['layers'][0]['name']);
    }

    #[Test]
    public function architectureLayersListIsPreservedWhenOverlayDoesNotDefineIt(): void
    {
        // Regression: an overlay that only touches `coverage` or `allow` must
        // not clobber the base `layers` list.
        $base = [
            'architecture' => [
                'layers' => [
                    ['name' => 'controller', 'patterns' => ['App\\Controller']],
                ],
            ],
        ];
        $overlay = [
            'architecture' => [
                'coverage' => 'error',
            ],
        ];

        $result = ConfigurationMerger::merge($base, $overlay);

        self::assertCount(1, $result['architecture']['layers']);
        self::assertSame('controller', $result['architecture']['layers'][0]['name']);
        self::assertSame('error', $result['architecture']['coverage']);
    }

    #[Test]
    public function architectureAllowMapsAreDeepMerged(): void
    {
        $base = [
            'architecture' => [
                'allow' => [
                    'controller' => ['service'],
                ],
            ],
        ];
        $overlay = [
            'architecture' => [
                'allow' => [
                    'service' => ['repository'],
                ],
            ],
        ];

        $result = ConfigurationMerger::merge($base, $overlay);

        self::assertSame(['service'], $result['architecture']['allow']['controller']);
        self::assertSame(['repository'], $result['architecture']['allow']['service']);
    }

    #[Test]
    public function architectureCoverageScalarIsOverridden(): void
    {
        $base = [
            'architecture' => [
                'coverage' => 'warn',
            ],
        ];
        $overlay = [
            'architecture' => [
                'coverage' => 'error',
            ],
        ];

        $result = ConfigurationMerger::merge($base, $overlay);

        self::assertSame('error', $result['architecture']['coverage']);
    }

    #[Test]
    public function architectureAllowListEntriesAreReplacedNotMerged(): void
    {
        // Within architecture.allow.<source>, the target list is replaced (matching
        // the rules deep-merge contract for list-valued options).
        $base = [
            'architecture' => [
                'allow' => [
                    'controller' => ['service', 'shared'],
                ],
            ],
        ];
        $overlay = [
            'architecture' => [
                'allow' => [
                    'controller' => ['repository'],
                ],
            ],
        ];

        $result = ConfigurationMerger::merge($base, $overlay);

        self::assertSame(['repository'], $result['architecture']['allow']['controller']);
    }

    #[Test]
    public function architectureKeepsPresetLayersWhenProjectAddsCoverage(): void
    {
        // Regression: project config that only adds `coverage` must not clobber
        // the preset's layers list.
        $preset = [
            'architecture' => [
                'layers' => [
                    ['name' => 'controller', 'patterns' => ['App\\Controller']],
                ],
            ],
        ];
        $project = [
            'architecture' => [
                'coverage' => 'error',
            ],
        ];

        $result = ConfigurationMerger::merge($preset, $project);

        self::assertCount(1, $result['architecture']['layers']);
        self::assertSame('controller', $result['architecture']['layers'][0]['name']);
        self::assertSame('error', $result['architecture']['coverage']);
    }

    #[Test]
    public function rulesDeepMergeHandlesNestedAssociativeArrays(): void
    {
        $base = [
            'rules' => [
                'complexity.cyclomatic' => [
                    'method' => ['warning' => 10, 'error' => 20],
                    'class' => ['max_warning' => 30],
                ],
            ],
        ];
        $overlay = [
            'rules' => [
                'complexity.cyclomatic' => [
                    'method' => ['error' => 25, 'threshold' => 15],
                ],
            ],
        ];

        $result = ConfigurationMerger::merge($base, $overlay);

        // Deeply nested: warning preserved, error overridden, threshold added
        self::assertSame(10, $result['rules']['complexity.cyclomatic']['method']['warning']);
        self::assertSame(25, $result['rules']['complexity.cyclomatic']['method']['error']);
        self::assertSame(15, $result['rules']['complexity.cyclomatic']['method']['threshold']);
        // Untouched nested key preserved
        self::assertSame(['max_warning' => 30], $result['rules']['complexity.cyclomatic']['class']);
    }
}

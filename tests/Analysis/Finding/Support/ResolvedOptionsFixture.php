<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Support;

use Closure;
use LogicException;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\ResolvedRuleOptions;
use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\Rule\RuleInterface;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsBuild;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Finding\RuleExecution;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;

/** Builds test snapshots with the product builder and explicit producer metadata. */
final readonly class ResolvedOptionsFixture
{
    public function __construct(private RuleOptionsRegistry $registry) {}

    /** @param class-string<RuleOptionsInterface> $optionsClass */
    public function create(string $producer, string $optionsClass): RuleOptionsInterface
    {
        $configuration = FindingConfiguration::none()
            ->withRuleOptions($this->registry->configFileOptions())
            ->withCliOverrides($this->registry->cliOptions())
            ->withSelection($this->registry->selection());
        $snapshot = self::build($configuration, [new RuleMetadata($producer, $optionsClass, '', [], false)]);
        $this->registry->replace($configuration->withResolvedOptions($snapshot));
        return $snapshot->for($producer);
    }

    /** @param list<RuleMetadata> $metadata */
    public static function build(FindingConfiguration $configuration, array $metadata): ResolvedRuleOptions
    {
        $lookups = [];
        foreach ($metadata as $producer) {
            $lookups[] = ['metadata' => $producer, 'create' => static fn(): RuleInterface => throw new LogicException('Metadata lookup constructed a rule.')];
        }
        $execution = new RuleExecution($lookups, new class implements ProfilerInterface {
            public function start(string $name, ?string $category = null): void {}
            public function stop(string $name): void {}
        }, new RuleOptionsRegistry());
        return (new RuleOptionsBuild($execution))->build($configuration);
    }

    /** @param array<string, mixed> $rules */
    public static function file(RuleOptionsRegistry $registry, array $rules): void
    {
        self::configure($registry, FindingConfiguration::none()->withRuleOptions($rules)->withCliOverrides($registry->cliOptions())->withSelection($registry->selection()));
    }

    /** @param array<string, mixed> $options */
    public static function cli(RuleOptionsRegistry $registry, string $producer, array $options): void
    {
        $cli = $registry->cliOptions();
        $cli[$producer] = $options;
        self::configure($registry, FindingConfiguration::none()->withRuleOptions($registry->configFileOptions())->withCliOverrides($cli)->withSelection($registry->selection()));
    }

    public static function cliValue(RuleOptionsRegistry $registry, string $producer, string $key, mixed $value): void
    {
        $options = $registry->cliOptions()[$producer] ?? [];
        $options[$key] = $value;
        self::cli($registry, $producer, $options);
    }

    public static function selection(RuleOptionsRegistry $registry, \Qualimetrix\Analysis\Finding\Contract\RuleSelection $selection): void
    {
        self::configure($registry, FindingConfiguration::none()->withRuleOptions($registry->configFileOptions())->withCliOverrides($registry->cliOptions())->withSelection($selection));
    }

    /** Installs raw input for a unit test that subsequently chooses its producer metadata. */
    public static function configure(RuleOptionsRegistry $registry, FindingConfiguration $configuration): void
    {
        $registry->replace($configuration->withResolvedOptions(self::build($configuration, [])));
    }

    /** @return array{metadata: RuleMetadata, create: Closure(): RuleInterface} */
    public static function lookup(RuleInterface $rule): array
    {
        return [
            'metadata' => new RuleMetadata($rule->getName(), $rule::getOptionsClass(), $rule::getDescription(), CliAliasReader::read($rule::class), false),
            'create' => static fn(): RuleInterface => $rule,
        ];
    }
}

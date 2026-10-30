<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\CheckScope;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ConfiguredValueWriters;
use Qualimetrix\Analysis\Policy\Baseline\DocumentAccess\BaselineDocumentReader;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Infrastructure\Console\Invocation\CliSelectorDecoder;
use Qualimetrix\Infrastructure\Console\Invocation\CommandLineSpelling;
use Qualimetrix\Infrastructure\Git\GitScopeResolution;
use Qualimetrix\Reporting\FindingProjection\Contract\ConfiguredFindingExclusions;
use Qualimetrix\Reporting\FindingProjection\Contract\FindingProjectionOptions;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeRequest;
use Symfony\Component\Console\Input\InputInterface;

/** @qmx-threshold coupling.instability 0.800001 -- This subject implements an owner-declared outward protocol with intentionally few incoming readers; the finite boundary preserves visibility of additional dependencies. */
final readonly class FindingProjectionOptionsResolver
{
    public function __construct(private BaselineDocumentReader $documentReader) {}

    public function resolve(
        ConfiguredFindingExclusions $configuredExclusions,
        InputInterface $input,
        GitScopeResolution $scope,
    ): FindingProjectionOptions {
        $cliExcludePaths = CommandLineSpelling::options($input, 'suppress-path');
        $cliExcludeNamespaces = CommandLineSpelling::options($input, 'suppress-namespace');
        $decoder = new CliSelectorDecoder();
        $cliPaths = array_map(fn(string $value) => $decoder->decodePath($value, '--suppress-path'), $cliExcludePaths);
        $cliNamespaces = array_map(fn(string $value) => $decoder->decodeNamespace($value, '--suppress-namespace'), $cliExcludeNamespaces);
        $exclusions = $configuredExclusions->withAdditional(
            $cliPaths,
            $cliNamespaces,
            self::writtenOnCommandLine($cliPaths, '--suppress-path'),
            self::writtenOnCommandLine($cliNamespaces, '--suppress-namespace'),
        );

        $gitScope = null;
        if ($scope->gitClient !== null && $scope->reportScope !== null) {
            $gitScope = new GitScopeRequest(
                reference: $scope->reportScope->ref,
                projectRoot: $scope->projectRoot,
                includeParentNamespaces: !(bool) $input->getOption('report-strict'),
            );
        }

        $baselinePath = CommandLineSpelling::option($input, 'baseline');

        return new FindingProjectionOptions(
            baselineDocument: $baselinePath !== null && $baselinePath !== ''
                ? $this->documentReader->preflight($baselinePath)
                : null,
            suppressPaths: $exclusions->suppressPaths,
            suppressNamespaces: $exclusions->suppressNamespaces,
            annotationSuppressionDisabled: (bool) $input->getOption('no-suppression-annotations'),
            gitScope: $gitScope,
            pathWriters: $exclusions->pathWriters,
            namespaceWriters: $exclusions->namespaceWriters,
        );
    }

    /**
     * @param list<PathPattern|NamespacePattern> $patterns
     *
     * @return array<string, ConfiguredValueWriters>
     */
    private static function writtenOnCommandLine(array $patterns, string $option): array
    {
        $writers = new ConfiguredValueWriters([ConfigurationOrigin::of(ConfigurationSource::CommandLine, $option)]);

        return array_fill_keys(array_map(static fn(PathPattern|NamespacePattern $pattern): string => $pattern->definition->display(), $patterns), $writers);
    }
}

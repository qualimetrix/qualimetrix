<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\CheckScope;

use Qualimetrix\Analysis\Policy\Baseline\DocumentAccess\BaselineDocumentReader;
use Qualimetrix\Infrastructure\Console\Invocation\CliSelectorDecoder;
use Qualimetrix\Infrastructure\Console\Invocation\CommandLineSpelling;
use Qualimetrix\Infrastructure\Git\GitScopeResolution;
use Qualimetrix\Reporting\FindingProjection\Contract\ConfiguredFindingExclusions;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeRequest;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionOptions;
use Symfony\Component\Console\Input\InputInterface;

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
        $exclusions = $configuredExclusions->withAdditional(
            array_map(fn(string $value) => $decoder->decodePath($value, '--suppress-path'), $cliExcludePaths),
            array_map(fn(string $value) => $decoder->decodeNamespace($value, '--suppress-namespace'), $cliExcludeNamespaces),
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
        );
    }
}

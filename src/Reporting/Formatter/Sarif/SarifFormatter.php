<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Sarif;

use Qualimetrix\Core\Version;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Formatter\FormatterInterface;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Report;

/**
 * Formats report as SARIF (Static Analysis Results Interchange Format) JSON.
 *
 * SARIF 2.1.0 spec: https://docs.oasis-open.org/sarif/sarif/v2.1.0/sarif-v2.1.0.html
 * Supported by GitHub Security, VS Code SARIF Viewer, Azure DevOps, JetBrains IDEs.
 */
final class SarifFormatter implements FormatterInterface
{
    private const SCHEMA = 'https://raw.githubusercontent.com/oasis-tcs/sarif-spec/master/Schemata/sarif-schema-2.1.0.json';
    public function __construct(
        private readonly SarifRuleCollector $ruleCollector,
    ) {}

    public function format(Report $report, FormatterContext $context): string
    {
        $rules = $this->ruleCollector->collectRules($report->violations);

        // Build ruleIndex map: violationCode -> index in rules array
        $ruleIndexMap = [];
        foreach ($rules as $index => $rule) {
            $ruleIndexMap[$rule['id']] = $index;
        }

        $run = [
            'tool' => [
                'driver' => [
                    'name' => 'Qualimetrix',
                    'version' => Version::get(),
                    'informationUri' => SarifRuleCollector::INFORMATION_URI,
                    'rules' => $rules,
                ],
            ],
            'results' => $this->formatResults($report->violations, $context, $ruleIndexMap),
        ];

        // Add originalUriBaseIds when basePath is provided
        if ($context->basePath !== '') {
            $run['originalUriBaseIds'] = [
                '%SRCROOT%' => [
                    'uri' => self::pathToFileUri($context->basePath),
                ],
            ];
        }

        $sarif = [
            '$schema' => self::SCHEMA,
            'version' => '2.1.0',
            'runs' => [$run],
        ];

        return json_encode($sarif, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    public function getName(): string
    {
        return 'sarif';
    }

    public function getDefaultGroupBy(): GroupBy
    {
        return GroupBy::None;
    }

    /**
     * Formats violations as SARIF results.
     *
     * @param list<Violation> $violations
     * @param array<string, int> $ruleIndexMap
     *
     * @return list<array<string, mixed>>
     */
    private function formatResults(array $violations, FormatterContext $context, array $ruleIndexMap): array
    {
        return array_map(
            function (Violation $v) use ($context, $ruleIndexMap): array {
                $result = [
                    'ruleId' => $v->violationCode,
                    'ruleIndex' => $ruleIndexMap[$v->violationCode] ?? 0,
                    'level' => $this->ruleCollector->mapLevel($v->severity),
                    'message' => ['text' => $v->message],
                    'partialFingerprints' => [
                        'primaryLocationLineHash' => $v->getFingerprint(),
                    ],
                ];

                if ($v->location->isNone()) {
                    // Omit locations for project-level violations (valid per SARIF 2.1.0)
                } else {
                    $result['locations'] = [
                        [
                            'physicalLocation' => [
                                'artifactLocation' => $this->buildArtifactLocation(
                                    $context->relativizePath($v->location->file),
                                    $context->basePath !== '',
                                ),
                                'region' => [
                                    'startLine' => $v->location->line ?? 1,
                                    'startColumn' => 1,
                                ],
                            ],
                        ],
                    ];
                }

                if ($v->relatedLocations !== []) {
                    $result['relatedLocations'] = array_values(array_map(
                        fn(int $index, Location $loc): array => [
                            'id' => $index,
                            'physicalLocation' => [
                                'artifactLocation' => $this->buildArtifactLocation(
                                    $context->relativizePath($loc->file),
                                    $context->basePath !== '',
                                ),
                                'region' => [
                                    'startLine' => $loc->line ?? 1,
                                    'startColumn' => 1,
                                ],
                            ],
                            'message' => ['text' => 'Related location'],
                        ],
                        array_keys($v->relatedLocations),
                        $v->relatedLocations,
                    ));
                }

                return $result;
            },
            $violations,
        );
    }

    /**
     * Builds a SARIF artifactLocation entry.
     *
     * When a base path is configured, adds the uriBaseId reference so SARIF
     * consumers can resolve paths relative to the repository root.
     *
     * @return array<string, string>
     */
    private function buildArtifactLocation(string $uri, bool $hasBasePath): array
    {
        $location = ['uri' => $uri];

        if ($hasBasePath) {
            $location['uriBaseId'] = '%SRCROOT%';
        }

        return $location;
    }

    /**
     * Converts an absolute filesystem path to a file:/// URI (RFC 8089).
     *
     * Handles both Unix (/home/user/project) and Windows (C:\Users\project) paths.
     */
    private static function pathToFileUri(string $path): string
    {
        // Normalize backslashes to forward slashes (Windows)
        $path = str_replace('\\', '/', $path);

        // Ensure trailing slash
        $path = rtrim($path, '/') . '/';

        // Percent-encode path segments per RFC 3986 (handles spaces, #, % etc.)
        $segments = explode('/', $path);
        $encoded = implode('/', array_map('rawurlencode', $segments));

        // Restore Windows drive letter colon (e.g., don't encode C:)
        if (preg_match('/^([A-Za-z])%3A/', $encoded, $m) === 1) {
            $encoded = $m[1] . ':' . substr($encoded, \strlen($m[0]));
        }

        // RFC 8089: file:///path on Unix, file:///C:/path on Windows
        // Unix paths start with '/', so 'file://' + '/path' = 'file:///path' (correct)
        // Windows paths start with 'C:/', so 'file:///' + 'C:/path' = 'file:///C:/path' (correct)
        return 'file://' . ($path[0] === '/' ? '' : '/') . $encoded;
    }
}

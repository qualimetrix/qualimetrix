<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Sarif;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Core\ProductIdentity;
use Qualimetrix\Core\Version;
use Qualimetrix\Reporting\Formatter\FormatterInterface;
use Qualimetrix\Reporting\Formatter\PublishedFinding;
use Qualimetrix\Reporting\Formatter\PublishedUtf8;
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
    private const SCHEMA = 'https://raw.githubusercontent.com/oasis-tcs/sarif-spec/main/sarif-2.1/schema/sarif-schema-2.1.0.json';
    public function __construct(
        private readonly SarifRuleCollector $ruleCollector,
    ) {}

    public function format(Report $report, FormatterContext $context): string
    {
        $rules = $this->ruleCollector->collectRules($report->findings);

        // Build ruleIndex map: code -> index in rules array
        $ruleIndexMap = [];
        foreach ($rules as $index => $rule) {
            $ruleIndexMap[$rule['id']] = $index;
        }

        $run = [
            'tool' => [
                'driver' => [
                    'name' => 'Qualimetrix',
                    'version' => Version::get(),
                    'informationUri' => ProductIdentity::docsUrl(),
                    // `properties` is SARIF's extension point: a bare key on
                    // `driver` would be refused by schema-strict consumers.
                    'properties' => [
                        'llmsTxt' => ProductIdentity::llmsTxtUrl(),
                    ],
                    'rules' => $rules,
                ],
            ],
            'results' => $this->formatResults($report->findings, $context, $ruleIndexMap),
        ];

        if ($report->coverage !== null) {
            $run['invocations'] = [[
                'executionSuccessful' => $report->coverage->isComplete(),
                'toolExecutionNotifications' => array_map(
                    static fn($failure): array => [
                        'level' => 'error',
                        'message' => ['text' => \sprintf('%s: %s', $failure->path, $failure->message)],
                        'descriptor' => ['id' => 'QMX-ANALYSIS-' . strtoupper($failure->kind)],
                    ],
                    $report->coverage->failures,
                ),
            ]];
        }

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

        return PublishedUtf8::encodeJson(
            $sarif,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES,
            static function (array $sarif, int $repairs): array {
                $sarif['runs'][0]['invocations'] ??= [['executionSuccessful' => true, 'toolExecutionNotifications' => []]];
                $sarif['runs'][0]['invocations'][0]['toolExecutionNotifications'][] = [
                    'level' => 'warning',
                    'message' => ['text' => PublishedUtf8::describe($repairs)],
                    'descriptor' => ['id' => 'QMX-PUBLICATION-INVALID-UTF8'],
                ];

                return $sarif;
            },
        );
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
     * Formats findings as SARIF results.
     *
     * @param list<Finding> $findings
     * @param array<string, int> $ruleIndexMap
     *
     * @return list<array<string, mixed>>
     */
    private function formatResults(array $findings, FormatterContext $context, array $ruleIndexMap): array
    {
        return array_map(
            function (Finding $v) use ($context, $ruleIndexMap): array {
                // 'level' derives from Finding::severity, which a measured
                // breach already promoted to Error via reportedAsBreach()
                // (ADR 0017) — no extra mapping needed here for promotion to
                // propagate. The accepted level itself has no dedicated SARIF
                // field, so it rides along in the free-text message, same as
                // checkstyle/gitlab/github (ADR 0017).
                $result = [
                    'ruleId' => $v->code,
                    'ruleIndex' => $ruleIndexMap[$v->code] ?? 0,
                    'level' => $this->ruleCollector->mapLevel($v->severity),
                    'message' => ['text' => PublishedFinding::annotatedMessage($v)],
                    'partialFingerprints' => [
                        'primaryLocationLineHash' => $v->getFingerprint(),
                    ],
                ];

                if ($v->location->file === null) {
                    // Omit locations for project-level findings (valid per SARIF 2.1.0)
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
                        fn(int $index, Location $loc): array => $this->buildRelatedLocation($index, $loc, $context),
                        array_keys($v->relatedLocations),
                        $v->relatedLocations,
                    ));
                }

                return $result;
            },
            $findings,
        );
    }

    /**
     * A related location without a file keeps its id and message and carries
     * no physical location: `"uri": ""` would point at the base itself.
     *
     * @return array<string, mixed>
     */
    private function buildRelatedLocation(int $index, Location $loc, FormatterContext $context): array
    {
        if ($loc->file === null) {
            return ['id' => $index, 'message' => ['text' => 'Related location']];
        }

        return [
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
        ];
    }

    /**
     * Builds a SARIF artifactLocation entry.
     *
     * When a base path is configured, adds the uriBaseId reference so SARIF
     * consumers can resolve paths relative to the repository root.
     *
     * @return array<string, string>
     */
    private function buildArtifactLocation(string $relativePath, bool $hasBasePath): array
    {
        // A URI reference, encoded segment by segment like the base it is
        // resolved against: a raw `#` would end it, a raw `%` would be read as
        // an escape.
        $location = ['uri' => implode('/', array_map('rawurlencode', explode('/', $relativePath)))];

        if ($hasBasePath) {
            $location['uriBaseId'] = '%SRCROOT%';
        }

        return $location;
    }

    /**
     * Converts an absolute filesystem path to a file:/// URI (RFC 8089).
     *
     * Path separator handling is POSIX-only per ADR 0015; Windows paths must
     * already be POSIX-normalized at the boundary that produced $context->basePath.
     */
    private static function pathToFileUri(string $path): string
    {
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

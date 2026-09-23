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
        $repairs = 0;

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
            'results' => $this->formatResults($report->findings, $context, $ruleIndexMap, $repairs),
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

        if ($report->outOfScope !== null && $report->outOfScope->total() > 0) {
            $run = self::withNotification($run, 'note', $report->outOfScope->describe(), 'QMX-DRILL-DOWN-OUT-OF-SCOPE');
        }

        // Add originalUriBaseIds when basePath is provided
        if ($context->basePath !== '') {
            $run['originalUriBaseIds'] = [
                '%SRCROOT%' => [
                    'uri' => self::pathToFileUri($context->basePath, $repairs),
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
                $sarif['runs'][0] = self::withNotification(
                    $sarif['runs'][0],
                    'warning',
                    PublishedUtf8::describe($repairs),
                    'QMX-PUBLICATION-INVALID-UTF8',
                );

                return $sarif;
            },
            $repairs,
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
     * Adds a tool notification about the document itself, opening the
     * invocation when coverage did not.
     *
     * @param array<string, mixed> $run
     *
     * @return array<string, mixed>
     */
    private static function withNotification(array $run, string $level, string $text, string $descriptor): array
    {
        $run['invocations'] ??= [['executionSuccessful' => true, 'toolExecutionNotifications' => []]];
        $run['invocations'][0]['toolExecutionNotifications'][] = [
            'level' => $level,
            'message' => ['text' => $text],
            'descriptor' => ['id' => $descriptor],
        ];

        return $run;
    }

    /**
     * Formats findings as SARIF results.
     *
     * @param list<Finding> $findings
     * @param array<string, int> $ruleIndexMap
     *
     * @return list<array<string, mixed>>
     */
    private function formatResults(array $findings, FormatterContext $context, array $ruleIndexMap, int &$repairs): array
    {
        return array_map(
            function (Finding $v) use ($context, $ruleIndexMap, &$repairs): array {
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
                                    $repairs,
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
                        // Not an arrow function: it would capture the repair count by value and lose this location's repairs.
                        function (int $index, Location $loc) use ($context, &$repairs): array {
                            return $this->buildRelatedLocation($index, $loc, $context, $repairs);
                        },
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
    private function buildRelatedLocation(int $index, Location $loc, FormatterContext $context, int &$repairs): array
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
                    $repairs,
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
    private function buildArtifactLocation(string $relativePath, bool $hasBasePath, int &$repairs): array
    {
        // A URI reference, encoded segment by segment like the base it is
        // resolved against: a raw `#` would end it, a raw `%` would be read as
        // an escape.
        $location = ['uri' => self::encodeSegments($relativePath, $repairs)];

        if ($hasBasePath) {
            $location['uriBaseId'] = '%SRCROOT%';
        }

        return $location;
    }

    /**
     * Percent-encodes each segment of a path. The repair comes first:
     * `rawurlencode()` turns an invalid byte into a valid `%FF`, which the
     * document encoder would then publish without the repair's mark.
     */
    private static function encodeSegments(string $path, int &$repairs): string
    {
        return implode('/', array_map('rawurlencode', explode('/', PublishedUtf8::repair($path, $repairs))));
    }

    /**
     * Converts an absolute filesystem path to a file:/// URI (RFC 8089).
     *
     * Path separator handling is POSIX-only per ADR 0015; Windows paths must
     * already be POSIX-normalized at the boundary that produced $context->basePath.
     */
    private static function pathToFileUri(string $path, int &$repairs): string
    {
        // Ensure trailing slash
        $path = rtrim($path, '/') . '/';

        // Percent-encode path segments per RFC 3986 (handles spaces, #, % etc.)
        $encoded = self::encodeSegments($path, $repairs);

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

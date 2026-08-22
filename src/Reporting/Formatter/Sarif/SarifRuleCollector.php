<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Sarif;

use Qualimetrix\Analysis\Finding\Contract\ChannelPresentation;
use Qualimetrix\Analysis\Finding\Contract\ChannelPresentationInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;

/**
 * Collects and describes SARIF rule entries from a set of violations.
 *
 * Builds the rules array for the SARIF tool driver, including human-readable
 * names, descriptions, and documentation URLs. Both the description and the
 * `helpUri` are derived from the channel's producing rule via
 * {@see ChannelPresentationInterface} — see
 * `docs/internal/plans/sarif-channel-descriptions.md` ("Decision") for why
 * this class holds no table of its own: the two tables it used to carry
 * (`getRuleDescription()`'s `match`, `CATEGORY_DOCS_MAP`) had already drifted
 * from the rules they were copying.
 */
final class SarifRuleCollector
{
    public const INFORMATION_URI = 'https://github.com/qualimetrix/qualimetrix';

    /**
     * Site root, not `/rules/`: `computed.health` documents outside `rules/`
     * entirely (`reference/health-scores`), which no `/rules/`-rooted base
     * could ever address. See `docs/internal/plans/sarif-channel-descriptions.md`,
     * the "helpUri" section.
     */
    private const DOCS_BASE_URI = 'https://qualimetrix.dev/';

    public function __construct(
        private readonly ChannelPresentationInterface $presentation,
    ) {}

    /**
     * Collects unique rules from violations.
     *
     * @param list<Violation> $violations
     *
     * @return list<array{id: string, name: string, shortDescription: array{text: string}, fullDescription: array{text: string}, helpUri: string, defaultConfiguration: array{level: string}}>
     */
    public function collectRules(array $violations): array
    {
        // Collect unique violation codes with their max severity
        /** @var array<string, array{ruleName: string, maxSeverity: Severity}> $violationCodes */
        $violationCodes = [];

        foreach ($violations as $violation) {
            $code = $violation->violationCode;

            if (!isset($violationCodes[$code])) {
                $violationCodes[$code] = [
                    'ruleName' => $violation->ruleName,
                    'maxSeverity' => $violation->severity,
                ];
            } elseif (self::severityRank($violation->severity) > self::severityRank($violationCodes[$code]['maxSeverity'])) {
                $violationCodes[$code]['maxSeverity'] = $violation->severity;
            }
        }

        $rules = [];

        foreach ($violationCodes as $code => $info) {
            // Resolved once per code, not once per field: getRuleDescription()
            // and getHelpUri() each resolve presentationFor($code) again on their
            // own, which is the right thing for their public, independently
            // tested contract, but would mean three redundant resolutions here
            // for what is a single fact about one code.
            $presentation = $this->presentation->presentationFor($code);
            $description = $this->describeFrom($presentation, $code);

            $rules[] = [
                'id' => $code,
                'name' => $this->formatRuleName($code),
                'shortDescription' => [
                    'text' => $description,
                ],
                'fullDescription' => [
                    'text' => $description,
                ],
                'helpUri' => $this->helpUriFrom($presentation),
                'defaultConfiguration' => [
                    'level' => $this->mapLevel($info['maxSeverity']),
                ],
            ];
        }

        return $rules;
    }

    /**
     * Formats rule name from kebab-case to Title Case.
     */
    public function formatRuleName(string $ruleName): string
    {
        // Convert kebab-case and dot-separated names to words
        $words = (preg_split('/[-.]/', $ruleName) !== false ? preg_split('/[-.]/', $ruleName) : [$ruleName]);
        $words = array_map('ucfirst', $words);

        return implode(' ', $words);
    }

    /**
     * Returns human-readable description for a channel.
     *
     * Derived from {@see ChannelPresentationInterface}, which joins the
     * channel to its producing rule's own description. Falls back to a
     * humanised rendering of the code itself when no channel carries it, or
     * when the resolved description is empty — the same fallback for both,
     * since neither is a legitimate answer to show the user.
     */
    public function getRuleDescription(string $violationCode): string
    {
        return $this->describeFrom($this->presentation->presentationFor($violationCode), $violationCode);
    }

    private function describeFrom(?ChannelPresentation $presentation, string $violationCode): string
    {
        if ($presentation === null) {
            return ucfirst(str_replace(['.', '-'], ' ', $violationCode));
        }

        return $presentation->description;
    }

    /**
     * Returns the documentation URL for a channel.
     *
     * Built from the producing rule's declared documentation page (see
     * {@see ChannelPresentationInterface}), rewriting the `.md` extension to
     * a trailing slash to match the site's clean-URL routing — the same
     * rewrite {@see \Qualimetrix\Analysis\Finding\Contract\Rule\RuleDocsPageReader}'s
     * docblock documents. Falls back to the repository URL for unknown or
     * user-defined codes.
     */
    public function getHelpUri(string $violationCode): string
    {
        return $this->helpUriFrom($this->presentation->presentationFor($violationCode));
    }

    private function helpUriFrom(?ChannelPresentation $presentation): string
    {
        if ($presentation === null) {
            return self::INFORMATION_URI;
        }

        return self::DOCS_BASE_URI . preg_replace('/\.md$/', '/', $presentation->docsPage);
    }

    /**
     * Maps internal severity to SARIF level.
     *
     * SARIF levels: error, warning, note, none
     */
    public function mapLevel(Severity $severity): string
    {
        return match ($severity) {
            Severity::Error => 'error',
            Severity::Warning => 'warning',
            Severity::Info => 'note',
        };
    }

    /**
     * Numeric rank for ordering: Info < Warning < Error.
     */
    private static function severityRank(Severity $severity): int
    {
        return match ($severity) {
            Severity::Info => 0,
            Severity::Warning => 1,
            Severity::Error => 2,
        };
    }
}

<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Refusal;

/**
 * One question: does this format's stdout carry a JSON document?
 *
 * The set is closed: `json`, `sarif`, `gitlab`, `metrics`, `suppressed`.
 * `text`, `text-verbose`, `summary`, `health`, `checkstyle`, `github` and
 * `html` are excluded — their stdout contract is human-readable, XML, or
 * workflow-command text, and a JSON envelope in that stream would be worse
 * than leaving stdout empty and the failure available only on stderr.
 * `health` is the terminal table; its JSON form is the `health` section of
 * `--format=json`.
 *
 * "Closed" is enforced, not just claimed: both lists together are the
 * complete classification this class promises, and
 * `MachineReadableFormatsRegistryTest` boots the real DI container and
 * asserts `self::knownFormats()` is exactly the set of names
 * `FormatterRegistry` has registered — a formatter added under
 * CLAUDE.md §7's automatic registration and left unclassified here fails
 * that test rather than silently taking the `false` (no envelope) default.
 * The same test renders every formatter and holds each one to the list it
 * is on, so a format filed on the wrong side fails too.
 */
final class MachineReadableFormats
{
    private const array JSON_DOCUMENT_FORMATS = [
        'json',
        'sarif',
        'gitlab',
        'metrics',
        'suppressed',
    ];

    /** Every registered format whose stdout is deliberately not a JSON document. */
    private const array NON_JSON_FORMATS = [
        'text',
        'text-verbose',
        'summary',
        'health',
        'checkstyle',
        'github',
        'html',
    ];

    public static function carriesJson(?string $format): bool
    {
        return $format !== null && \in_array($format, self::JSON_DOCUMENT_FORMATS, true);
    }

    /**
     * Every format name this class has an explicit opinion about, JSON or
     * not — the set `MachineReadableFormatsRegistryTest` compares against
     * `FormatterRegistry`'s actual registrations.
     *
     * @return list<string>
     */
    public static function knownFormats(): array
    {
        return [...self::JSON_DOCUMENT_FORMATS, ...self::NON_JSON_FORMATS];
    }
}

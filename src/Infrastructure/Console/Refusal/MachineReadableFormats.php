<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Refusal;

/**
 * One question: does this format's stdout carry a JSON document?
 *
 * The set is closed and measured against `FormatterRegistry` on `1513bf67`
 * (`01-refusal-envelope.md` §2.1): `json`, `sarif`, `gitlab`, `metrics`,
 * `health`, `suppressed`. `text`, `text-verbose`, `summary`, `checkstyle`,
 * `github` and `html` are excluded — their stdout contract is human-readable,
 * XML, or workflow-command text, and a JSON envelope in that stream would be
 * worse than the empty stdout it replaces (`01-refusal-envelope.md` §2.1,
 * "Отвергнуто: конверт во всех двенадцати").
 */
final class MachineReadableFormats
{
    private const array JSON_DOCUMENT_FORMATS = [
        'json',
        'sarif',
        'gitlab',
        'metrics',
        'health',
        'suppressed',
    ];

    public static function carriesJson(?string $format): bool
    {
        return $format !== null && \in_array($format, self::JSON_DOCUMENT_FORMATS, true);
    }
}

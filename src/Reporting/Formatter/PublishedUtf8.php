<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter;

use JsonException;
use Qualimetrix\Core\SourceText\SourceBytes;

/**
 * Keeps a structured report valid UTF-8 when the analysed code is not.
 *
 * The parser accepts any byte above 0x7F inside an identifier, so a symbol
 * name, and every message quoting it, can reach publication as invalid UTF-8
 * after an otherwise complete analysis. A JSON encoder then refuses the whole
 * document and an XML writer emits one no parser accepts.
 *
 * Invalid strings use reversible percent encoding, preserving distinct bytes.
 * The repair is never silent: it counts the strings it
 * touched, and every structured format publishes that count in its own
 * diagnostic channel next to the repaired document.
 */
final class PublishedUtf8
{
    /** The document key the JSON object formats publish the repair count under. */
    public const string REPAIR_KEY = 'invalidUtf8Replaced';

    /** Check name / descriptor / source suffix the interchange formats publish the repair under. */
    public const string REPAIR_CHECK = 'publication.invalid-utf8';

    /**
     * Encodes a document, repairing its strings only when the encoder refuses
     * them. `$mark` receives the repaired document and the number of strings
     * repaired, and returns the document with that count published in it.
     *
     * `$repairedBefore` counts strings the caller repaired with {@see self::repair()}
     * itself, because a transformation of its own — percent-encoding a path —
     * would otherwise turn an invalid byte into valid ASCII the encoder never
     * refuses. They are marked even when the encoder accepts the document.
     *
     * @param array<mixed> $document
     * @param callable(array<mixed>, int): array<mixed> $mark
     */
    public static function encodeJson(array $document, int $flags, callable $mark, int $repairedBefore = 0): string
    {
        try {
            $encoded = json_encode($document, $flags | \JSON_THROW_ON_ERROR);

            return $repairedBefore === 0
                ? $encoded
                : json_encode($mark($document, $repairedBefore), $flags | \JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            if ($exception->getCode() !== \JSON_ERROR_UTF8) {
                throw $exception;
            }
        }

        $repairs = $repairedBefore;
        $repaired = self::repairTree($document, $repairs);

        return json_encode($mark($repaired, $repairs), $flags | \JSON_THROW_ON_ERROR);
    }

    /**
     * Encodes a JSON object document, publishing a repair under
     * {@see self::REPAIR_KEY}; `$repairs` receives the number of strings
     * repaired, 0 when the encoder accepted the document as it was.
     *
     * @param array<string, mixed> $document
     */
    public static function encodeJsonObject(array $document, int $flags, int &$repairs = 0): string
    {
        return self::encodeJson(
            $document,
            $flags,
            static function (array $repaired, int $count) use (&$repairs): array {
                $repairs = $count;

                return $repaired + [self::REPAIR_KEY => $count];
            },
        );
    }

    /**
     * The string with invalid bytes percent-escaped; `$repairs` is
     * incremented when anything was replaced.
     */
    public static function repair(string $value, int &$repairs): string
    {
        if (SourceBytes::isUtf8($value)) {
            return $value;
        }

        ++$repairs;

        return SourceBytes::escapeInvalid($value);
    }

    /**
     * The sentence every format uses to say it repaired strings.
     */
    public static function describe(int $repairs): string
    {
        return \sprintf(
            '%d published string(s) contained invalid UTF-8 from the analysed source; invalid bytes were percent-escaped as %%XX.',
            $repairs,
        );
    }

    private static function repairTree(mixed $value, int &$repairs): mixed
    {
        if (\is_string($value)) {
            return self::repair($value, $repairs);
        }

        if (\is_object($value)) {
            return (object) self::repairTree((array) $value, $repairs);
        }

        if (!\is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $item) {
            $result[\is_string($key) ? self::repair($key, $repairs) : $key] = self::repairTree($item, $repairs);
        }

        return $result;
    }
}

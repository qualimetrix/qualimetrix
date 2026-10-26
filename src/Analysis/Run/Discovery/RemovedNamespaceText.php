<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

use SplFileObject;

/**
 * One open removed file read in bounded chunks, and the namespaces its text
 * may declare when it is too large to tokenize.
 *
 * Every `namespace` word followed by a name or `{` counts, wherever it stands.
 * That lists every namespace the file declares and possibly words that are
 * none; the extra names only withhold more. A word whose name does not end
 * within the lookahead leaves the file unreadable, so each word costs at most
 * the lookahead and the heap holds about two chunks.
 */
final class RemovedNamespaceText
{
    private const int CHUNK_BYTES = 65536;

    private const int LOOKAHEAD_BYTES = 1024;

    private const int KEYWORD_BYTES = 9;

    private const string NAME = '/\G[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*/';

    private bool $ended = false;

    public function __construct(private readonly SplFileObject $file, private int $byteAllowance) {}

    public function bytesLeft(): int
    {
        return $this->byteAllowance;
    }

    /** @return string|null null when the read fails or would exceed the allowance */
    public function read(int $length): ?string
    {
        // @qmx-ignore-next-line code-smell.error-suppression -- A failed read stays null and makes the removed namespaces unknown; interpreter notices must not precede the report document.
        $chunk = @$this->file->fread(max(1, $this->byteAllowance < $length ? $this->byteAllowance + 1 : $length));
        if ($chunk === false) {
            return null;
        }
        $this->byteAllowance -= \strlen($chunk);

        return $this->byteAllowance < 0 ? null : $chunk;
    }

    /**
     * @param string $text what was already read from the file
     *
     * @return list<string>|null names as written and '' for global code; null when the file cannot be read
     */
    public function namespacesAfter(string $text): ?array
    {
        $declared = [];
        while (!$this->ended) {
            $chunk = $this->read(self::CHUNK_BYTES);
            if ($chunk === null) {
                return null;
            }
            $this->ended = $chunk === '';
            $text .= $chunk;
            // A word starting before this offset has its whole lookahead in the buffer.
            $settled = $this->ended ? \strlen($text) : \strlen($text) - self::KEYWORD_BYTES - self::LOOKAHEAD_BYTES;
            $consumed = $this->namesBefore($text, $settled, $declared);
            if ($consumed === null) {
                return null;
            }
            $text = substr($text, max($consumed, $settled, 0));
        }
        $declared[''] = true;

        return array_map(strval(...), array_keys($declared));
    }

    /**
     * @param array<string, true> $declared
     *
     * @return int|null the offset past the last word read; null when a name does not end within the lookahead
     */
    private function namesBefore(string $text, int $settled, array &$declared): ?int
    {
        $offset = 0;
        while (($hit = stripos($text, 'namespace', $offset)) !== false && $hit < $settled) {
            $offset = $hit + self::KEYWORD_BYTES;
            $name = $this->nameAfter($text, $offset);
            if ($name === null) {
                return null;
            }
            if ($name !== false) {
                $declared[$name] = true;
            }
        }

        return $offset;
    }

    /** @return string|false|null the name or '' for a block; false when no name follows; null when undecided */
    private function nameAfter(string $text, int $at): string|false|null
    {
        $limit = min(\strlen($text), $at + self::LOOKAHEAD_BYTES);
        $atEnd = $this->ended && $limit === \strlen($text);
        $at = self::pastTrivia($text, $at, $limit);
        if ($at === null) {
            return $atEnd ? false : null;
        }
        if ($text[$at] === '{') {
            return '';
        }
        if (preg_match(self::NAME, $text, $match, 0, $at) !== 1) {
            return false;
        }

        return $at + \strlen($match[0]) < $limit || $atEnd ? $match[0] : null;
    }

    /** @return int|null the first offset that is neither whitespace nor comment; null when none lies before the limit */
    private static function pastTrivia(string $text, int $at, int $limit): ?int
    {
        while (($at += strspn($text, " \t\r\n", $at)) < $limit) {
            $end = match (true) {
                substr_compare($text, '/*', $at, 2) === 0 => self::after($text, '*/', $at + 2, $limit),
                substr_compare($text, '//', $at, 2) === 0, $text[$at] === '#' => self::pastLineComment($text, $at + 1, $limit),
                default => $at,
            };
            if ($end === $at || $end === null) {
                return $end;
            }
            $at = $end;
        }

        return null;
    }

    /** Where a `//` or `#` comment ends as the lexer ends it: past a CR or LF, or at a closing tag. */
    private static function pastLineComment(string $text, int $from, int $limit): ?int
    {
        $line = substr($text, $from, $limit - $from);
        $length = strcspn($line, "\r\n");
        $close = strpos($line, '?>');
        if ($close !== false && $close < $length) {
            return $from + $close;
        }

        return $length < \strlen($line) ? $from + $length + 1 : null;
    }

    /** The offset past the needle when it ends before the limit. */
    private static function after(string $text, string $needle, int $from, int $limit): ?int
    {
        $found = strpos(substr($text, $from, $limit - $from), $needle);

        return $found === false || $from + $found + \strlen($needle) > $limit ? null : $from + $found + \strlen($needle);
    }
}

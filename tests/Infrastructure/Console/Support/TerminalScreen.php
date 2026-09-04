<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Support;

/**
 * A terminal small enough to be an oracle: it replays a byte stream and reports
 * the screen the user is left looking at.
 *
 * Comparing bytes cannot answer the question this stand exists for. A run whose
 * screen is intact and a run whose screen is destroyed differ in bytes either
 * way — the frame's erase sequences are part of normal drawing. What
 * distinguishes them is what survives *after* the erases are applied, which
 * takes a cursor and a line buffer.
 *
 * Implemented: `CUU` (`ESC[nA`), `CUD`, `CUF`, `CUB`, `CHA` (`ESC[nG`),
 * `EL` (`ESC[nK`), `ED` (`ESC[nJ`), carriage return, line feed and backspace.
 * Everything else — `SGR` colour above all — is consumed without moving the
 * cursor, which is exactly its effect on the visible text.
 */
final class TerminalScreen
{
    /**
     * Indexed by row. Not declared a list: an erase rewrites one row in place,
     * and the analyser cannot see that the index is always within range.
     *
     * @var array<int, string>
     */
    private array $lines = [''];

    private int $row = 0;

    private int $column = 0;

    public function __construct(private readonly int $width = 120) {}

    public static function replay(string $bytes, int $width = 120): self
    {
        $screen = new self($width);
        $screen->feed($bytes);

        return $screen;
    }

    public function feed(string $bytes): void
    {
        $offset = 0;
        $length = \strlen($bytes);

        while ($offset < $length) {
            $byte = $bytes[$offset];

            if ($byte === "\x1b" && $offset + 1 < $length && $bytes[$offset + 1] === '[') {
                $cursor = $offset + 2;
                $parameters = '';
                while ($cursor < $length && !ctype_alpha($bytes[$cursor])) {
                    $parameters .= $bytes[$cursor];
                    ++$cursor;
                }
                $this->applyControl($cursor < $length ? $bytes[$cursor] : '', $parameters);
                $offset = $cursor + 1;

                continue;
            }

            if ($byte === "\x1b") {
                $offset += 2;

                continue;
            }

            if ($byte === "\r") {
                $this->column = 0;
                ++$offset;

                continue;
            }

            if ($byte === "\n") {
                ++$this->row;
                $this->column = 0;
                $this->ensureRow();
                ++$offset;

                continue;
            }

            if ($byte === "\x08") {
                $this->column = max(0, $this->column - 1);
                ++$offset;

                continue;
            }

            $width = self::characterWidth($byte);
            $this->put(substr($bytes, $offset, $width));
            $offset += $width;
        }
    }

    /** @return list<string> */
    public function lines(): array
    {
        $lines = array_map(static fn(string $line): string => rtrim($line), $this->lines);
        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return array_values($lines);
    }

    public function text(): string
    {
        return implode("\n", $this->lines());
    }

    /**
     * The screen with wrapping undone, so an assertion about one written line
     * does not have to know the terminal width.
     */
    public function unwrappedText(): string
    {
        $joined = '';
        foreach ($this->lines as $line) {
            $joined .= $line;
            if (self::displayWidth($line) < $this->width) {
                $joined .= "\n";
            }
        }

        return $joined;
    }

    private function applyControl(string $final, string $parameters): void
    {
        $count = $parameters === '' ? null : (int) $parameters;

        switch ($final) {
            case 'A':
                $this->row = max(0, $this->row - ($count ?? 1));
                break;
            case 'B':
                $this->row += $count ?? 1;
                $this->ensureRow();
                break;
            case 'C':
                $this->column += $count ?? 1;
                break;
            case 'D':
                $this->column = max(0, $this->column - ($count ?? 1));
                break;
            case 'G':
                $this->column = max(0, ($count ?? 1) - 1);
                break;
            case 'K':
                $this->eraseInLine($count ?? 0);
                break;
            case 'J':
                $this->eraseInDisplay($count ?? 0);
                break;
            default:
                break;
        }
    }

    private function eraseInLine(int $mode): void
    {
        $this->ensureRow();
        $cells = self::cells($this->lines[$this->row]);

        $this->lines[$this->row] = match ($mode) {
            2 => '',
            1 => str_repeat(' ', min($this->column, \count($cells)))
                . implode('', \array_slice($cells, $this->column)),
            default => implode('', \array_slice($cells, 0, $this->column)),
        };
    }

    private function eraseInDisplay(int $mode): void
    {
        $this->ensureRow();

        if ($mode === 2) {
            $this->lines = [''];
            $this->row = 0;
            $this->column = 0;

            return;
        }

        if ($mode !== 0) {
            return;
        }

        $cells = self::cells($this->lines[$this->row]);
        $this->lines[$this->row] = implode('', \array_slice($cells, 0, $this->column));
        $this->lines = \array_slice($this->lines, 0, $this->row + 1);
    }

    private function put(string $cell): void
    {
        $this->ensureRow();
        $cells = self::cells($this->lines[$this->row]);
        while (\count($cells) < $this->column) {
            $cells[] = ' ';
        }
        $cells[$this->column] = $cell;
        $this->lines[$this->row] = implode('', $cells);
        ++$this->column;

        if ($this->column >= $this->width) {
            ++$this->row;
            $this->column = 0;
            $this->ensureRow();
        }
    }

    private function ensureRow(): void
    {
        while (\count($this->lines) <= $this->row) {
            $this->lines[] = '';
        }
    }

    /** @return list<string> */
    private static function cells(string $line): array
    {
        $cells = preg_split('//u', $line, -1, \PREG_SPLIT_NO_EMPTY);

        return $cells === false ? [] : $cells;
    }

    private static function displayWidth(string $line): int
    {
        return \count(self::cells($line));
    }

    private static function characterWidth(string $byte): int
    {
        $code = \ord($byte);

        return match (true) {
            $code >= 0xF0 => 4,
            $code >= 0xE0 => 3,
            $code >= 0xC0 => 2,
            default => 1,
        };
    }
}

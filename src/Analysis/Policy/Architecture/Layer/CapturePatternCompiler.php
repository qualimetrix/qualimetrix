<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer;

use InvalidArgumentException;

/** Compiles one Architecture capture-pattern source into its immutable parts. */
final class CapturePatternCompiler
{
    public const string VARIABLE_NAME_REGEX = '[A-Za-z_][A-Za-z0-9_]*';

    private int $cursor = 0;

    private string $regex = '~\\A(?:';

    /** @var list<string> */
    private array $variables = [];

    /** @var list<string> */
    private array $multiSegmentVariables = [];

    /** @var array<string, true> */
    private array $seenVariables = [];

    public function __construct(private readonly string $source) {}

    /** @return array{string, list<string>, list<string>} */
    public function compile(): array
    {
        while ($this->cursor < \strlen($this->source)) {
            $this->consumeToken();
        }

        return [$this->regex . ')\\z~', $this->variables, $this->multiSegmentVariables];
    }

    private function consumeToken(): void
    {
        $char = $this->source[$this->cursor];

        match ($char) {
            '\\' => $this->consumeSeparator(),
            '}' => $this->rejectClosingBrace(),
            '{' => $this->consumeCapture(),
            '*' => $this->consumeWildcard(),
            '?' => $this->consumeSingleCharacterWildcard(),
            default => $this->consumeLiteral($char),
        };
    }

    private function consumeSeparator(): void
    {
        $remaining = substr($this->source, $this->cursor);
        if ($remaining === '\\**') {
            $this->regex .= '\\\\.+';
            $this->cursor += 3;

            return;
        }

        if (str_starts_with($remaining, '\\**\\')) {
            $this->regex .= '\\\\(?:[^\\\\]+\\\\)*';
            $this->cursor += 4;

            return;
        }

        $this->regex .= preg_quote('\\', '~');
        $this->cursor++;
    }

    private function rejectClosingBrace(): never
    {
        throw new InvalidArgumentException(\sprintf(
            "CapturePattern: unbalanced '}' at offset %d in pattern \"%s\".",
            $this->cursor,
            $this->source,
        ));
    }

    private function consumeCapture(): void
    {
        [$variableName, $multiSegment, $advance] = self::parseCapture($this->source, $this->cursor);

        if (isset($this->seenVariables[$variableName])) {
            throw new InvalidArgumentException(\sprintf(
                "CapturePattern: duplicate capture name '%s' in pattern \"%s\" — each variable may only appear once.",
                $variableName,
                $this->source,
            ));
        }

        if ($advance < \strlen($this->source) && $this->source[$advance] === '{') {
            throw new InvalidArgumentException(\sprintf(
                "CapturePattern: adjacent captures '{%s}{...}' at offset %d in pattern \"%s\" — "
                . 'insert a namespace separator (\'\\\\\') between consecutive captures.',
                $variableName,
                $advance,
                $this->source,
            ));
        }

        $this->seenVariables[$variableName] = true;
        $this->variables[] = $variableName;
        if ($multiSegment) {
            $this->multiSegmentVariables[] = $variableName;
        }

        $this->regex .= $multiSegment
            ? '(?P<' . $variableName . '>[^\\\\]+(?:\\\\[^\\\\]+)*)'
            : '(?P<' . $variableName . '>[^\\\\]+)';
        $this->cursor = $advance;
    }

    private function consumeWildcard(): void
    {
        if ($this->cursor === 0 && str_starts_with($this->source, '**\\')) {
            $this->regex .= '(?:[^\\\\]+\\\\)*';
            $this->cursor += 3;

            return;
        }

        if (substr($this->source, $this->cursor, 2) === '**') {
            $this->regex .= '.*';
            $this->cursor += 2;

            return;
        }

        $this->regex .= '[^\\\\]*';
        $this->cursor++;
    }

    private function consumeSingleCharacterWildcard(): void
    {
        $this->regex .= '[^\\\\]';
        $this->cursor++;
    }

    private function consumeLiteral(string $char): void
    {
        $this->regex .= preg_quote($char, '~');
        $this->cursor++;
    }

    /** @return array{string, bool, int} */
    public static function parseCapture(string $source, int $openAt): array
    {
        $closeAt = self::closingBrace($source, $openAt);
        $body = substr($source, $openAt + 1, $closeAt - $openAt - 1);
        if ($body === '') {
            throw new InvalidArgumentException(\sprintf(
                "CapturePattern: empty capture '{}' at offset %d in pattern \"%s\".",
                $openAt,
                $source,
            ));
        }

        [$name, $multiSegment] = self::captureBody($body, $source);
        if (preg_match('/^' . self::VARIABLE_NAME_REGEX . '$/', $name) !== 1) {
            throw new InvalidArgumentException(\sprintf(
                "CapturePattern: invalid capture name '%s' in pattern \"%s\" (must match %s).",
                $name,
                $source,
                self::VARIABLE_NAME_REGEX,
            ));
        }

        return [$name, $multiSegment, $closeAt + 1];
    }

    private static function closingBrace(string $source, int $openAt): int
    {
        for ($offset = $openAt + 1, $length = \strlen($source); $offset < $length; $offset++) {
            if ($source[$offset] === '{') {
                throw new InvalidArgumentException(\sprintf(
                    "CapturePattern: nested '{' at offset %d in pattern \"%s\" — captures cannot contain other captures.",
                    $offset,
                    $source,
                ));
            }

            if ($source[$offset] === '}') {
                return $offset;
            }
        }

        throw new InvalidArgumentException(\sprintf(
            "CapturePattern: unbalanced '{' at offset %d in pattern \"%s\".",
            $openAt,
            $source,
        ));
    }

    /** @return array{string, bool} */
    private static function captureBody(string $body, string $source): array
    {
        if (!str_contains($body, ':')) {
            return [$body, false];
        }

        [$name, $quantifier] = explode(':', $body, 2);
        if ($quantifier !== '*' && $quantifier !== '**') {
            throw new InvalidArgumentException(\sprintf(
                "CapturePattern: unknown capture quantifier ':%s' in pattern \"%s\" (only ':*' and ':**' are supported).",
                $quantifier,
                $source,
            ));
        }

        return [$name, $quantifier === '**'];
    }
}

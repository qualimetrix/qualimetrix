<?php

declare(strict_types=1);

namespace QmxFindingGate;

/** Finding lines keep their location and message; a parenthesis inside a message is never stripped. */
final class ProseRecords
{
    public const array SURFACES = ['format:summary', 'format:text', 'format:text-verbose', 'format:github', 'show-suppressed'];

    public const array FIELDS = [
        'format:summary' => ['code', 'file', 'line', 'message', 'severity', 'score'],
        'format:text' => ['code', 'file', 'line', 'message', 'severity', 'symbol'],
        'format:text-verbose' => ['code', 'file', 'line', 'message', 'severity', 'symbol'],
        'format:github' => ['code', 'file', 'line', 'message', 'severity'],
        'show-suppressed' => ['code', 'file', 'line', 'message', 'severity', 'symbol'],
    ];

    /** @return list<array{lines:list<int>,fields:array<string,mixed>}> */
    public static function extract(string $surface, string $text): array
    {
        if (!\in_array($surface, self::SURFACES, true)) {
            throw new GateError('Unknown prose finding surface.');
        }
        $lines = explode("\n", $text);
        $records = [];
        $file = null;
        for ($index = 0; $index < \count($lines); ++$index) {
            $line = $lines[$index];
            if (preg_match('~^(.+) \([0-9]+ violations?\)$~D', $line, $match) === 1) {
                $file = $match[1];
            }
            if ($surface === 'format:github' && preg_match('~^::(error|warning|notice) (.*?)::(.*)$~D', $line, $match) === 1) {
                $properties = [];
                foreach (explode(',', $match[2]) as $property) {
                    $parts = explode('=', $property, 2);
                    if (\count($parts) !== 2) {
                        throw new GateError('A GitHub annotation property has no value.');
                    }
                    $properties[$parts[0]] = self::unescape($parts[1], true);
                }
                if (!isset($properties['title'])) {
                    throw new GateError('A GitHub annotation requires its channel title.');
                }
                $records[] = ['lines' => [$index], 'fields' => ['code' => $properties['title'], 'file' => $properties['file'] ?? '[project]', 'line' => isset($properties['line']) ? (int) $properties['line'] : null, 'message' => self::unescape($match[3], false), 'severity' => $match[1] === 'notice' ? 'info' : $match[1]]];
            } elseif (preg_match('~^(.+?)(?::([0-9]+))?: (error|warning|info)\[([^\]]+)\]: (.*)$~D', $line, $match) === 1) {
                $records[] = ['lines' => [$index], 'fields' => ['code' => $match[4], 'file' => $match[1], 'line' => $match[2] === '' ? null : (int) $match[2], 'message' => $match[5], 'severity' => $match[3]]];
            } elseif (preg_match('~^  (ERROR|WARN|INFO)(?: ([^ ].*?))?(?:  (.+))?$~D', $line, $head) === 1) {
                [$detail, $end] = self::detail($lines, $index);
                $location = $head[2] ?? '';
                $atLine = null;
                $locationFile = $file;
                if (preg_match('~^at line ([0-9]+)$~D', $location, $at) === 1) {
                    $atLine = (int) $at[1];
                } elseif ($location !== '') {
                    [$locationFile, $atLine] = self::location($location);
                }
                if ($locationFile === null) {
                    throw new GateError('A detailed finding line has no observed file or project group.');
                }
                $records[] = ['lines' => range($index, $end), 'fields' => ['code' => $detail[2], 'file' => $locationFile, 'line' => $atLine, 'message' => $detail[1], 'severity' => match ($head[1]) {
                    'ERROR' => 'error', 'WARN' => 'warning', default => 'info',
                }, 'symbol' => $head[3] ?? '']];
                $index = $end;
            } elseif ($surface === 'format:summary' && preg_match('~^\s+[0-9]+\. \[(ERR|WRN|INF)\] ([0-9.]+)  (.*?)  \[.*\]$~D', $line, $head) === 1) {
                if (!isset($lines[$index + 1]) || preg_match('~^\s+([^:]+): (.*)$~D', $lines[$index + 1], $detail) !== 1) {
                    throw new GateError('A ranked issue has no published detail line.');
                }
                $end = $index + 1;
                $message = $detail[2];
                while (isset($lines[$end + 1]) && self::continuation($lines[$end + 1])) {
                    $message .= "\n" . $lines[++$end];
                }
                [$locationFile, $atLine] = self::location($head[3]);
                $records[] = ['lines' => range($index, $end), 'fields' => ['code' => $detail[1], 'file' => $locationFile, 'line' => $atLine, 'message' => $message, 'severity' => match ($head[1]) {
                    'ERR' => 'error', 'WRN' => 'warning', default => 'info',
                }, 'score' => $head[2]]];
                $index = $end;
            }
        }
        return $records;
    }

    /**
     * @param list<string> $lines
     *
     * @return array{array{0:string,1:string,2:string},int}
     */
    private static function detail(array $lines, int $index): array
    {
        $text = '';
        for ($end = $index + 1; isset($lines[$end]); ++$end) {
            $line = $lines[$end];
            if ($line === '' || ($end === $index + 1 ? !str_starts_with($line, '    ') : !self::continuation($line))) {
                break;
            }
            $text .= ($end === $index + 1 ? '' : "\n") . $line;
            if (preg_match('~^    (.*)  \[([^\]]+)\]$~Ds', $text, $detail) === 1) {
                return [$detail, $end];
            }
        }
        throw new GateError('A detailed finding has no complete published advice and channel terminator.');
    }

    private static function continuation(string $line): bool
    {
        return $line !== '' && !ctype_space($line[0])
            && preg_match('~^(?:[0-9]+ violations?\b|Qualimetrix\b|Technical debt\b|Analysis complete:|Docs:|Hints:|[^:]+: (?:error|warning|info)\[|.+ \([0-9]+ violations?\)$)~D', $line) !== 1;
    }

    /** @return array{string,?int} */
    private static function location(string $location): array
    {
        if (preg_match('~^(.*):([0-9]+)$~D', $location, $match) === 1) {
            return [$match[1], (int) $match[2]];
        }
        return [$location, null];
    }

    private static function unescape(string $text, bool $property): string
    {
        $map = ['%0D' => "\r", '%0A' => "\n"];
        if ($property) {
            $map += ['%3A' => ':', '%2C' => ','];
        }
        // Percent is last, so a literal "%0A" does not become a line break.
        return str_replace('%25', '%', strtr($text, $map));
    }

    /**
     * @param array<string,mixed> $prose
     * @param array<string,mixed> $finding
     */
    public static function matches(string $surface, array $prose, array $finding): bool
    {
        if ($prose['code'] !== $finding['code'] || $prose['file'] !== ($finding['file'] ?? '[project]')
            || ($prose['line'] !== null && $prose['line'] !== $finding['line']) || $prose['severity'] !== $finding['severity']) {
            return false;
        }
        $detailed = isset($prose['symbol']);
        $message = ReportRecords::message($finding, $detailed || $surface === 'format:summary');
        $symbol = self::symbol($finding, $detailed);
        if ($detailed) {
            return $prose['message'] === $message && $prose['symbol'] === $symbol;
        }
        if ($surface === 'format:github') {
            return $prose['message'] === $message;
        }
        $suffix = $symbol === '' ? '' : ' (' . $symbol . ')';
        if ($surface === 'format:summary' && SubjectLevel::of((string) $finding['subject']) === 'class') {
            $suffix = '';
        }
        return $prose['message'] === $message . $suffix;
    }

    /** @param array<string,mixed> $finding */
    private static function symbol(array $finding, bool $detailed = false): string
    {
        $subject = (string) $finding['subject'];
        $symbol = (string) $finding['symbol'];
        $level = SubjectLevel::of($subject);
        if ($finding['file'] !== null && $symbol === $finding['file']) {
            return '';
        }
        if (str_starts_with($subject, 'ns:')) {
            return $detailed || $symbol === '' ? '' : 'namespace: ' . $symbol;
        }
        if ($level === 'file' || $level === 'project') {
            return '';
        }
        $at = strrpos($symbol, '\\');
        return $at === false ? $symbol : substr($symbol, $at + 1);
    }

    /**
     * @param array{lines:list<int>,fields:array<string,mixed>} $entry
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    public static function rewriteProjection(string $surface, string $text, array $entry, array $before, array $after): string
    {
        $lines = explode("\n", $text);
        $index = $entry['lines'][0];
        if ($surface === 'format:github') {
            $escape = static fn(string $value, bool $property): string => strtr($value, $property
                ? ['%' => '%25', "\r" => '%0D', "\n" => '%0A', ':' => '%3A', ',' => '%2C']
                : ['%' => '%25', "\r" => '%0D', "\n" => '%0A']);
            $line = $lines[$index];
            foreach (['file' => $after['file'], 'line' => $after['line'], 'title' => $after['code']] as $key => $value) {
                $line = preg_replace_callback('~(?<= |,)' . $key . '=([^,:]*)(?=,|::)~', static fn(array $match): string => $key . '=' . $escape((string) $value, true), $line) ?? throw new GateError('Cannot rewrite a GitHub annotation property.');
            }
            $tag = $after['severity'] === 'info' ? 'notice' : $after['severity'];
            $line = preg_replace('~^::(?:error|warning|notice) ~', '::' . $tag . ' ', $line) ?? throw new GateError('Cannot rewrite an annotation level.');
            $at = strpos($line, '::', 2);
            if ($at === false) {
                throw new GateError('Cannot locate an annotation payload.');
            }
            $lines[$index] = substr($line, 0, $at + 2) . $escape(ReportRecords::message($after), false);
        } elseif (isset($entry['fields']['symbol'])) {
            $location = ($after['file'] ?? '[project]') . ($entry['fields']['line'] === null || $after['line'] === null ? '' : ':' . $after['line']);
            if ($before['file'] === $after['file']) {
                if (str_contains($lines[$index], 'at line ')) {
                    $location = $after['line'] === null ? '' : 'at line ' . $after['line'];
                } elseif (preg_match('~^  (?:ERROR|WARN|INFO)(?:  .+)?$~D', $lines[$index]) === 1) {
                    $location = '';
                }
            }
            $tag = match ($after['severity']) {
                'error' => 'ERROR', 'warning' => 'WARN', default => 'INFO',
            };
            $symbol = self::symbol($after, true);
            $lines[$index] = '  ' . $tag . ($location === '' ? '' : ' ' . $location) . ($symbol === '' ? '' : '  ' . $symbol);
            self::replaceAdvice($lines, $entry, '    ' . ReportRecords::message($after, true) . '  [' . $after['code'] . ']');
        } elseif ($surface === 'format:summary') {
            $location = ($after['file'] ?? '[project]') . ($entry['fields']['line'] === null || $after['line'] === null ? '' : ':' . $after['line']);
            $tag = match ($after['severity']) {
                'error' => 'ERR', 'warning' => 'WRN', default => 'INF',
            };
            $lines[$index] = preg_replace('~\[(?:ERR|WRN|INF)\] ([0-9.]+)  (.*?)  \[~', '[' . $tag . '] $1  ' . $location . '  [', $lines[$index]) ?? throw new GateError('Cannot rewrite a summary location.');
            $suffix = SubjectLevel::of((string) $after['subject']) === 'class' || self::symbol($after) === '' ? '' : ' (' . self::symbol($after) . ')';
            if (preg_match('~^\s*~', $lines[$entry['lines'][1]], $indent) !== 1) {
                throw new GateError('Cannot locate summary detail indentation.');
            }
            self::replaceAdvice($lines, $entry, $indent[0] . $after['code'] . ': ' . ReportRecords::message($after, true) . $suffix);
        } else {
            $suffix = self::symbol($after) === '' ? '' : ' (' . self::symbol($after) . ')';
            $lines[$index] = ($after['file'] ?? '[project]') . ($entry['fields']['line'] === null || $after['line'] === null ? '' : ':' . $after['line']) . ': ' . $after['severity'] . '[' . $after['code'] . ']: ' . ReportRecords::message($after) . $suffix;
        }
        return implode("\n", $lines);
    }

    /**
     * @param array<int,string> $lines
     * @param array{lines:list<int>,fields:array<string,mixed>} $entry
     */
    private static function replaceAdvice(array &$lines, array $entry, string $advice): void
    {
        array_splice($lines, $entry['lines'][1], \count($entry['lines']) - 1, explode("\n", $advice));
    }

    /** @param list<int> $removed */
    public static function erase(string $text, array $removed): string
    {
        $lines = explode("\n", $text);
        foreach ($removed as $index) {
            unset($lines[$index]);
        }
        return implode("\n", $lines);
    }
}

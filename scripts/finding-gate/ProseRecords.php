<?php

declare(strict_types=1);

namespace QmxFindingGate;

/** Finding lines keep their location and message; a parenthesis inside a message is never stripped. */
final class ProseRecords
{
    public const array SURFACES = ['format:summary', 'format:text', 'format:text-detail', 'format:text-verbose', 'format:github', 'show-suppressed'];

    public const array FIELDS = [
        'format:summary' => ['code', 'file', 'line', 'message', 'severity', 'rank', 'debt', 'score'],
        'format:text' => ['code', 'file', 'line', 'message', 'severity', 'symbol'],
        'format:text-detail' => ['code', 'file', 'line', 'message', 'severity', 'symbol'],
        'format:text-verbose' => ['code', 'file', 'line', 'message', 'severity', 'symbol'],
        'format:github' => ['code', 'file', 'line', 'message', 'severity'],
        'show-suppressed' => ['code', 'file', 'line', 'message', 'severity', 'symbol'],
    ];

    /** @return list<array{lines:list<int>,fields:array<string,mixed>}> */
    public static function extract(string $surface, string $text, string $codec = 'legacy', bool $requirePopulation = false): array
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
                if (($match[1] === 'notice' && array_keys($properties) === ['title']
                    && \in_array($properties['title'], ['drill-down.out-of-scope', 'run.project-scope'], true))
                    || ($match[1] === 'error' && isset($properties['file']) && ($properties['line'] ?? null) === '1'
                        && \in_array($properties['title'], ReportRecords::ANALYSIS_DIAGNOSTICS, true))) {
                    continue;
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
                $extra = $codec === 'current' && $surface !== 'format:text-verbose' ? self::extraLines($lines, $end) : [];
                $records[] = ['lines' => range($index, $end), 'fields' => $extra + ['code' => $detail[2], 'file' => $locationFile, 'line' => $atLine, 'message' => $detail[1], 'severity' => match ($head[1]) {
                    'ERROR' => 'error', 'WARN' => 'warning', default => 'info',
                }, 'symbol' => $head[3] ?? '']];
                $index = $end;
            } elseif ($surface === 'format:summary' && preg_match('~^\s+([0-9]+)\. \[(ERR|WRN|INF)\] ([0-9]+(?:\.[0-9]+)?)  (.*?)  \[([^\]]*)\]$~D', $line, $head) === 1) {
                if (!isset($lines[$index + 1]) || preg_match('~^\s+([^:]+): (.*)$~D', $lines[$index + 1], $detail) !== 1) {
                    throw new GateError('A ranked issue has no published detail line.');
                }
                $end = $index + 1;
                $message = $detail[2];
                while (isset($lines[$end + 1]) && self::continuation($lines[$end + 1])) {
                    $message .= "\n" . $lines[++$end];
                }
                $extra = $codec === 'current' ? self::extraLines($lines, $end) : [];
                [$locationFile, $atLine] = self::location($head[4]);
                $records[] = ['lines' => range($index, $end), 'fields' => $extra + ['code' => $detail[1], 'file' => $locationFile, 'line' => $atLine, 'message' => $message, 'severity' => match ($head[2]) {
                    'ERR' => 'error', 'WRN' => 'warning', default => 'info',
                }, 'rank' => (int) $head[1], 'debt' => $head[5], 'score' => $head[3]]];
                $index = $end;
            }
        }
        if ($requirePopulation && $records === []) {
            $empty = array_intersect(array_map(trim(...), $lines), ['No findings', 'No violations found.', 'No violations in this scope.']);
            if ($empty === []) {
                throw new GateError('The prose publication has no observed finding population.');
            }
        }
        return $records;
    }

    /** @return list<string> */
    public static function fieldsOf(string $surface, string $codec): array
    {
        $fields = self::FIELDS[$surface] ?? [];
        return $codec === 'current' && \in_array($surface, ['format:summary', 'format:text-detail', 'show-suppressed'], true)
            ? [...$fields, 'recommendation', 'acceptedLevel'] : $fields;
    }

    /**
     * @param list<string> $lines
     *
     * @return array{recommendation: ?string, acceptedLevel: ?string}
     */
    private static function extraLines(array $lines, int &$end): array
    {
        $fields = ['recommendation' => null, 'acceptedLevel' => null];
        if (isset($lines[$end + 1]) && preg_match('~^ +Recommendation: (.*)$~D', $lines[$end + 1], $match) === 1) {
            ++$end;
            $fields['recommendation'] = $match[1];
            while (isset($lines[$end + 1]) && self::continuation($lines[$end + 1])) {
                $fields['recommendation'] .= "\n" . $lines[++$end];
            }
        }
        if (isset($lines[$end + 1]) && preg_match('~^ +(accepted at .+)$~D', $lines[$end + 1], $match) === 1) {
            ++$end;
            $fields['acceptedLevel'] = $match[1];
        }
        return $fields;
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
    public static function matches(string $surface, array $prose, array $finding, string $codec = 'legacy'): bool
    {
        if ($prose['code'] !== $finding['code'] || $prose['file'] !== ($finding['file'] ?? ($codec === 'current' && $surface !== 'format:github' ? ReportRecords::place($finding) : '[project]'))
            || ($prose['line'] !== null && $prose['line'] !== $finding['line']) || $prose['severity'] !== $finding['severity']) {
            return false;
        }
        $detailed = isset($prose['symbol']);
        $separate = $codec === 'current' && \in_array($surface, ['format:text', 'format:text-detail', 'format:summary', 'show-suppressed'], true) && ($detailed || $surface === 'format:summary');
        $message = $separate ? $finding['message'] : ReportRecords::message($finding, $detailed || $surface === 'format:summary', $codec);
        if ($separate && (($prose['recommendation'] ?? null) !== $finding['recommendation']
            || ($prose['acceptedLevel'] ?? null) !== ReportRecords::baselineText($finding))) {
            return false;
        }
        if ($codec === 'current' && $surface === 'format:github' && $finding['file'] === null && ReportRecords::place($finding) !== '[project]') {
            $message = ReportRecords::place($finding) . ': ' . $message;
        }
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
    public static function rewriteProjection(string $surface, string $text, array $entry, array $before, array $after, string $codec = 'legacy'): string
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
            $lines[$index] = substr($line, 0, $at + 2) . $escape(($codec === 'current' && $after['file'] === null && ReportRecords::place($after) !== '[project]' ? ReportRecords::place($after) . ': ' : '') . ReportRecords::message($after, codec: $codec), false);
        } elseif (isset($entry['fields']['symbol'])) {
            $location = ($after['file'] ?? ($codec === 'current' ? ReportRecords::place($after) : '[project]')) . ($entry['fields']['line'] === null || $after['line'] === null ? '' : ':' . $after['line']);
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
            self::replaceAdvice($lines, $entry, '    ' . ($codec === 'current' ? $after['message'] : ReportRecords::message($after, true)) . '  [' . $after['code'] . ']' . self::extraText($after, $codec, '    '));
        } elseif ($surface === 'format:summary') {
            $location = ($after['file'] ?? ($codec === 'current' ? ReportRecords::place($after) : '[project]')) . ($entry['fields']['line'] === null || $after['line'] === null ? '' : ':' . $after['line']);
            $tag = match ($after['severity']) {
                'error' => 'ERR', 'warning' => 'WRN', default => 'INF',
            };
            $lines[$index] = preg_replace('~\[(?:ERR|WRN|INF)\] ([0-9.]+)  (.*?)  \[~', '[' . $tag . '] $1  ' . $location . '  [', $lines[$index]) ?? throw new GateError('Cannot rewrite a summary location.');
            $suffix = SubjectLevel::of((string) $after['subject']) === 'class' || self::symbol($after) === '' ? '' : ' (' . self::symbol($after) . ')';
            if (preg_match('~^\s*~', $lines[$entry['lines'][1]], $indent) !== 1) {
                throw new GateError('Cannot locate summary detail indentation.');
            }
            self::replaceAdvice($lines, $entry, $indent[0] . $after['code'] . ': ' . ($codec === 'current' ? $after['message'] : ReportRecords::message($after, true)) . $suffix . self::extraText($after, $codec, $indent[0]));
        } else {
            $suffix = self::symbol($after) === '' ? '' : ' (' . self::symbol($after) . ')';
            $lines[$index] = ($after['file'] ?? ($codec === 'current' ? ReportRecords::place($after) : '[project]')) . ($entry['fields']['line'] === null || $after['line'] === null ? '' : ':' . $after['line']) . ': ' . $after['severity'] . '[' . $after['code'] . ']: ' . ReportRecords::message($after, codec: $codec) . $suffix;
        }
        return implode("\n", $lines);
    }

    /** @param array<string,mixed> $finding */
    private static function extraText(array $finding, string $codec, string $indent): string
    {
        if ($codec !== 'current') {
            return '';
        }
        $text = $finding['recommendation'] === null ? '' : "\n" . $indent . 'Recommendation: ' . $finding['recommendation'];
        $baseline = ReportRecords::baselineText($finding);
        return $text . ($baseline === null ? '' : "\n" . $indent . $baseline);
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

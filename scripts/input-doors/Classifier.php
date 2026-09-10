<?php

declare(strict_types=1);

/**
 * The verdict of the silent-acceptance oracle, as a pure function of raw
 * observations plus one handwritten declaration.
 *
 * The rule the whole round rests on: `SPEAKS` is never derived from a
 * difference between texts. A product that prints back the value it was given
 * differs from every baseline by construction, and a product that prints
 * `No violations found.` prints the same thing whether the namespace is absent
 * or merely clean. So the signal is declared, and three guards stand on the
 * declaration — specificity against `H`, `A` and `H0`, silence on the frozen
 * pre-cure snapshot, and a two-sided claim about the echo.
 */

namespace Qualimetrix\InputDoors;

/** One raw side of a probe: what the product did, before any interpretation. */
final class Observation
{
    public function __construct(
        public readonly int $exit,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly bool $fileExists = false,
        public readonly bool $dirExists = false,
    ) {}

    public function text(): string
    {
        return $this->stdout . "\n--stderr--\n" . $this->stderr;
    }
}

/**
 * The knobs a control flips to plant one flaw at a time. Every default is the
 * shipped behaviour; a control that has to edit the classifier itself proves
 * nothing about the classifier that ships.
 *
 * `echoClaimChecked` is the one knob the stand itself turns, and the reason is
 * measured: the `echoes` column is a claim about the tree under test, and on
 * the frozen pre-cure snapshot the value is not echoed because the product said
 * nothing at all. Enforcing the claim there returned `ECHO NOT FOUND` **before**
 * the refusal and specificity steps, so six of the doors this round cured read
 * as a broken declaration instead of as the silence they were — and a cure row
 * whose signal already fired before the cure would have been hidden behind the
 * same outcome.
 */
final class ClassifierOptions
{
    /**
     * @param list<string> $specificitySides
     */
    public function __construct(
        public readonly bool $echoExcision = true,
        public readonly bool $textDiffFallback = false,
        public readonly array $specificitySides = ['H', 'A', 'H0'],
        public readonly bool $refusalBeforeObservability = true,
        public readonly bool $echoClaimChecked = true,
    ) {}
}

final class Verdict
{
    public const string REFUSES = 'REFUSES';
    public const string SPEAKS = 'SPEAKS';
    public const string SILENT = 'SILENT';
    public const string NOT_OBSERVABLE = 'NOT OBSERVABLE';

    public function __construct(
        public readonly string $outcome,
        public readonly string $decidedBy,
        public readonly string $note = '',
    ) {}

    public function isRed(): bool
    {
        return !\in_array($this->outcome, [self::REFUSES, self::SPEAKS, self::SILENT, self::NOT_OBSERVABLE], true);
    }
}

final class Classifier
{
    /** The one framing the product's own refusal ladder prints. */
    public const string REFUSAL_FRAMING = 'Configuration error:';

    public function __construct(
        private readonly Normalizer $normalizer,
        private readonly ClassifierOptions $options = new ClassifierOptions(),
    ) {}

    /**
     * @param array<string, Observation> $sides keyed M, H, A and optionally H0
     */
    public function classify(array $sides, Probe $probe, string $observable): Verdict
    {
        $kind = self::observableKind($observable);
        $mismatch = $this->signalObservableMismatch($probe->signal, $kind, $observable);

        if ($mismatch !== null) {
            return new Verdict('SIGNAL/OBSERVABLE MISMATCH', 'declaration', $mismatch);
        }

        $required = ['M', 'H', 'A'];

        if ($probe->hasHitEmpty()) {
            $required[] = 'H0';
        }

        foreach ($required as $side) {
            if (!isset($sides[$side])) {
                return new Verdict('INCOMPLETE TRIPLE', 'stand', 'missing side ' . $side);
            }
        }

        $texts = [];

        foreach ($sides as $side => $observation) {
            $texts[$side] = $this->prepared($observation, $probe, $observable);
        }

        if ($probe->declaresSignal()) {
            $echoProblem = $this->options->echoClaimChecked
                ? $this->echoDisagreement($probe, $sides['M'])
                : null;

            if ($echoProblem !== null) {
                return new Verdict('ECHO NOT FOUND', 'declaration', $echoProblem);
            }

            foreach ($this->options->specificitySides as $side) {
                if (!isset($sides[$side])) {
                    continue;
                }

                if ($this->fires($probe->signal, $sides[$side], $texts[$side])) {
                    return new Verdict('SIGNAL NOT SPECIFIC', 'declaration', 'the declared signal fires on side ' . $side);
                }
            }
        }

        // Refusal is exit 3 *plus the product's own refusal framing*, and the
        // framing is looked for on both streams: a machine-readable format
        // renders the refusal as its document on stdout. Requiring the framing
        // is what keeps Symfony's "Not enough arguments" — which the A side of
        // every required argument produces, at exit 3 — from making a genuinely
        // refusing door read as unspecific.
        $refuses = $this->fires('refusal', $sides['M'], $texts['M'])
            && !$this->fires('refusal', $sides['H'], $texts['H'])
            && !$this->fires('refusal', $sides['A'], $texts['A']);

        // Refusal is decided before observability, and the order is the point:
        // the triple of exit codes witnesses on its own. Measured on
        // `--memory-limit`, whose hit is indistinguishable from its baseline
        // after normalization — under the other order a door that demonstrably
        // refuses would be filed as unobservable and inflate the remainder.
        if ($refuses && $this->options->refusalBeforeObservability) {
            return new Verdict(Verdict::REFUSES, 'exit codes plus the product refusal framing');
        }

        if (!$this->hitObservable($kind, $sides, $texts)) {
            return new Verdict(Verdict::NOT_OBSERVABLE, $kind, 'H and A are indistinguishable on this observable, so a miss cannot be told from a hit');
        }

        if ($refuses) {
            return new Verdict(Verdict::REFUSES, 'exit codes plus the product refusal framing');
        }

        if ($probe->declaresSignal() && $this->fires($probe->signal, $sides['M'], $texts['M'])) {
            return new Verdict(Verdict::SPEAKS, 'declared signal fired on M');
        }

        if ($this->options->textDiffFallback && !$probe->declaresSignal() && $texts['M'] !== $texts['A']) {
            return new Verdict(Verdict::SPEAKS, 'text difference (fallback)');
        }

        return new Verdict(
            Verdict::SILENT,
            $probe->declaresSignal() ? 'declared, not fired' : 'declared none',
        );
    }

    /**
     * The forms one declared value can take in a text: as written, and as a
     * machine-readable format escapes it. A namespace is the case that forced
     * this — `Fixture\NoSuch` is written `Fixture\\NoSuch` inside JSON, and a
     * guard that only looked for the literal called every JSON observable a
     * missing echo.
     *
     * @return list<string>
     */
    public static function echoForms(string $value): array
    {
        if ($value === '' || $value === 'none') {
            return [];
        }

        $forms = [$value];
        $encoded = json_encode($value);

        if ($encoded !== false) {
            $forms[] = trim($encoded, '"');
        }

        return array_values(array_unique(array_filter($forms, static fn(string $form): bool => $form !== '')));
    }

    public static function observableKind(string $observable): string
    {
        if (str_starts_with($observable, 'file:')) {
            return 'file';
        }

        if (str_starts_with($observable, 'dir:')) {
            return 'dir';
        }

        if ($observable === 'exitcode') {
            return 'exitcode';
        }

        return 'text';
    }

    /**
     * Rendering the value the way the text renders it, then cutting: the
     * literal from the declaration never appears, because paths are printed
     * absolute and then tokenized before the observation is stored.
     */
    public function excise(string $text, string $value, Probe $probe): string
    {
        if (!$this->options->echoExcision || $value === '' || $value === 'none') {
            return $text;
        }

        foreach (self::echoForms($value) as $form) {
            $text = str_replace($form, '', $text);
        }

        return $text;
    }

    /** @param array<string, Observation> $sides
     * @param array<string, string> $texts
     */
    private function hitObservable(string $kind, array $sides, array $texts): bool
    {
        return match ($kind) {
            'exitcode' => $sides['H']->exit !== $sides['A']->exit,
            'file' => $sides['H']->fileExists !== $sides['A']->fileExists,
            'dir' => $sides['H']->dirExists !== $sides['A']->dirExists,
            default => $texts['H'] !== $texts['A'],
        };
    }

    private function prepared(Observation $observation, Probe $probe, string $observable): string
    {
        $surface = self::observableKind($observable) === 'text' ? $observable : 'exitcode';
        $stdout = $this->normalizer->normalize($surface, $observation->stdout);
        $text = $stdout . "\n--stderr--\n" . $observation->stderr;
        $text = $this->excise($text, $probe->miss, $probe);
        $text = $this->excise($text, $probe->hit, $probe);

        return $this->excise($text, $probe->hitEmpty, $probe);
    }

    /** Guard 3: the `echoes` column is checked in both directions. */
    private function echoDisagreement(Probe $probe, Observation $miss): ?string
    {
        $found = false;

        foreach (self::echoForms($probe->miss) as $form) {
            if (str_contains($miss->text(), $form)) {
                $found = true;

                break;
            }
        }

        if ($probe->echoes && !$found) {
            return 'echoes=yes but the miss value does not appear in M';
        }

        if (!$probe->echoes && $found) {
            return 'echoes=no but the miss value appears in M';
        }

        return null;
    }

    private function fires(string $signal, Observation $observation, string $preparedText): bool
    {
        if ($signal === 'refusal') {
            return $observation->exit === 3 && str_contains($observation->text(), self::REFUSAL_FRAMING);
        }

        if (str_starts_with($signal, 'exit:')) {
            return $observation->exit === (int) substr($signal, 5);
        }

        if (str_starts_with($signal, 'stderr:')) {
            return preg_match(substr($signal, 7), $observation->stderr) === 1;
        }

        if (str_starts_with($signal, 'stdout:')) {
            return preg_match(substr($signal, 7), $observation->stdout) === 1;
        }

        if (str_starts_with($signal, 'finding:')) {
            $channel = explode('@', substr($signal, 8), 2)[0];

            return str_contains($preparedText, $channel);
        }

        return false;
    }

    private function signalObservableMismatch(string $signal, string $kind, string $observable): ?string
    {
        if (str_starts_with($signal, 'finding:')) {
            $format = explode('@', substr($signal, 8), 2)[1] ?? '';

            if ($kind !== 'text') {
                return 'a finding signal needs a text observable';
            }

            if ('format:' . $format !== $observable) {
                return \sprintf('the signal names format "%s" but the observable is "%s"', $format, $observable);
            }

            if (\in_array($format, ['summary', 'health', 'dot'], true)) {
                return \sprintf('format "%s" does not carry findings', $format);
            }
        }

        if ((str_starts_with($signal, 'stdout:') || str_starts_with($signal, 'stderr:')) && $kind !== 'text' && $kind !== 'exitcode') {
            return 'a text signal over a non-text observable';
        }

        return null;
    }
}

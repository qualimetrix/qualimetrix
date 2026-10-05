<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Refusal;

use RuntimeException;
use Throwable;

/**
 * A configuration refusal caused by user input: a key, a value, a file, a
 * selector, a form — anything the configuration's author is responsible for.
 *
 * The carried kind for configuration refusals. There is no
 * public constructor: every refusal is one of the four named forms below —
 * `at()`, `aboutDocument()`, `aboutInput()` and `acrossLayers()` — each built
 * through a named factory rather than a shared one with a boolean flag.
 *
 * The per-source factories that follow the forms are shorthands, not a
 * further form: each delegates to `at()`, `aboutDocument()` or `aboutInput()`
 * with the origin built here. A throw site that names its source literally
 * would otherwise have to import {@see ConfigurationOrigin} and
 * {@see ConfigurationSource} for no reason but to assemble a constant — three
 * type dependencies where one would do. The general forms stay public
 * for the sites that compute their source or forward an origin they were given.
 *
 * ClassRank measures how much of the graph flows into a type, and for the one
 * carried configuration kind that number counts the places the product refuses
 * bad input instead of accepting it. CLI doors use this carrier instead of
 * folding empty values into defaults. Splitting the kind to lower the rank would
 * buy a number and a second way to spell a configuration refusal.
 */
final class ConfigurationRefusal extends RuntimeException implements RefusalInterface
{
    /** @param list<ConfigurationOrigin> $contributors */
    private function __construct(
        private readonly ConfigurationOrigin $origin,
        private readonly ?RefusedPosition $position,
        private readonly string $summary,
        ?Throwable $previous = null,
        private readonly array $contributors = [],
    ) {
        parent::__construct($summary, 0, $previous);
    }

    /** A refusal addressed by a key position: an unknown key, a value of the wrong shape. */
    public static function at(
        ConfigurationOrigin $origin,
        RefusedPosition $position,
        string $summary,
        ?Throwable $previous = null,
    ): self {
        return new self($origin, $position, $summary, $previous);
    }

    /** A refusal about the document as a whole: the file is missing, unparseable, or has a foreign envelope version. */
    public static function aboutDocument(
        ConfigurationOrigin $origin,
        string $summary,
        ?Throwable $previous = null,
    ): self {
        return new self($origin, null, $summary, $previous);
    }

    /** A refusal about a value that has no position in a document: an argument, a selector, a path. */
    public static function aboutInput(
        ConfigurationOrigin $origin,
        string $summary,
        ?Throwable $previous = null,
    ): self {
        return new self($origin, null, $summary, $previous);
    }

    /**
     * A refusal about a value composed from several layers, or a relation
     * between values different layers wrote: every contributing layer is at
     * fault, and each is named. The origin is {@see ConfigurationSource::Resolved}.
     *
     * @param non-empty-list<ConfigurationOrigin> $contributors lowest precedence first
     */
    public static function acrossLayers(
        array $contributors,
        ?RefusedPosition $position,
        string $summary,
        ?Throwable $previous = null,
    ): self {
        return new self(ConfigurationOrigin::of(ConfigurationSource::Resolved), $position, $summary, $previous, $contributors);
    }

    /**
     * A key position inside a configuration file.
     *
     * @param string $path the file the key was written in
     */
    public static function atConfigFileKey(
        string $path,
        RefusedPosition $position,
        string $summary,
        ?Throwable $previous = null,
    ): self {
        return self::at(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, $path), $position, $summary, $previous);
    }

    /**
     * A key position inside a preset.
     *
     * @param string $preset the preset the key was written in
     */
    public static function atPresetKey(
        string $preset,
        RefusedPosition $position,
        string $summary,
        ?Throwable $previous = null,
    ): self {
        return self::at(ConfigurationOrigin::of(ConfigurationSource::Preset, $preset), $position, $summary, $previous);
    }

    /** A key position of a built-in value no layer wrote. */
    public static function atDefaultsKey(
        RefusedPosition $position,
        string $summary,
        ?Throwable $previous = null,
    ): self {
        return self::at(ConfigurationOrigin::of(ConfigurationSource::Defaults), $position, $summary, $previous);
    }

    /**
     * A key position in the merged document, where no single file or option can
     * be named any more. The locator trails and defaults to null because
     * {@see ConfigurationSource::Resolved} has none by construction; a caller
     * that still holds the resolved key name passes it as a diagnostic hint.
     */
    public static function atResolvedKey(
        RefusedPosition $position,
        string $summary,
        ?string $key = null,
        ?Throwable $previous = null,
    ): self {
        return self::at(ConfigurationOrigin::of(ConfigurationSource::Resolved, $key), $position, $summary, $previous);
    }

    /**
     * A configuration file that could not be read, parsed, or accepted as a whole.
     *
     * @param string $path the file itself
     */
    public static function aboutConfigFileDocument(
        string $path,
        string $summary,
        ?Throwable $previous = null,
    ): self {
        return self::aboutDocument(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, $path), $summary, $previous);
    }

    /** A preset that could not be read, parsed, or accepted as a whole. */
    public static function aboutPresetDocument(
        string $preset,
        string $summary,
        ?Throwable $previous = null,
    ): self {
        return self::aboutDocument(ConfigurationOrigin::of(ConfigurationSource::Preset, $preset), $summary, $previous);
    }

    /** A key position inside a baseline file. */
    public static function atBaselineFileKey(
        string $path,
        RefusedPosition $position,
        string $summary,
        ?Throwable $previous = null,
    ): self {
        return self::at(ConfigurationOrigin::of(ConfigurationSource::BaselineFile, $path), $position, $summary, $previous);
    }

    /** A baseline file that could not be read, parsed, or accepted as a whole. */
    public static function aboutBaselineFileDocument(
        string $path,
        string $summary,
        ?Throwable $previous = null,
    ): self {
        return self::aboutDocument(
            ConfigurationOrigin::of(ConfigurationSource::BaselineFile, $path),
            $summary,
            $previous,
        );
    }

    /**
     * A document a command-line argument pointed at, refused as a whole — the
     * argument names it, so the argument is the locator.
     */
    public static function aboutCommandLineDocument(
        string $option,
        string $summary,
        ?Throwable $previous = null,
    ): self {
        return self::aboutDocument(
            ConfigurationOrigin::of(ConfigurationSource::CommandLine, $option),
            $summary,
            $previous,
        );
    }

    /**
     * A command-line value with no position in any document.
     *
     * @param string $option the option or argument that carried it
     */
    public static function aboutCommandLineInput(
        string $option,
        string $summary,
        ?Throwable $previous = null,
    ): self {
        return self::aboutInput(ConfigurationOrigin::of(ConfigurationSource::CommandLine, $option), $summary, $previous);
    }

    /**
     * A merged value with no position in any document. The locator trails for
     * the same reason as in {@see self::atResolvedKey()}, and naming the key
     * there does not give the refusal a position: the merge no longer tells
     * whether a file or a command-line option wrote the value.
     */
    public static function aboutResolvedInput(
        string $summary,
        ?string $key = null,
        ?Throwable $previous = null,
    ): self {
        return self::aboutInput(ConfigurationOrigin::of(ConfigurationSource::Resolved, $key), $summary, $previous);
    }

    /**
     * Every source the refusal names: the contributing layers of an
     * {@see self::acrossLayers()} refusal, otherwise the one origin.
     *
     * @return non-empty-list<ConfigurationOrigin>
     */
    public function sources(): array
    {
        return $this->contributors === [] ? [$this->origin] : $this->contributors;
    }

    /** Null for the {@see self::aboutDocument()}/{@see self::aboutInput()} forms — there is no position by construction. */
    public function position(): ?RefusedPosition
    {
        return $this->position;
    }

    /**
     * One sentence, unframed, without an "Configuration error:" prefix and
     * without a code. Framing belongs to presentation (stage 01) — it must not
     * happen here.
     */
    public function summary(): string
    {
        return $this->summary;
    }
}

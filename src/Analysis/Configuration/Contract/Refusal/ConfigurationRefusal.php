<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Refusal;

use RuntimeException;
use Throwable;

/**
 * A configuration refusal caused by user input: a key, a value, a file, a
 * selector, a form — anything the configuration's author is responsible for.
 *
 * The single carried kind for exit code 3 (see `00-overview.md`). There is no
 * public constructor: every refusal is one of the three named forms below, and
 * every form is built through a named factory rather than a shared one with a
 * boolean flag, so an impossible combination (a closed position with nothing
 * accepted) cannot be constructed at all.
 */
final class ConfigurationRefusal extends RuntimeException
{
    private function __construct(
        private readonly ConfigurationOrigin $origin,
        private readonly ?RefusedPosition $position,
        private readonly string $summary,
        ?Throwable $previous = null,
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

    public function origin(): ConfigurationOrigin
    {
        return $this->origin;
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

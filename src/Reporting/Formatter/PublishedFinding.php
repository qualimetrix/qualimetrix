<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter;

use Qualimetrix\Analysis\Finding\Contract\Finding;

/**
 * What a published finding's text fields hold, surface by surface.
 *
 * A finding has two texts, `Finding::$message` and the optional
 * `Finding::$recommendation`, plus the accepted level a measured breach
 * carries (ADR 0017). Every surface publishes one of three compositions of
 * them, and a key names the same composition wherever a document uses it:
 *
 * | composition                    | where                                               | key                         |
 * |--------------------------------|-----------------------------------------------------|-----------------------------|
 * | the message, the recommendation | `json` (violations and topIssues), `html`, `suppressed` | `message`, `recommendation` |
 * | {@see self::annotatedMessage()} | `sarif`, `gitlab`, `checkstyle`, `github`, flat `text` | `message.text`, `description`, `message`, the annotation text |
 * | {@see self::advice()}          | `text --detail`, `summary` (details and top issues) | prose, no key               |
 *
 * The structured surfaces have room for both texts, so they publish both and
 * leave the accepted level to its own field. An interchange format has one
 * free-text slot, so the level rides in it. Prose for a person leads with the
 * recommendation when there is one. `metrics` and `health` publish no finding
 * text at all.
 *
 * `scripts/finding-gate/PublishedVocabulary.php` is not this: it tells the
 * equivalence gate which key to look for on a diff line. This class is what
 * the formatters call, so a surface cannot publish a composition it does not
 * name.
 */
final class PublishedFinding
{
    /**
     * The message with the accepted level of a measured breach appended — for
     * a surface with a single free-text slot.
     */
    public static function annotatedMessage(Finding $finding): string
    {
        return $finding->message . self::breachSuffix($finding);
    }

    /**
     * The recommendation when there is one, else the message, with the
     * accepted level appended — prose addressed to a person.
     */
    public static function advice(Finding $finding): string
    {
        return $finding->getDisplayMessage() . self::breachSuffix($finding);
    }

    /**
     * The dependency edge a finding is about, as the structured surfaces
     * publish it under `edge`.
     *
     * @return ?array{target: string, type?: string}
     */
    public static function edge(Finding $finding): ?array
    {
        if ($finding->dependencyTarget === null) {
            return null;
        }

        $target = $finding->dependencyTarget->toCanonical();
        if ($finding->dependencyType === null) {
            return ['target' => $target];
        }

        return [
            'type' => $finding->dependencyType->value,
            'target' => $target,
        ];
    }

    /** " (accepted at 25, now 31)" on a measured breach, '' otherwise (ADR 0017). */
    private static function breachSuffix(Finding $finding): string
    {
        $breach = AcceptedLevelNarrator::describe($finding);

        return $breach === null ? '' : \sprintf(' (%s)', $breach);
    }
}

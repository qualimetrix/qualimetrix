<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\RuleVocabulary\Fixtures;

/**
 * The authored forms of a threshold directive, one per declaration.
 *
 * Two measures of the same population — the product's extractor and
 * `QmxDirectiveAudit\ThresholdDirectiveScan` — are only worth running as a pair
 * if they are written differently, and only usable as a pair if they answer the
 * same way. This file is the second half of that: every form below is one a
 * developer can write, and both measures must reach the same verdict on it.
 * `src/` cannot stand in for this file — its thirty-odd sites spell their
 * channels with lowercase letters, dots and hyphens and nothing else, so a
 * character class narrowed anywhere would go unnoticed by a measurement of the
 * live tree.
 *
 * Nothing here is formatted or analysed by the tooling: the text is the
 * subject, and a fixer that tidies a docblock star would silently rewrite the
 * case it is standing for. The forms with no directive are as load-bearing as
 * the ones with one — "recognised by neither" is a claim, not an absence.
 *
 * Every target is spelled differently from every other, so a case can be found
 * in this source by its own name.
 */
final class AuthoredThresholdForms
{
    /**
     * @qmx-threshold plain.simple 20
     */
    public function plain(): void {}

    // The tag with no space between it and the docblock star. The product's
    // pattern has no left boundary, so this is honoured — and a measure that
    // demanded a whole word would call the site missing. The explanation sits
    // outside the docblock because the style fixer inserts a blank line before
    // a tag that follows prose, which would rewrite the case itself.
    /**
     *@qmx-threshold glued.star 20
     */
    public function gluedToTheStar(): void {}

    /**
     * A word that merely begins with the tag addresses nothing.
     *
     * @qmx-thresholdx suffixed.word 20
     */
    public function suffixedWord(): void {}

    /**
     * Documentation, not a directive: `@qmx-threshold backticked.target 20`.
     */
    public function backticked(): void {}

    /**
     * A backtick region spanning lines, before a real directive:
     * `one
     *  two
     *  three`
     *
     * @qmx-threshold after.backticks 20
     */
    public function afterMultilineBacktickRegion(): void {}

    public function outsideADocblock(): void
    {
        // @qmx-threshold outside.docblock 20
    }

    /**
     * The values run to the end of the line, so the second tag is text.
     *
     * @qmx-threshold first.of.two 20 @qmx-threshold second.of.two 30
     */
    public function twoOnOneLine(): void {}

    /**
     * The target stops at the first character a channel is not made of, and
     * what follows it is then not separated by a space, so there are no values.
     *
     * @qmx-threshold paren.call(x) 30
     */
    public function targetCutAtACall(): void {}

    /**
     * A target that begins with a character no channel is made of addresses
     * nothing at all.
     *
     * @qmx-threshold (paren.wrapped) 30
     */
    public function targetWrappedInParens(): void {}

    /**
     * @qmx-threshold class.star.* 20
     */
    public function targetWithAStar(): void {}

    /**
     * @qmx-threshold class.hash#code 20
     */
    public function targetWithAHash(): void {}

    /**
     * @qmx-threshold class.colon:level 20
     */
    public function targetWithAColon(): void {}

    /**
     * @qmx-threshold class.digit9 20
     */
    public function targetWithADigit(): void {}

    /**
     * @qmx-threshold class_underscore 20
     */
    public function targetWithAnUnderscore(): void {}

    /**
     * @qmx-threshold Class.Upper 20
     */
    public function targetWithACapital(): void {}
}

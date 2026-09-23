<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract\Directive;

use LogicException;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;

/**
 * An authored directive the extractor read and refused to carry out.
 *
 * It travels on a {@see Suppression} because the alternative — dropping it —
 * is the defect: a form that never reaches the directive store is judged by
 * nothing, neither the configuration error a `check` reports nor the verdict
 * `bin/qmx directives` prints. Carrying it is the same move the channel
 * grammars already make when they admit `:` and `#` so a misaddressed channel
 * can be **captured and then refused by name** rather than silently narrowed.
 * A refused `@qmx-threshold` travels the same way: it retunes nothing, so the
 * override list has no place for it.
 *
 * A refused directive filters nothing. {@see Suppression::matches()} answers
 * `false` for every channel, so the refusal cannot be mistaken for a
 * suppression of the thing it names.
 *
 * The two strings are the same fact for two readers. `$form` is the identity a
 * report groups and prints directives by — the vocabulary
 * {@see SuppressionType} supplies for the suppression forms, and
 * {@see self::THRESHOLD_FORM} for the threshold — and `$tag` is what stands in
 * the source. They differ only for a tag nobody reads, which has no form to be
 * one of; an unreadable tag reported as one of the four real ones would send
 * its author to a line that does not say that.
 */
final readonly class DirectiveRefusal
{
    /** The form a threshold directive is reported under, beside the three of {@see SuppressionType}. */
    public const string THRESHOLD_FORM = 'threshold';

    private const string TAG_PREFIX = '@qmx-';

    /**
     * The tag names this tool reads, without the prefix, and the form each is
     * reported under. A tag missing from here is one nobody reads.
     *
     * @var array<non-empty-string, non-empty-string>
     */
    private const array FORM_OF_TAG = [
        'ignore' => 'symbol',
        'ignore-next-line' => 'next-line',
        'ignore-file' => 'file',
        'threshold' => self::THRESHOLD_FORM,
    ];

    private function __construct(
        public DirectiveRefusalReason $reason,
        public string $form,
        public string $tag,
    ) {}

    /** @param non-empty-string $form the tag as authored, without its `@qmx-` prefix */
    public static function formNotRecognised(string $form): self
    {
        return new self(DirectiveRefusalReason::FormNotRecognised, $form, self::TAG_PREFIX . $form);
    }

    /**
     * Why a docblock tag no grammar read is refused, most fundamental first: a
     * name nobody reads, a missing argument, and last the placement — the only
     * one of the three that is not visible in the tag itself. A threshold in a
     * line or block comment is refused before this is asked, by
     * {@see self::thresholdOutsideDocblock()}: the carrier is wrong whatever
     * the tag says.
     *
     * The suppression grammars read every tag of their own forms that carries
     * an argument, so of the known tags only a threshold can arrive here with
     * one: a tag its reader did not answer for, on a node no threshold binds to.
     *
     * @param non-empty-string $tagName the tag as authored, without its `@qmx-` prefix
     * @param string $argument what stands after the tag on its line, empty when nothing does
     */
    public static function ofUnreadTag(string $tagName, string $argument): self
    {
        $form = self::FORM_OF_TAG[$tagName] ?? null;
        if ($form === null) {
            return self::formNotRecognised($tagName);
        }

        return match (true) {
            $argument === '' => self::namesNoTarget($form),
            $form === self::THRESHOLD_FORM => self::thresholdWithNoDeclarationToBind(),
            default => throw new LogicException(\sprintf('The "%s" grammar left a tag with an argument unread', $tagName)),
        };
    }

    /** @param string $form one of {@see SuppressionType}'s values or {@see self::THRESHOLD_FORM} */
    public static function namesNoTarget(string $form): self
    {
        return new self(DirectiveRefusalReason::NamesNoTarget, $form, self::tagOf($form));
    }

    public static function noDeclarationToBind(): self
    {
        return new self(DirectiveRefusalReason::NoDeclarationToBind, SuppressionType::Symbol->value, self::tagOf(SuppressionType::Symbol->value));
    }

    public static function thresholdWithNoDeclarationToBind(): self
    {
        return new self(DirectiveRefusalReason::NoDeclarationToBind, self::THRESHOLD_FORM, self::tagOf(self::THRESHOLD_FORM));
    }

    public static function thresholdOutsideDocblock(): self
    {
        return new self(DirectiveRefusalReason::ThresholdOutsideDocblock, self::THRESHOLD_FORM, self::tagOf(self::THRESHOLD_FORM));
    }

    /**
     * What to tell the author, in the words of the form itself.
     *
     * The rest of the directive vocabulary has its refusals worded by
     * `DirectiveAddressability`, and these are here instead because they are
     * the ones that owe nothing to the run: they are wrong against the grammar
     * of the tag or against the place it was written, not against the channels
     * this run happens to have.
     *
     * @param string $argument the channel or rule as authored, empty when none was written
     */
    public function describe(string $argument): string
    {
        $authored = trim($this->tag . ' ' . $argument);
        $isThreshold = $this->form === self::THRESHOLD_FORM;

        return match ($this->reason) {
            DirectiveRefusalReason::FormNotRecognised => \sprintf(
                'Directive "%s" is not a tag this tool reads. The tags are @qmx-ignore, @qmx-ignore-next-line,'
                . ' @qmx-ignore-file and @qmx-threshold; the first two name a channel before the reason.',
                $authored,
            ),
            DirectiveRefusalReason::NamesNoTarget => $isThreshold
                ? \sprintf(
                    'Directive "%s" names no rule. Write the rule and its values on the tag\'s own line:'
                    . ' @qmx-threshold <rule> <value>, or warning=<n> error=<n>.',
                    $authored,
                )
                : \sprintf(
                    'Directive "%s" names no channel. Write the channel on the tag\'s own line, before the'
                    . ' reason — or "*" for every channel.',
                    $authored,
                ),
            DirectiveRefusalReason::NoDeclarationToBind => $isThreshold
                ? \sprintf(
                    'Threshold "%s" is written where no declaration it can retune is measured, so it retunes'
                    . ' nothing. Move it into the docblock of the class, method or function it is about.',
                    $authored,
                )
                : \sprintf(
                    'Suppression "%s" is written where no declaration is measured, so it binds to nothing.'
                    . ' Write @qmx-ignore-next-line to silence the line below it, or move the tag onto the class,'
                    . ' method or function it is about.',
                    $authored,
                ),
            DirectiveRefusalReason::ThresholdOutsideDocblock => \sprintf(
                'Threshold "%s" is written in a line or block comment, and a threshold is read only from a'
                . ' docblock. Write it in the /** */ docblock of the class, method or function it retunes.',
                $authored,
            ),
        };
    }

    /** @return non-empty-string */
    private static function tagOf(string $form): string
    {
        $tagName = array_search($form, self::FORM_OF_TAG, true);
        if ($tagName === false) {
            throw new LogicException(\sprintf('"%s" is not a directive form', $form));
        }

        return self::TAG_PREFIX . $tagName;
    }
}

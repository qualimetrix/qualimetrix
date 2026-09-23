<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract\Directive;

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
 *
 * A refused directive filters nothing. {@see Suppression::matches()} answers
 * `false` for every channel, so the refusal cannot be mistaken for a
 * suppression of the thing it names.
 *
 * The two strings are the same fact for two readers. `$form` is the identity a
 * report groups and prints directives by — the vocabulary
 * {@see SuppressionType} supplies for the forms that are read — and `$tag` is
 * what stands in the source. They differ for a tag nobody reads, which has no
 * form to be one of; an unreadable tag reported as one of the four real ones
 * would send its author to a line that does not say that.
 */
final readonly class DirectiveRefusal
{
    private const string TAG_PREFIX = '@qmx-';

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

    public static function noDeclarationToBind(): self
    {
        return new self(DirectiveRefusalReason::NoDeclarationToBind, SuppressionType::Symbol->value, '@qmx-ignore');
    }

    /**
     * What to tell the author, in the words of the form itself.
     *
     * The rest of the directive vocabulary has its refusals worded by
     * `DirectiveAddressability`, and these two are here instead because they
     * are the two that owe nothing to the run: a tag nobody reads and a
     * placement nothing binds to are wrong against the grammar, not against
     * the channels this run happens to have.
     *
     * @param string $channel the channel as authored, empty when none was written
     */
    public function describe(string $channel): string
    {
        $authored = trim($this->tag . ' ' . $channel);

        return match ($this->reason) {
            DirectiveRefusalReason::FormNotRecognised => \sprintf(
                'Directive "%s" is not a tag this tool reads. The tags are @qmx-ignore, @qmx-ignore-next-line,'
                . ' @qmx-ignore-file and @qmx-threshold; the first two name a channel before the reason.',
                $authored,
            ),
            DirectiveRefusalReason::NoDeclarationToBind => \sprintf(
                'Suppression "%s" is written where no declaration is measured, so it binds to nothing.'
                . ' Write @qmx-ignore-next-line to silence the line below it, or move the tag onto the class,'
                . ' method or function it is about.',
                $authored,
            ),
        };
    }
}

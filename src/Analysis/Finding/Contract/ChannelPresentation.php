<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

/**
 * Display text and documentation location for one channel.
 *
 * Both halves are facts owned elsewhere and only assembled here — see
 * {@see ChannelPresentationInterface}. `$docsPage` is a path relative to
 * `website/docs/` (e.g. `rules/complexity.md`), the same value a rule
 * declares via its `DOCS_PAGE` constant
 * ({@see \Qualimetrix\Analysis\Finding\Contract\Rule\RuleDocsPageReader}); building a
 * site URL out of it — choosing a base and rewriting the `.md` extension —
 * is a Reporting-side concern, not this capability's.
 */
final readonly class ChannelPresentation
{
    public function __construct(
        public string $description,
        public string $docsPage,
    ) {}
}

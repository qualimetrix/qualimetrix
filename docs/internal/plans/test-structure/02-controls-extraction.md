# Stage 02 — repository controls leave `tests/`

43 files assert facts about the repository rather than about product behaviour,
and 13 more do so in part. They move out of `tests/`. The verdict per file is in
[`measurement/controls-verdict.tsv`](measurement/controls-verdict.tsv); the
discriminator that produced it is D5 in the overview.

## The root and its shape

```
governance/
├── Documentation/        # docs/ and website/ agree with code
├── GeneratedArtifacts/   # generated files are fresh
├── SourceLayout/         # invariants over the src/ tree
└── TestSuite/            # the suite's own integrity — G1, G2, G3 from stage 01
```

Named by what is guarded, per D4. `controls/` was rejected: "this directory is
about ___" completes as "the checks", which names a role. Each subdirectory
completes with a noun phrase instead.

**This is a new folder and a new namespace, so ADR 0016's three tests apply.**
Name: passes, as above. Co-change: a change to the documentation-agreement rules
touches `Documentation/` and nothing else — verifiable against git history after
the move. Duplication: if the tree were decomposed by subject, none of these
four would have to be copied into every subject; they are genuinely about the
repository as a whole, which is what makes the root legitimate rather than a
second `Utils/`.

**Open question for the owner, not settled here:** whether `SourceLayout/` is
one subject or two — invariants asserted over the *file tree* and invariants
asserted over *declared ownership* (the manifest) may be different subjects that
happen to share a mechanism. ADR 0016's evidence cannot tell two kinds of one
thing from two things. Split only if the owner says they are two.

## Registration — the part that leaks

A moved file must land in **every** set that previously held it. A file in no
set is the failure this project has already paid for. Enumerate and verify each
machine-side:

| Set                    | Current state                                                                                                  | Action                                             |
| ---------------------- | -------------------------------------------------------------------------------------------------------------- | -------------------------------------------------- |
| PHPUnit config         | `phpunit.xml.dist` lists `tests/...` by name                                                                   | new config, or a suite pointing at `governance/`   |
| PHPStan                | `phpstan.neon:13` has `tests`; line 32 ignores `tests/Unit/RuleVocabulary/Fixtures/AuthoredThresholdForms.php` | add the root; **move that ignore with the file**   |
| PHP-CS-Fixer           | `.php-cs-fixer.dist.php` finder has `__DIR__ . '/tests'`                                                       | add the root                                       |
| Composer autoload-dev  | `Qualimetrix\Tests\` → `tests/`; classmap for `TestSupport/ArchitectureStaticAnalysis/Unit/Fixtures/`          | new PSR-4 prefix; move the classmap entry          |
| Composer scripts       | `check:code` runs tests; `check:self` is "what the product says about this repo"                               | see below                                          |
| CI                     | no direct `vendor/bin/phpunit`; everything goes through composer                                               | nothing, once the scripts are right                |
| Architecture inventory | `scripts/generate-modular-architecture-test-inventory.php:181` hardcodes a `tests/` path list                  | **must be edited or `architecture:check` reddens** |

**Which composer group.** The four groups are named by what invalidates them.
`Documentation/` and `GeneratedArtifacts/` fit `check:self`. `SourceLayout/`
and `TestSuite/` do not — they are invalidated by a code change, not by a claim
about the repo. Decide at execution between a fifth group and splitting the root
across two existing ones; state the choice in the stage report. Inheriting
`check:self` by default is the wrong answer for two of the four subdirectories.

**One control already does not run under `composer check`.**
`SuppressionSnapshotFreshnessTest` and the method
`itChecksEveryGeneratedProjectionWithoutWriting` are tagged
`#[Group('live-freshness')]`, which `scripts/phpunit-aggregate.py:36` excludes.
They execute under a bare `composer test` and not under the aggregate. Whatever
happens to them, the neighbouring assertion *about that routing* must be updated
in the same change, or it reddens — a partial repair here leaves the build worse
than either end state.

## Delete rather than move

Some controls duplicate a mechanism that already exists. Deleting is better than
relocating a second implementation of one rule, because two implementations
disagree eventually and nothing says which is right.

- Confirmed duplicates of `suppression-snapshot:check` and
  `architecture:check` — named in
  [`measurement/controls-verdict-notes.md`](measurement/controls-verdict-notes.md),
  subject to the routing caveat above.
- Confirmed **not** duplicates, keep: `DogfoodingTopologyTest`, both
  `*InternalTopologyTest`, `DirectiveAudit*`, `RenameEnumerationRetirementTest`,
  `ChannelRename*`, `PromiseEffect/*` — they overlap partially and cover ground
  the composer script does not.

## Splitting the 13 mixed files

For each, `controls-verdict.tsv` names the control methods by line. The file
stays where it is; the named methods move. Splitting is cheaper than exiling a
file that is half useful — and exiling it whole would delete behavioural
coverage, which is the opposite of this plan's purpose.

## Definition of Done

- Every path with verdict `control` is out of `tests/`; every path with verdict
  `test` is still in it. Checked by script against the TSV, not by eye.
- G2 from stage 01 reports zero orphans across **both** roots, and the count of
  suite-uncovered directories is not above the stage-01 baseline.
- `composer architecture:check` green — this is where the hardcoded inventory
  path bites if it was missed.
- `composer check` green, and the aggregate demonstrably still executes the
  moved controls: compare the executed-test count before and after, and state
  it. A green aggregate that silently stopped running 43 files looks identical
  to success.

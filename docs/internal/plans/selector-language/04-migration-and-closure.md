# Stage 4 — repository migration, documentation, governance, and acceptance

## P8 — migrate every tracked authored selector

**Files:** `qmx.yaml`, `qmx.yaml.example`, applicable presets, all YAML/PHP test
fixtures enumerated by a fresh search, and generated configuration examples.

Each legacy value is classified from its intended current behavior, not converted
mechanically by punctuation:

- current literal exact intent -> `exact`;
- current boundary-prefix intent -> `subtree`;
- current glob or deliberate broader selection -> reviewed `regex`;
- Finder basename-any-depth intent -> explicit path regex;
- character-class hacks such as `[Q]ualimetrix...` -> their actual intended
  regex without preserving the hack.

The migration records before/after counts for repository dogfood findings,
suppressed findings, inert suppressors, unmatched exclusions, and framework
classification. Any delta is explained per selector before acceptance.

## P9 — public documentation and history

**Files:**

- add an ADR under `docs/adr/` defining the explicit forms, ownership, exception
  set, resource/refusal contract, and rejected alternatives
- `CHANGELOG.md` `Breaking` entry with old-to-new YAML and CLI examples and
  consumer migration steps
- canonical English and Russian website configuration/CLI/baseline pages and
  affected Coupling/Architecture pages
- `qmx.yaml.example`, component READMEs, CLI help strings, and any docs found by
  the selector vocabulary sweep

Docs state that regex is full-subject and show `exact`/`subtree` first. They do
not sell PCRE as universally faster. Every English website edit has a matching
Russian edit with identical semantic examples and skip-marker topology.

## P10 — selector-surface governance

**Production/governance files:**

- replace `governance/SelectorSyntax/GlobAlphabetSoleEnumerationTest.php`
- replace or reshape
  `governance/SelectorSyntax/NamespaceMatcherNormalizationSurfaceTest.php`
- add a versioned selector-surface registry under the same guarded subject
- update only required governance fixtures/registrations if filenames change;
  the existing group remains registered
- retain `measurement/selector-surfaces.tsv` as the reviewed derivation input,
  not runtime authority

The registry names every public selector door, object universe, owner, accepted
language, ingress decoder, runtime matcher, attribution/binding consumer, and
documented exception. Governance checks both directions: every registry row
resolves to code/config schema/CLI evidence, and every known selector-construction
site is declared. A new direct `fnmatch()`, user-input `preg_match()`, private
prefix matcher, or selector-shaped configuration/CLI option without a row fails.

Closed-identity and Architecture exceptions are rows, not comments outside the
registry. This is what lets the campaign claim completeness without pretending
that future arbitrary code is discoverable by one grep.

## P11 — acceptance and review

Run, in increasing cost order:

1. focused Core, Configuration, Finding, Reporting, Run, Coupling, Architecture,
   Console, and SelectorSyntax suites after their packages;
2. static analysis and style on changed files;
3. repository dogfood before/after comparison and P0 performance/resource probe;
4. website documentation build and generated-doc consistency checks;
5. `composer check` in full;
6. `git diff --check`, inventory reverse searches, and review of the complete
   diff for stale legacy examples and accidental compatibility paths;
7. mandatory multi-owner code review under the project review procedure, with a
   second round after fixes when the first round finds contract or HIGH issues.

Acceptance refuses these false greens: tests that construct only Core values and
skip public ingress; application tests without attribution/binding parity; a
green partial-run unmatched test that never exercises full-run judgement; Finder
tests that do not prove pruning before descent; Architecture tests that test
validation and runtime against different pattern corpora; and documentation
searches that inspect only English pages.

## Package dependency graph

```text
P0 -> P1 -> P2 -> {P3, P4, P5, P6, P7} -> P8 -> {P9, P10} -> P11
```

P3-P5 are sequential because their Console fixtures overlap. P6 and P7 may run
in parallel only after P5 because their production/test owners are disjoint. P8
owns root configuration and the remaining fixtures after those branches
converge. P9 owns prose; P10 owns governance. P11 changes no product contract
except fixes demanded by verification/review.

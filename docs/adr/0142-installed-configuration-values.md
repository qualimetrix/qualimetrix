# 0142. A Configured Value a Package Installed Is Named, and Judged Only Where It Can Be Removed

**Date:** 2026-10-30
**Status:** Accepted

**Builds on:** [ADR 0139](0139-configuration-imports.md) (imports) and the
project-scope value judgement of `suppression.*` and `discovery.unmatched-exclude`.

## Context

Ten channels report that a configured value matches nothing in the project:
`discovery.unmatched-exclude`, `suppression.unmatched-path`,
`suppression.unmatched-namespace`, `suppression.unmatched-rule-ledger`,
`coupling.unmatched-framework-namespace`, `cohesion.unmatched-exclude-method`,
`architecture.unmatched-type`, `architecture.unmatched-exclude`,
`architecture.unreachable-layer` and `architecture.empty-template`. Each exists
because a value that binds nothing does nothing while its author believes it does
something: a typo, a rename, a move.

Imports made a configuration shared across projects ordinary: a base installed by
Composer (`imports: [vendor/acme/std/qmx.yaml]`). Measured with one such base
writing one stale value per channel: every channel reported in the consumer, and
only `architecture.unmatched-type` said which file wrote the value.

The values do not sit in the same kind of node. Five keys hold lists a later layer
replaces whole, an empty list included — `architecture.layers`,
`coupling.framework_namespaces`, `rules.<name>.exclude_methods` and a rule's
`suppress_paths` and `suppress_namespaces` — so the consumer can take a value away
by writing the list itself. The root `exclude`, `suppress_paths` and
`suppress_namespaces` are sets every layer adds to, where a later layer cannot take
an entry back ([#216](https://github.com/qualimetrix/qualimetrix/issues/216)). A
rule's `suppress_namespace_channels` entry is a named-map entry whose list a later
layer can replace but neither empty nor drop: an entry written `[]` or `~` and a
map written `{}` are refused, and a map written `~` leaves the entries below in place.
With the base's `fail_on: warning`, the consumer's CI failed on a set entry it
could neither fix nor remove; switching the channel off would also hide the
consumer's own stale values.

## Decision

### 1. Every finding of the class names the writers of its value

The message ends with the sources that wrote the value, importer chain included,
as `ConfigurationOrigin::describe()` words them: `Written by configuration file
"vendor/acme/std/qmx.yaml" (imported by configuration file "qmx.yaml").` A set
entry two layers wrote names both. `architecture.unmatched-type` keeps its own
wording, which already names the writer and its path. Identity does not change:
occurrences stay keyed by the value, as the amendment to ADR 0139 decided for
`architecture.unmatched-type`. The writer is in the message, not a new structured
field.

### 2. An unremovable value only an installed package wrote is not judged

A value the consumer cannot remove — an entry of the root `exclude`,
`suppress_paths` or `suppress_namespaces`, or of a rule's
`suppress_namespace_channels` entry — that matches nothing and whose every writer
is a file Composer installed into the analysed project is not judged: no finding,
and the value is listed in
`projectScope.unjudgedValues` beside a `projectScope.reasons` entry of kind
`installed-configuration` that names the file. The channel's judged population
records it at a gate of its own, `installed-writer`, declared a population
boundary rather than a data gap: the run saw everything it needed, the value is
outside what this project answers for, and reports that list data gaps (text,
SARIF, GitHub annotations) must not tell every consumer its run was incomplete.
An exclude selector whose walk was blocked inside the project's own tree is still
reported as unjudgeable: that fact is the project's.

The reason is actionability. The consumer can neither correct such an entry (the
file belongs to the package, and an install overwrites an edit) nor remove it
(#216). Its only responses, switching the channel off or accepting the value in a
baseline, either hide its own stale values or record an inapplicable value as debt.
The author still gets the signal where it can act: in the package's own
repository its configuration is the root, outside any install directory.

A value that binds is judged exactly as before: it does nothing wrong, and listing
every installed suppression that works as "not judged" on every run would only
bury the ones that matter.

Values in the five replaceable lists stay judged, and so do the channels they feed.
Their consumer can replace the list and so remove the value, and their absence can be the consumer's own doing — its
`exclude` can empty a base's template layer — which a skipped judgement would
hide. `architecture.unreachable-layer` and `architecture.empty-template` are
configuration errors; turning them into silence for an installed writer would
turn a consumer's mistake into a passing run.

### 3. "Installed" is where Composer put the file, by positive evidence

A writer is installed when the analysed project's `composer.json` was read with
no issue in or under its `config` (a decoder fallback to `vendor` after an
invalid `config.vendor-dir` is no evidence), its install directory
(`config.vendor-dir`, default `vendor`) is an existing directory that is neither
the project root nor one of its ancestors (`vendor-dir: .` would otherwise make
the root configuration "installed"), and the writer's name leads — through the links above
the install directory, but not through any link below it — to a regular file
inside it that the configuration read. Not following links below the install
directory makes a path repository's symlinked package and its mirrored copy
(`"symlink": false`, `COMPOSER_MIRROR_PATH_REPOS=1`) give the same answer.
Anything else — a command-line value, a project file, an unresolvable name, a
missing or misplaced install directory, an unreadable manifest — is judged as
before. An entry also written by the project is the project's and is judged.

### Alternatives rejected

- **Skip every value an import or preset wrote.** It eats the case imports were
  added for: a project splitting its own configuration would lose typo detection
  in `config/architecture.yaml`.
- **Skip installed values of all ten channels** (the value was written for other
  projects, so one project's absence does not judge its author). It hides a
  consumer-caused absence in the channels whose value the consumer can remove
  anyway, and silences two configuration errors.
- **Decide by key kind alone** (every set and named-map entry, every list). A
  rule's `suppress_namespace_channels` entry reads like a replaceable list but
  cannot be emptied; the test is whether a later layer can remove the value.
- **Judge such values at a lower severity.** The finding stays unactionable, and
  the same rule would stop a typo in a project-owned file from failing
  `fail_on: warning`.
- **Name the source and nothing else.** The consumer of an installed base keeps a
  permanent warning it cannot act on.
- **An authored per-value marker (`{subtree: src/Legacy, optional: true}`).** It
  needs the base author's cooperation and widens the selector grammar of five keys.
- **The importer marks an import as foreign.** An authored answer to a question
  Composer already answers; it remains the extension for bases distributed
  another way.

## Consequences

- When #216 lets a layer remove a set entry, or the channel-entry grammar accepts
  an empty list, the consumer can act on an installed value, and §2 should be
  revisited for that key.
- A base distributed outside Composer — a Git submodule, a copied file, a path
  imported directly rather than through the install directory — is judged like
  project configuration; its consumer keeps `disabled_rules` and a hand-written
  baseline entry.
- Only the working directory's `composer.json` names the install directory. A
  consumer run from a monorepo root against an application's own `vendor/`, an
  install directory moved by `COMPOSER_VENDOR_DIR`, a `vendor-dir` written with
  `~` or `$HOME` (which Composer expands), and bin-plugin trees such as
  `vendor-bin/*/vendor` are not recognised; their entries are judged.
- A built-in preset is named by its preset name and is judged. No built-in preset
  writes a root `exclude`, `suppress_paths` or `suppress_namespaces` value; a test
  keeps it so, because such an entry would reach every consumer and could not be
  removed.
- Whether a writer is installed is a fact about where its file lies, not about how
  it was reached: a file named through a chain of importers, or matched by a
  selector in `imports`, is judged by its own path.
- A root configuration that is itself inside the install directory
  (`--config=vendor/acme/std/qmx.yaml`) has none of its set entries judged.
- A consumer that accepted such a finding in a baseline sees the entry go stale
  once (`baseline.unused-entry`, which fails `--fail-on=warning`); the changelog
  names the cleanup.

# 0078. An Entry the Run Did Not Read Makes It Incomplete

**Date:** 2026-09-22
**Status:** Accepted

## Context

[ADR 0018](0018-analysis-coverage-verdict-and-output-projection.md) made
coverage the canonical pipeline verdict and gave incompleteness its own exit
code. Its population, though, was *every discovered PHP file*: an entry only
entered coverage once discovery had accepted it as a file to analyse, and a
failure was something that happened while parsing or processing that file.

Discovery silently dropped three kinds of entry before that point:

- a symbolic link to a directory, which the walk does not descend into;
- a `*.php` entry that is not a regular file — a FIFO, a socket, a device, a
  link whose target is gone;
- a directory the process may not list.

None of them is a PHP file that failed, so none of them reached coverage at
all. The run's file set was smaller than the tree the caller pointed it at, the
coverage object said the run was complete, and the exit code was 0 or 2 — the
same answer the tool gives for a tree it read in full.

That is the failure mode a static analyser can least afford. A green report on
code nobody read is byte-for-byte the same document as a green report on code
that was read, so the reader cannot tell the two apart, and the cost lands on
whoever trusts the clean result. Every other unread part of the tree — a file
that would not parse — was already loud. These three were the exception, and
nothing about them made the exception safe.

## Decision

**The population of coverage is every entry the run was pointed at, not every
PHP file it managed to open.** An entry that never became a unit of analysis is
a terminal failure like a parse failure, with a typed reason:

| `kind`                 | Entry                                                       |
| ---------------------- | ----------------------------------------------------------- |
| `directory-symlink`    | a symbolic link to a directory, found inside a scanned tree |
| `not-regular-file`     | a `*.php` entry that is not a regular file                  |
| `unreadable-directory` | a directory that could not be listed                        |

They join `parse` and `processing` in `AnalysisFailureKind`, so the published
`kind` vocabulary grows from two values to five. A run holding one of them is
incomplete, its policy result is not authoritative, and it answers **exit 4**
where it used to answer 0 or 2.

Three sub-decisions carry the weight, and each had a cheaper alternative that
was rejected:

**A skipped entry lands among the failures, not in a bucket of its own.** Every
reader that already reports incompleteness — the exit code, the twelve
formats, the text report, the baseline and graph refusals — reads
`coverage->failures`. A separate bucket would have to be taught to each of them
one at a time, and until it was, the skip would be a loss that only a new field
knew about: the same silence under a different name. Rejected alternative: a
second `SkipReason` enum standing beside `AnalysisFailureKind`. It is a 1:1
duplicate of an existing vocabulary whose only purpose is to force every
consumer to learn a second one.

**Incompleteness is a return code, not a warning.** A warning on the error
stream is discarded by every machine consumer by construction — `--format=json`
is read from stdout — and `-q` silences it outright, which means the exact
caller most likely to be automated is the one least likely to see it. The exit
code is the one channel no consumer can leave unread, and exit 4 already meant
"incomplete" under ADR 0018, so the answer needed no new vocabulary at the
process boundary. Rejected alternatives: a stderr warning with exit 0 (the
green report stays indistinguishable), and exit 3 (that code means bad input,
and a tree containing a FIFO is not bad input — the run was asked a question it
answered only in part).

**An entry named on the command line is followed, including a symbolic link to
a directory.** Naming a path is a request to analyse what is behind it; the
protection is against a link *encountered* while walking a tree, which silently
changes which files a run measures, can leave the project root, and does not
terminate on a cycle. So `qmx check src/` reports `src/linked` as a skipped
entry, while `qmx check src/linked` analyses the target and is a complete run.
Rejected alternative: refusing the link in both positions, which would leave no
way to analyse a tree reached through a link at all.

## Consequences

- A tree of ordinary files and directories is unaffected: same findings, same
  exit code, same coverage object.
- A tree containing one of the three entries answers exit 4, and exit 4 takes
  precedence over the warning and error policy codes (ADR 0018). The report
  still carries every violation the run did produce — `check` stays diagnostic
  on incomplete input — so what changes is the verdict, not the payload: a tree
  whose findings used to be answered with 2 is now answered with 4, and the
  policy result that comes with it is not authoritative.
- A consumer that parses `failures[].kind` receives values it has not seen.
  `json` and `metrics` publish them in `coverage.failures[]`; `checkstyle`
  publishes them as `qmx.analysis.<kind>` and `gitlab` as
  `check_name: analysis.<kind>`.
- Baseline lifecycle commands and `graph:export` refuse such a run and write no
  artifact, which follows from ADR 0018 and is not new here — but the set of
  trees that triggers the refusal is larger now.
- ADR 0018's "every discovered PHP file has exactly one terminal state" holds
  for what it governs and is widened here: the subject is an entry, and a
  directory is one of the things it can be.

### Updating a consumer

- **What CI sees.** A job that treated any non-zero status as "the analyser
  found something" now fails on a tree it used to pass, and the report it
  collected names no new violation. Read the status, not the finding count.
- **Telling 4 from 2.** The exit code alone is enough: 2 means at least one
  error-severity violation, 4 means part of the tree was not read. They do not
  overlap — 4 wins whenever both would apply, so a 2 is always a complete run.
  In `json` and `metrics` the same fact is `coverage.complete: false`, and
  `coverage.failures[]` names every path and why it was not read.
- **A strict deserializer** of `failures[].kind` must accept
  `directory-symlink`, `not-regular-file` and `unreadable-directory` beside
  `parse` and `processing`. Prefer treating an unrecognised value as "an entry
  this run did not read" over refusing the document: the vocabulary is the
  tool's to extend, and a reader that fails closed on a new reason loses the
  whole report to learn nothing.
- **If exit 4 is unwanted for a known entry**, exclude the directory that holds
  it: `exclude:` prunes a directory before the walk records anything about it,
  so `exclude: [{subtree: build/link-farm}]` (ADR 0077 syntax) takes both a
  directory symlink and an unlistable directory out of `failures[]` and the run
  is complete again. `exclude:` prunes directories only, so it cannot do this
  for a non-regular `*.php` entry: remove or rename that entry, or point the
  run at a path that does not contain it. Silencing the whole class is
  deliberately not offered — a switch that turns unread code back into a clean
  report is the state this decision exists to remove.

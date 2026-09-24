# Discovery Rules

Discovery rules report on the run's own file selection rather than on the code it analysed. A filter that removes nothing looks exactly like no filter at all: the report covers files their author meant to leave out, and every number in it is computed over a set nobody asked for.

---

## Unmatched Exclude

**Rule ID:** `discovery.unmatched-exclude`

### What it measures

Every `--exclude` value and every `exclude:` entry in `qmx.yaml` is checked against the directories the run actually walked. A value that removed no directory is reported.

The channel carries **two findings**, because a selector has three possible fates and only one of them is silence:

| Fate of the selector                                     | What the run says                                                |
| -------------------------------------------------------- | ---------------------------------------------------------------- |
| It removed a directory                                   | nothing — the configuration works                                |
| It removed nothing, anywhere in the project              | the selector is stale                                            |
| The walk could not look everywhere it might have matched | the selector was not judged, and the blocking directory is named |

The third case exists because a directory this process may not list hides whatever it holds, so a selector that could have matched inside it is not called stale. Reporting nothing for it would make "your configuration is fine" and "nobody checked your configuration" the same output.

### Why it matters

Without this channel, a missed exclusion and no exclusion at all produce byte-identical output. There is nothing in a report to tell an author that `--exclude=subtree:vendor` never fired because the run was rooted below `vendor/` already, or that `exclude: [{subtree: tests}]` stopped matching when the directory was renamed to `test/`. The excluded files are analysed, they contribute findings, and the exclusion sits in the configuration looking like it works.

### Scope and severity

The channel reports at **project level**, at severity `warning`.

It is only judged on a run whose paths cover everything the project's `composer.json` declares under `autoload` — `psr-4` and `psr-0` roots, `classmap` and `files` entries alike — and, with [`--include-autoload-dev`](../usage/cli-options.md#--include-autoload-dev), everything under `autoload-dev` as well. On a narrower run — a single subdirectory, a git-scoped run — a pattern binds nothing simply because the code it names lies outside the slice. That is the caller's choice, not the author's mistake, so the rule stays silent rather than reporting the caller's own narrowing back at them. The report's [project scope](../usage/output-formats.md#project-scope-in-every-format) names the rule among those a narrowed run did not judge. A project whose manifest declares nothing in the sections the run counts — no `composer.json`, one that does not parse, or one with no such section — has no project beyond the paths you name, so the rule judges those paths as the whole project.

A selector is judged against the **whole project tree**, not against the analysed paths. `exclude: [{subtree: tests}]` written for `qmx check .` removes nothing under `qmx check src/`, but the directory it names is right there, so the rule says nothing. Only a selector that would remove no directory anywhere in the project is reported. Discovery uses the same full-subject `exact | subtree | regex` path language as suppression and prunes a matching directory before descending into it.

Both findings answer to the gates above and to the same `enabled` option, but they are separate baseline identities: accepting a stale selector is not accepting an unjudged one, and a project that has accepted the first still hears about the second.

| Rule              | ID                            | What it detects                                                              |
| ----------------- | ----------------------------- | ---------------------------------------------------------------------------- |
| Unmatched exclude | `discovery.unmatched-exclude` | An exclude pattern that removed no directory, or one the run could not check |

### Example

```bash
bin/qmx check src/ --exclude=subtree:Generated
```

When no directory named `Generated` exists anywhere in the project:

```
[project] discovery.unmatched-exclude
  The exclude pattern "Generated" matched no directory anywhere in the project,
  so nothing was left out for it. Every file it was written to skip was
  measured, and this report covers them.
```

When a directory the pattern might have matched inside could not be listed — an unreadable mount, a directory whose permissions deny this process — the same channel reports that the pattern was not judged instead:

```
[project] discovery.unmatched-exclude
  The exclude pattern "Generated" could not be checked: the directory
  "vendor/private" could not be listed, and a directory the pattern would have
  matched may sit inside it. This report says nothing about whether that
  pattern is still needed.
```

This is a `warning`, so `--fail-on=warning` turns it into a non-zero exit. That is deliberate: a CI job that mounts a directory it cannot read is measuring a tree it cannot see all of, and a green build would say otherwise. Give the process permission to list the directory, or exclude it so the walk stops asking.

### Options

| Option    | Default | Description                 |
| --------- | ------- | --------------------------- |
| `enabled` | `true`  | Enable or disable the rule. |

### Configuration

```yaml
# qmx.yaml
rules:
  discovery.unmatched-exclude:
    enabled: true
```

```bash
bin/qmx check src/ --disable-rule=discovery.unmatched-exclude
```

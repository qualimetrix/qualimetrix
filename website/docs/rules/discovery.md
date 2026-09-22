# Discovery Rules

Discovery rules report on the run's own file selection rather than on the code it analysed. A filter that removes nothing looks exactly like no filter at all: the report covers files their author meant to leave out, and every number in it is computed over a set nobody asked for.

---

## Unmatched Exclude

**Rule ID:** `discovery.unmatched-exclude`

### What it measures

Every `--exclude` value and every `exclude:` entry in `qmx.yaml` is checked against the directories the run actually walked. A value that removed no directory is reported.

### Why it matters

Without this channel, a missed exclusion and no exclusion at all produce byte-identical output. There is nothing in a report to tell an author that `--exclude=subtree:vendor` never fired because the run was rooted below `vendor/` already, or that `exclude: [{subtree: tests}]` stopped matching when the directory was renamed to `test/`. The excluded files are analysed, they contribute findings, and the exclusion sits in the configuration looking like it works.

### Scope and severity

The channel reports at **project level**, at severity `warning`.

It is only judged on a run whose paths cover everything the project's `composer.json` declares as production code — `psr-4` and `psr-0` roots, `classmap` and `files` entries alike. On a narrower run — a single subdirectory, a git-scoped run — a pattern binds nothing simply because the code it names lies outside the slice. That is the caller's choice, not the author's mistake, so the rule stays silent rather than reporting the caller's own narrowing back at them. A project whose manifest declares no production autoload at all — no `composer.json`, one that does not parse, or one with no production section — gives the check nothing to measure the run against, and the rule stays silent there too.

A selector is judged against the **whole project tree**, not against the analysed paths. `exclude: [{subtree: tests}]` written for `qmx check .` removes nothing under `qmx check src/`, but the directory it names is right there, so the rule says nothing. Only a selector that would remove no directory anywhere in the project is reported. Discovery uses the same full-subject `exact | subtree | regex` path language as suppression and prunes a matching directory before descending into it.

| Rule              | ID                            | What it detects                              |
| ----------------- | ----------------------------- | -------------------------------------------- |
| Unmatched exclude | `discovery.unmatched-exclude` | An exclude pattern that removed no directory |

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

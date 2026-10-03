# Suppression Rules

Suppression rules report on the run's own suppression configuration rather than on the code it analysed. A `suppress_paths` or `suppress_namespaces` value that names nothing hides nothing and never will, while its author believes it is hiding something.

---

## Suppression Configuration

**Rule ID:** `suppression.configuration`

Nothing is reported under the producer name `suppression.configuration` itself — it exists so the channels below have one owner to disable and configure as a family. Each channel has its own name and its own meaning.

### What it measures

Every suppression value the run was given — global or under `rules.<name>` — is checked against what the run actually saw: `suppress_paths` against the files it analysed, `suppress_namespaces` against the namespaces it declared. A value matching neither is reported.

A project-level finding has no namespace, so no `suppress_namespaces` value — global or under `rules.<name>` — removes one, however broad its pattern. `(project)`, which a report shows where such a finding's namespace would be, is a display value and not a namespace: written as `suppress_namespaces: [{exact: '(project)'}]`, at either level, it removes nothing and is reported here like any other value that names nothing. To silence a project-level finding, disable its channel or accept it in a baseline.

### Why it matters

This is a different zero from the one `--format=suppressed` already reports. That report's `neverMatched` list is built from removals, so it answers "this suppressor removed nothing" — a state an honest, paid-down suppression also reaches, and the right response to which is to celebrate and delete. The channels here answer "this suppressor named nothing", which no amount of repaired code can cause. Only a typo, a rename, or a move can.

The two questions look alike in a report and lead to opposite actions, which is why they are answered separately.

### Scope and severity

All the channels report at **project level**, at severity `warning`.

Measured [project scope](../usage/output-formats.md#project-scope-in-every-format)
asks two questions. Namespace absence needs complete declaration evidence:
omitted PHP, authored PHP removal, generated exclusions and an uncertain universe
withhold it. Path values use PHP-path completeness and universe certainty
separately; authored/generated removal alone does not close that question.
Rule-ledger namespace values also ask the declaration question.

Every value is placed separately. Accepted production/development PSR-4 facts
from the invocation snapshot locate namespaces independently of report state;
without a map they remain unjudged. A literal under a removed entry is not judged.
Path/ledger regex is unjudged with incomplete paths or authored removal that could
hide a match; namespace regex also requires complete declaration evidence. This
conservative answer can withhold an unrelated stale regex: rerun without the
exclusion. Reports name every skipped `{channel, option, pattern}` value,
including on a `covered` run.

They are not written into a generated baseline: `baseline:generate` measures findings on a different seam, and a warning about the author's own configuration should not become accepted debt in the file that author generates with one command.

If a value is correct and cannot be corrected — a `qmx.yaml` shared across repositories, naming a path one of them does not have — there are two ways out, and they are not the same:

- Switch the channel off where that shared configuration lives: `disabled_rules: ['suppression.unmatched-path']` silences that one channel and leaves the other two speaking. `--disable-rule` does the same for one run.
- Accept it in a baseline you write by hand. A written entry naming the channel and the finding's `occurrence` under `project:` is honoured like any other. Because the value is part of that occurrence, the acceptance names *that* value: replacing it with a different unbound one is reported rather than passing under the accepted entry.

### The channels

| Channel                             | What it detects                                                                                                                                    |
| ----------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| `suppression.unmatched-path`        | A global `suppress_paths` value matching no analysed file                                                                                          |
| `suppression.unmatched-namespace`   | A global `suppress_namespaces` value matching no declared namespace                                                                                |
| `suppression.unmatched-rule-ledger` | The same, for a value configured under `rules.<name>` — `suppress_namespace_channels` included, reported under the selector it was written beneath |

The rule-ledger channel is separate because the mistake it catches has its own shape: a per-rule suppressor is written next to the rule it belongs to, so a value that outlived its subject stays legible in context long after it stopped naming anything.

### Example

```yaml
# qmx.yaml
suppress_namespaces:
  - exact: App\Legacy\Importer   # the namespace was renamed to App\Import
```

```
[project] suppression.unmatched-namespace
  The suppress_namespaces pattern "App\Legacy\Importer" matched no namespace
  declared in this run, so it suppressed nothing and could not have. If the
  code it was written for still exists under another spelling, its findings
  are being reported.
```

### Options

| Option    | Default | Description                                      |
| --------- | ------- | ------------------------------------------------ |
| `enabled` | `true`  | Enable or disable the rule and all its channels. |

### Configuration

```yaml
# qmx.yaml
rules:
  suppression.configuration:
    enabled: true
```

```bash
bin/qmx check src/ --disable-rule=suppression.configuration
```

## Owner and namespace membership

A suppress_namespace_channels key must address a channel of its configured producer at Namespace level. One channel must witness both owner membership and the applied level; a level from a sibling channel cannot justify it. Write the keyed map in YAML. The audit itself uses final selection and keeps its existing whole/partial-coverage limits. enabled:true is an intentional exact enable over a lower disable.

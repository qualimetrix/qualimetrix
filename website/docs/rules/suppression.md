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

The [project scope](../usage/output-formats.md#project-scope-in-every-format)
controls whether the channels can judge: `covered` reaches all counted targets,
while `narrowed` and `unmeasured` withhold them. `unknown` permits judgement
when a missing, damaged or undeclared autoload universe is paired with the
whole project root. A subset without a complete declared universe is
`unmeasured`, not a whole project by assumption. The report names the reasons
and withheld channels.

A judging run still asks where each value belongs. Namespace values use
accepted production and development PSR-4 records from the current invocation
snapshot; map usability is independent of `unknown` or `covered`. Without a
usable map, namespace values remain unjudged and appear in `unjudgedValues`.

Each value is then judged separately, against the place it names. `subtree: tests/Legacy` points at `tests/`, which `qmx check src/` never analysed, so that entry is not judged on that run — while `subtree: src/Legacy` on the same run is. A skipped value is not silent: the report's project scope names it in `unjudgedValues`, on a `covered` run too. A namespace value is placed through the PSR-4 map, `autoload-dev` included. An arbitrary `regex` has no sound static location; it is therefore judged only when the run covers the complete relevant universe, never by guessing a literal prefix from regex syntax.

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

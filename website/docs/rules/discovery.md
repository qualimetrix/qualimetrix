# Discovery Rules

Discovery rules report on the run's own file selection rather than on the code it analysed. A filter that removes nothing looks exactly like no filter at all: the report covers files their author meant to leave out, and every number in it is computed over a set nobody asked for.

---

## Unmatched Exclude

**Rule ID:** `discovery.unmatched-exclude`

### What it measures

Every effective authored `exact`, `subtree` or `regex` selector is checked against
observed entries: files, directories, links and special entries. All matching
selectors bind before an entry is pruned. Equal writes from file, CLI and presets
are grouped by canonical display with their full origin history.

| Measured outcome                                                     | What the run says                                                              |
| -------------------------------------------------------------------- | ------------------------------------------------------------------------------ |
| The selector bound                                                   | `Removed`, with no unmatched warning                                           |
| Nothing bound and no possible hidden location exists                 | An unmatched-selector warning                                                  |
| A match could lie under a directory removed by its own source        | A qualified warning about matches outside the hider's removal                  |
| A possible hider has an origin absent from the selector's source set | An unjudged value with a scope reason naming the hider and its foreign origins |
| A possible location cannot be listed                                 | The inaccessible directory is named; absolute absence is not claimed           |
| PHP paths are incomplete or the universe is uncertain                | The unsettled selector is not judged                                           |

### Scope and severity

The channel reports at project level with severity `warning` under final producer
selection. It asks PHP-path completeness and universe certainty separately from
declaration absence: authored PHP removal and generated files alone do not close
this question. A bound selector remains `Removed` on a narrow run. Git `--report`
limits publication, not analysis, and retains this channel even in strict mode.
See [Project scope](../usage/output-formats.md#project-scope-in-every-format).

The audit does not descend into removed directories. Possible hiders are physical
directories, including accepted named directory-alias targets, not walked links
or individual files. Regex might match in any such directory; exact/subtree uses
literal containment. Any hider origin absent from the queried selector's sources
makes it other-source. Intersecting sets are valid and other-source takes priority;
equal sets give same-source.

An excluded directory outside selected paths has unseen descendants: it records
named universe uncertainty without listing or PHP search there. Its selector
remains bound while other unsettled selectors may be withheld. An inaccessible
entry inside the selected run makes coverage incomplete with exit 4; a warning
alone does not make that result authoritative.

### How to settle the answer

Rerun the full project without the named CLI/preset exclusion. A same-source
warning does not prove the selector is unnecessary everywhere; an other-source
skip does not mean it is stale. Correct a typo or remove the selector after
checking. Existing unmatched and inaccessible-location occurrence identities
remain distinct: accepting one in a baseline does not accept the other.

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

## Selection does not create coverage

Discovery audit runs only when its producer is active under final selection. enabled:true is an exact authored enable, not a reset; it can cancel a lower disable. Final projectScope judgement asks selector/path completeness separately from declaration absence. An only filter does not turn partial paths into full coverage. See [Configuration](../getting-started/configuration.md#declared-rule-forms-and-prepared-execution).

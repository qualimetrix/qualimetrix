# Stage 03 — Every JSON the tool emits publishes its identity

Carries `website/docs/usage/output-formats.*` in **both** languages, because
`governance/FormatOptionKeys/OutputFormatSchemaConsistencyTest` compares each
structured format's observed schema against those pages in both directions, and
that control runs inside `composer check:code`. A format change without its
documentation page is red. This was the strongest finding against the first
version of this plan.

## One package for the eight channels

Five of the eight — `json`, `suppressed`, `directives`, `baseline:rename-channels`
and `debug:layer-assignment` — publish the same four facts in a `meta` object and
read every one of them from `ProductIdentity::identity()`, `timestamp` merged in
by the caller. `metrics` and `sarif` take only `docs` and `llmsTxt` from it:
`metrics` keeps its own `version` (the export-format version) and `toolVersion`
rather than being overwritten by the tool's, and `sarif` has no `package` field
at all. `graph:export --format=json` also takes only `docs` and `llmsTxt`,
appended to the `meta` block its envelope already carried (`version`, `package`,
`timestamp`), the same treatment `metrics` gets for its own `version`. Split
across executors these keys acquire seven spellings — `llmsTxt`, `llms_txt`,
`llms`, `docsUrl` — and the divergence is invisible until a consumer hits it.
One executor, one spelling.

`identity()`'s `package` is `qmx` — the name every JSON document already
publishes in its `meta`, not the Composer package `qualimetrix/qualimetrix`.
`ProductIdentity` states the published fact rather than the installable unit.

Key names: `docs` and `llmsTxt`, camelCase, matching the surrounding JSON house
style (`worstNamespaces`, `toolVersion`).

| Channel                                                            | Where                | Note                                      |
| ------------------------------------------------------------------ | -------------------- | ----------------------------------------- |
| `json`, `suppressed`                                               | existing `meta`      | extends `version`, `package`, `timestamp` |
| `metrics`                                                          | existing root fields | same role; no nesting introduced          |
| `sarif`                                                            | `tool.driver`        | see below                                 |
| `directives`, `baseline:rename-channels`, `debug:layer-assignment` | **new** `meta`       | no metadata today                         |
| `graph:export --format=json`                                       | existing `meta`      | extends `version`, `package`, `timestamp` |

The three new blocks carry the canonical identity plus their own `timestamp`,
matching `json`'s shape, so an agent parsing any JSON this tool emits finds the
same block in the same place.

`graph:export --format=json` was first excluded here on the theory that its
stdout is the graph document a consumer feeds to another tool and therefore has
no envelope to extend. That theory was false: `JsonGraphExporter::export()`
opens its document with a `meta` block (`version`, `package`, `timestamp`) the
same shape `json` has, and `docs`/`llmsTxt` slot in exactly as they do for
`metrics`. Only `graph:export`'s DOT output has no envelope; the overview's
exclusion table now names only that case.

## SARIF: the constant has two roles

`SarifRuleCollector::INFORMATION_URI` is used twice — as `tool.driver.informationUri`
in `SarifFormatter`, and as the fallback `helpUri` for a channel with no
presentation (`SarifRuleCollector.php:147`). Repointing the constant would
therefore also move rule-level help links, which is a change nobody asked for.

So the constant is **split**:

- the tool-level `informationUri` comes from `ProductIdentity::docsUrl()`;
- the fallback `helpUri` keeps its current value under its own name, with a
  docblock saying it is a fallback and not the tool's information URI;
- `llmsTxt` goes in the standard `properties` bag. A bare new key on `driver`
  risks refusal by strict validators; `properties` is the spec's extension point.

`DOCS_BASE_URI` — the only pre-existing `qualimetrix.dev` literal in `src/` — is
folded into `ProductIdentity` here, which is what lets stage 02's "no literals"
assertion become unconditional.

Adjacent control to watch: `governance/Channel/SarifRuleDescriptorCoverageTest`
requires every real channel to resolve to a page carrying that producer's
`Rule ID:` anchor. The split must not move any *real* channel's `helpUri`; only
the no-presentation fallback and the tool-level field change.

## DoD

- All eight channels carry `docs` and `llmsTxt`, spelled identically; the five
  `meta`-object channels also carry `version` and `package` (`qmx`), all four
  read from `ProductIdentity::identity()`. `graph:export` keeps its own
  pre-existing `version` (the graph format) and `package` literal, the same
  treatment `metrics` gives its own `version`.
- The SARIF document validates against the `$schema` it declares.
- No real channel's `helpUri` changed; `SarifRuleDescriptorCoverageTest` green.
- `metrics` gains no field colliding with its existing `version` (the export
  format version) or `toolVersion`.
- EN and RU `output-formats` pages updated; `OutputFormatSchemaConsistencyTest`
  green in both directions. That control sweeps the structured formats it names
  — `json`, `metrics`, `sarif`, `gitlab`, `suppressed` — so it covers four of the
  eight channels here and **not** the four channels outside `check`'s own
  formats (`directives`, `baseline:rename-channels`, `debug:layer-assignment`,
  `graph:export`). Their pages are
  updated because the documentation should be right, not because a control
  forces it; nothing will go red if they are missed, which is exactly why they
  are named in this DoD.
- `CHANGELOG.md`: `Changed` for the identity block, `Breaking` for the SARIF
  `informationUri` value, naming the old and the new surface.
- `composer check` green; `composer gate` green against the commit this stage
  started from, with whatever surfaces the gate itself reports declared. This
  stage does not forecast that list — see the overview's second principle.

## Edge cases

- **Refusals.** The JSON refusal envelope stays `{error, exit_code}`. A refusal is
  not a report.
- **`suppressed` and the gate map.** `finding-gate/report-values.tsv` is declared
  against `format:suppressed`. Re-check it after the `meta` change: a shifted map
  is a silent failure, not a red test.
- **`metrics` consumers.** Additive only; nothing existing moves.

## Test plan

Per-channel field presence and spelling; SARIF schema validation; the three new
`meta` blocks matching `json`'s shape key-for-key apart from run-specific values;
both documentation directions.

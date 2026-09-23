# Output channel enumeration for an agent-discoverability pointer

## Purpose

Before any plan can claim to place a pointer to `https://qualimetrix.dev/llms.txt`
in "almost every output", every output channel the tool has must be named,
classified by consumer, and judged for whether a pointer fits there at all.
This document is that enumeration. It is fact-gathering only: no production
code, test, or existing doc was touched to produce it.

## How each group was derived, and what that method cannot see

- **Formatters (§1).** Derived from `src/Reporting/Formatter/FormatterRegistry`
  plus the DI auto-registration in
  `src/Infrastructure/DependencyInjection/Configurator/OutputConfigurator.php::registerFormatters()`
  (`registerClasses()` over `src/Reporting/Formatter/{*,**/*}`, excluding
  `Ansi/**` and `FormatterRegistry.php` itself), cross-checked against every
  `getName()` implementation found by `grep`. **Blind spot:** this method finds
  every class the container registers as `FormatterInterface`. It cannot find
  a channel that prints without going through a formatter at all — which is
  exactly why §3 and §5 exist as separate groups, found by a different method
  (reading each command's `execute()`, not the formatter registry).
- **Application header (§2).** Derived from reading
  `src/Infrastructure/Console/Application.php` end to end. It defines no
  `getHelp()`/`getLongVersion()` override, so `list`, `--help`, `--version`
  and bare `bin/qmx` render through Symfony Console's own stock
  `DescriptorHelper`/`TextDescriptor`, seeded only by `Application::NAME` and
  `Version::get()` passed to the parent constructor. **Blind spot:** this is a
  negative finding (absence of an override) — it holds only for this Symfony
  version's default rendering. A `composer.json` bump that changes Symfony
  Console's own list/help templates would silently change this row without
  touching this file.
- **13 commands and their tails (§3).** Derived from `bin/qmx`'s
  `ContainerCommandLoader` map (the single authoritative list of registered
  command names), then reading each command's `execute()`/`doExecute()` body
  for `writeln`/`json_encode` calls that run after the main payload. **Blind
  spot:** a command whose tail is produced by a shared helper class several
  frames away from the command file itself (as `check` delegates to
  `HintRenderer` through `SummaryFormatter`) is easy to miss on a shallow
  `grep`; this enumeration followed each delegation by hand, but a similarly
  indirect tail introduced later would not be caught by re-running the same
  `grep` alone.
- **`setHelp()` presence (§4).** Derived from `grep -c "setHelp"` across every
  `*Command.php` file, including `Debug/`. **Blind spot:** `grep` on the
  literal string `setHelp` finds only a call written in the command's own
  file. A command that inherited a `configure()` doing `$this->setHelp(...)`
  from an abstract parent would show `0` here and be misclassified as "no
  help" — checked by hand for `AbstractHookCommand`/`BaselineCommand` and
  confirmed neither injects help this way, but the grep alone would not have
  proven it.
- **Refusals (§5).** Derived from reading
  `src/Infrastructure/Console/Refusal/RefusalPresenter.php` and
  `src/Infrastructure/Console/Refusal/MachineReadableFormats.php` together:
  the presenter has exactly two output shapes (`present()`'s stderr
  `<error>...</error>` line, or `writeEnvelope()`'s stdout `{error,
  exit_code}` JSON), and `MachineReadableFormats::carriesJson()` is a closed,
  test-enforced classification of which of the 12 formats choose which shape.
  **Blind spot:** two commands (`graph:export`, `directives`) own a *second*,
  independent catch ladder instead of relying on `Application::doRun()`'s —
  found only by reading each command's `execute()` for its own `try/catch`
  around `ConfigurationRefusal`. A command added later with its own ladder
  that calls `RefusalPresenter` differently would not surface from reading
  `Application.php` alone.
- **HTML report (§6).** Derived from `src/Reporting/Formatter/Html/HtmlFormatter.php`
  (confirms the template comes from `html-report/report.html` with
  `__CSS__`/`__DATA__`/`__D3_JS__`/`__APP_JS__` placeholders, not from PHP) and
  then `grep -n "footer"` across `html-report/report.html`, `report.css` and
  `src/*.js`, which found the one write site,
  `html-report/src/main.js:824-833`. **Blind spot:** `grep` on the word
  "footer" finds only code that already names a footer. A second place that
  renders a doc pointer under a different DOM id (e.g. inside `#node-summary`
  or a tooltip) would need a full read of `main.js`, which this enumeration
  did not do end to end.

## Summary counts

| Metric                                                                                                                                                       | Count |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----- |
| Formatters enumerated                                                                                                                                        | 12    |
| Commands enumerated                                                                                                                                          | 13    |
| Output channels enumerated in total (§1 formatters + §2 app header sub-cases + §3 command tails + §5 refusal shapes + §6 HTML footer, each row counted once) | 34    |
| Channels with a place already present or cheaply addable                                                                                                     | 17    |
| Channels where a pointer is judged appropriate ("yes")                                                                                                       | 15    |
| Channels judged contraindicated ("no")                                                                                                                       | 12    |
| Channels judged debatable ("sporno")                                                                                                                         | 7     |

Rows do not partition cleanly into disjoint universes (a command's tail *is*
a formatter's output for `check`), so counts above are per-table; see each
table's own row count for the exact denominator used there.

## Gate answer (short)

Yes — for the summary tail, the JSON/metrics `meta`-shaped block, and SARIF,
adding the pointer redoes the finding-gate's *reference* comparison, because
the reference commit (any commit before this change) will not print the new
line/field and the candidate will, on every case that exercises that surface.
This is not a rename (nothing in `finding-gate/maps/*.tsv` fits: it is not an
old-name-to-new-name substitution) — it is exactly what
`finding-gate/declared-delta.tsv` exists for: *"surfaces that changed
structurally, not by rename."*

**What must be declared, and where:**

- File: `finding-gate/declared-delta.tsv` (three tab-separated columns per
  `scripts/finding-gate/DeclaredDelta.php::COLUMNS`: `surface`, `file`,
  `reason`) — one row per affected surface (`summary`, `json`, `metrics`,
  `sarif`, and any other surface the corpus' cases exercise that also prints
  the line, e.g. `text`/`text-verbose` if the pointer is added there too).
- File: one exact unified diff per declared row under
  `finding-gate/declared-delta/`, named to match the row.
- **The diff is not hand-written.** `composer` exposes
  `--derive-declared-delta` (`scripts/finding-gate/Options.php`), which
  measures the actual diff between the reference and candidate trees and
  writes both the index row and the diff file; a hand-typed `reason` survives
  a re-derivation only while the diff it was written against is unchanged,
  and a new/changed diff is written back as `?`, which the loader refuses —
  so the reason has to be re-supplied by a human after each re-derivation.
- **Failure modes to watch, per `scripts/finding-gate/DeclaredDelta.php`:**
  `delta-mismatch` (measured diff ≠ declared diff, byte for byte),
  `delta-stale` (a declared surface the two trees now agree on),
  `delta-too-large` (diff exceeds `DeclaredDelta::MAX_CHANGED_LINES = 200`
  changed lines — a one-line addition repeated across many corpus cases on
  one surface can plausibly hit this ceiling and would then need either a
  narrower corpus selection or the addition to be phrased so it collapses to
  fewer diff hunks), and `delta-overreach` (a diff line moves a field the
  equivalence tuple compares — not expected here, since the pointer is a new
  line/field, not a rewrite of an existing compared field, but worth
  re-checking against `finding-gate/equivalence-tuple.tsv` once the exact
  insertion point is chosen).
- Checkstyle, GitLab Code Quality and GitHub Actions annotations are excluded
  from this obligation because — see §1's "contraindicated" verdict below —
  no plan should be adding the pointer to those surfaces' fixed schema in the
  first place; if one did, the same `declared-delta.tsv` mechanism would
  apply to them too.

## User's working thesis on checkstyle/gitlab/github: confirmed, by schema

- **Checkstyle** (`src/Reporting/Formatter/CheckstyleFormatter.php`): the XML
  schema is `<checkstyle version><file name><error line severity message
  source>`. There is no document-level or file-level field for free text
  outside a violation. The only way to add a pointer is a synthetic `<error>`
  (misrepresenting a doc link as a lint violation, corrupting violation
  counts consumed by CI dashboards) or riding on `message`/`source` of *every
  real violation* (repeats the URL once per finding — noise, not "almost any
  output"). **Confirmed contraindicated.**
- **GitLab Code Quality** (`src/Reporting/Formatter/GitLabCodeQualityFormatter.php`):
  the whole payload is a bare JSON *array* of issue objects
  (`description, check_name, fingerprint, severity, location`) — there is no
  wrapping object with a `meta` slot to put a pointer in. GitLab's MR widget
  renders each array element as an inline diff annotation; a synthetic issue
  would appear to a reviewer as a fake code-quality finding. **Confirmed
  contraindicated.**
- **GitHub Actions annotations** (`src/Reporting/Formatter/GithubActionsFormatter.php`):
  output is a stream of `::error/::warning/::notice file=...::message` workflow
  commands, parsed line-by-line by the Actions runner into PR-diff
  annotations and the job summary. There is no header/footer construct at
  all — even an appended trailing `::notice::` line with no `file=` would
  still render as a visible annotation entry in the checks UI, i.e. exactly
  the "mus in the CI interface" the thesis warns about. **Confirmed
  contraindicated.**

## §1 — Formatters (registered in `FormatterRegistry`, 12 total)

Source of truth: `src/Reporting/Formatter/FormatterRegistry::HIDDEN_FORMATTERS`
lists `text-verbose` as hidden from listings but it is still a real,
selectable, registered formatter, so it is counted.

| Formatter (`getName()`) | File                                                        | Consumer                                                                                                                         | Existing slot                                                                                                                                   | Pointer fits?                     | Reason (one line)                                                                                                                                                                                                                                                                                                                                                           | Contract risk if added carelessly                                                                                                                                                                                                                                                                                                                    |
| ----------------------- | ----------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `summary`               | `Summary/SummaryFormatter.php` + `Summary/HintRenderer.php` | Human (default CLI output); AI agent reading raw stdout                                                                          | `Hints:` line, appended by `HintRenderer::render()` — already a rotating list of contextual tips joined with `\|`                               | **Yes**                           | It is literally the hint line an agent-or-human reads when it doesn't know what to do next; a static hint fits the existing list mechanically                                                                                                                                                                                                                               | Low — `HintRenderer` already appends a fixed final hint (`--format=html -o report.html ...`); one more fixed entry is the same shape. Needs `finding-gate` declared-delta (this surface is in the 12 compared)                                                                                                                                       |
| `text`                  | `TextFormatter.php`                                         | Human; grep/awk/IDE quickfix parsers                                                                                             | Trailing dim `Technical debt: ...` line                                                                                                         | **Debatable**                     | Format doc says "Compatible with GCC/Clang error format… parseable by grep/awk"; anything not matching `file:line: severity[code]: message` risks being mis-parsed by a quickfix regex that assumes every line is a finding                                                                                                                                                 | A quickfix parser that assumes one line = one violation would choke on an extra trailing line; needs testing against at least one real IDE integration before adding                                                                                                                                                                                 |
| `text-verbose`          | `TextVerboseFormatter.php`                                  | Human (deprecated path)                                                                                                          | Delegates entirely to `text` with `--detail` forced on                                                                                          | **No** (follow `text`'s decision) | Deprecated, "will be removed in a future major version" per its own docblock — not worth a special-cased addition                                                                                                                                                                                                                                                           | Same as `text` if added there, but deprecation makes it hard to justify actively touching it                                                                                                                                                                                                                                                         |
| `json`                  | `Json/JsonFormatter.php`                                    | AI agent; CI parser; programmatic consumers (own docblock: "suitable for AI agents, CI pipelines, and programmatic consumption") | `meta` object already carrying `version`, `package`, `timestamp`                                                                                | **Yes**                           | This is precisely the block a machine reader is expected to check first; `JsonFormatterTest` only asserts individual keys exist (`assertArrayHasKey`), not a closed key set, so a new key is not a breaking test change                                                                                                                                                     | Needs `finding-gate` declared-delta; needs confirming no external consumer does strict schema validation with `additionalProperties: false` (none found in this repo, but this is only this repo's own test suite, not third-party consumers per the Backward Compatibility Policy, which explicitly permits this)                                   |
| `metrics`               | `MetricsJsonFormatter.php`                                  | Programmatic (dashboards, trend analysis, third-party integrations, per docblock)                                                | Top-level `version`, `toolVersion`, `package`, `timestamp` fields (no nested `meta`, but same role)                                             | **Yes**                           | Same reasoning as `json`; the top-level fields are the closest existing analogue to a `meta` block                                                                                                                                                                                                                                                                          | Needs `finding-gate` declared-delta; the field naming (`version` used for the *export format* version, not the tool) means a new field must not collide with `version`/`toolVersion`                                                                                                                                                                 |
| `checkstyle`            | `CheckstyleFormatter.php`                                   | CI parser (Jenkins/GitLab/GitHub Checkstyle plugins)                                                                             | None — fixed XML schema                                                                                                                         | **No**                            | Confirmed contraindicated above                                                                                                                                                                                                                                                                                                                                             | Corrupts violation counts / CI dashboards if forced in                                                                                                                                                                                                                                                                                               |
| `sarif`                 | `Sarif/SarifFormatter.php`                                  | CI parser (GitHub Security, VS Code SARIF viewer, Azure DevOps, JetBrains); spec-governed                                        | SARIF 2.1.0's `run.tool.driver` object, or the standard `properties` bag any SARIF object may carry                                             | **Debatable**                     | SARIF is schema-governed by an external spec at `$schema`; the `driver` object's own fields (`name`, `version`, `informationUri`, `rules`) are fixed and already carry a URI (`SarifRuleCollector::INFORMATION_URI`) that plausibly already serves this role — adding a *second* URL needs the `properties` extension bag, not a new top-level key, to stay spec-conformant | A naive addition of a bare new key on `driver` (not inside `properties`) risks failing strict SARIF validators; must check what `SarifRuleCollector::INFORMATION_URI` already points to before assuming a second link is needed                                                                                                                      |
| `gitlab`                | `GitLabCodeQualityFormatter.php`                            | CI parser (GitLab MR Code Quality widget)                                                                                        | None — bare JSON array, no wrapper object                                                                                                       | **No**                            | Confirmed contraindicated above                                                                                                                                                                                                                                                                                                                                             | Renders as a fake code-quality finding in the MR widget                                                                                                                                                                                                                                                                                              |
| `github`                | `GithubActionsFormatter.php`                                | CI parser (GitHub Actions workflow-command interpreter)                                                                          | None — command stream, no header/footer                                                                                                         | **No**                            | Confirmed contraindicated above                                                                                                                                                                                                                                                                                                                                             | Renders as a visible (if empty-file) annotation in the Checks UI                                                                                                                                                                                                                                                                                     |
| `health`                | `Health/HealthTextFormatter.php`                            | Human (terminal health report)                                                                                                   | `renderHeader()`'s bold title line (`Health Report (Qualimetrix {version}) — N files analyzed`), or a new closing line mirroring `HintRenderer` | **Yes**                           | Purely human/textual output with an existing version-carrying header line; same shape as `summary`'s header                                                                                                                                                                                                                                                                 | Needs `finding-gate` declared-delta (this surface is one of the 12 compared); no existing "hints" mechanism here, so this would be new code, not a one-line change to an existing renderer                                                                                                                                                           |
| `html`                  | `Html/HtmlFormatter.php`                                    | Human (browser-viewed report)                                                                                                    | `#report-footer` element, filled by `html-report/src/main.js:824-833` (`Generated {date} \| Qualimetrix {version}`)                             | **Yes**                           | Existing footer already carries the version string; appending a doc link is the same line                                                                                                                                                                                                                                                                                   | Requires `npm run build` / `composer build:js` to regenerate `html-report/dist/report.min.js`; the JS bundle, not PHP, must be rebuilt or the change is invisible. No JS test asserts footer content today (`grep` over `html-report/tests/` found none), so no test contract to update — but that also means no regression guard exists for wording |
| `suppressed`            | `Suppressed/SuppressedFormatter.php`                        | AI agent / programmatic (companion to `json`, per its own docblock: "machine-readable composition of what a run suppressed")     | `meta` object, identical shape to `json`'s (`version`, `package`, `timestamp`)                                                                  | **Yes**                           | Same reasoning as `json`                                                                                                                                                                                                                                                                                                                                                    | Needs `finding-gate` declared-delta (in the 12 compared, and `format:suppressed` is also the one surface `report-values.tsv` is declared against — re-check that map still applies cleanly after any structural change here)                                                                                                                         |

## §2 — Application header (`src/Infrastructure/Console/Application.php`)

| Invocation              | What renders                                                                                                                                                           | Responsible method                                                                                                                                                                                                                              | Existing slot                                                 | Pointer fits? | Reason                                                                                                                                                                                                                                                                        |
| ----------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------- | ------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `php bin/qmx` (no args) | Symfony's default: the `list` command's output (app name/version banner + command listing), since no default command is set                                            | Not this project's code — Symfony `Application::run()` → `Application::doRun()` falls through to the `list` command because no `setDefaultCommand()` call exists; `Application::NAME`/`Version::get()` feed the banner via the base constructor | None in this project's code; Symfony's own `DescriptorHelper` | **Sporno**    | A pointer here is the single highest-visibility spot ("agent ran the tool with zero args") but changing it means overriding Symfony's own descriptor/help rendering, not a one-line addition — meaningfully larger than every other row in this document                      |
| `php bin/qmx list`      | Same Symfony command listing                                                                                                                                           | Symfony `ListCommand` (vendor code)                                                                                                                                                                                                             | None in this project                                          | **Sporno**    | Same as above — not a "cheap" channel; would need a custom `HelpCommand`/`ListCommand` override or an `Application::getHelp()` override, out of scope for a one-line pointer                                                                                                  |
| `php bin/qmx --help`    | Symfony's global help (same as `list` essentially, or a command's help when combined with a command name)                                                              | Symfony `Application::renderThrowable()`/help machinery (vendor code); `Application.php` in this repo defines no override                                                                                                                       | None in this project                                          | **Sporno**    | Same reasoning                                                                                                                                                                                                                                                                |
| `php bin/qmx --version` | `Qualimetrix {Version::get()}` — Symfony's stock one-line version string built from the name/version passed to `parent::__construct()` in `Application::__construct()` | `Symfony\Component\Console\Application::getLongVersion()` (vendor default; not overridden here)                                                                                                                                                 | None — single line by design                                  | **No**        | `--version` is a Unix convention consumers (scripts checking `qmx --version`) expect to be exactly the name and version, nothing else; appending a URL breaks that convention for zero benefit (an agent invoking `--version` is checking presence/version, not reading docs) |

## §3 — Command output / tails (13 commands, per `bin/qmx`'s `ContainerCommandLoader` map)

| Command                    | Own tail beyond formatter output?                                                                                                           | Where                                                                                                                | Pointer fits?                       | Reason                                                                                                                                                                                                                                                                                                     |
| -------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- | ----------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `check`                    | Yes, via the chosen `--format`'s own formatter (see §1); no command-level tail on top of it                                                 | `CheckCommand::doExecute()` delegates entirely to `ResultPresenter`/the resolved formatter                           | Follows §1's per-formatter verdict  | `check` itself never prints beyond what the formatter emits                                                                                                                                                                                                                                                |
| `baseline:generate`        | Yes — plain `writeln()` summary lines, no `--format` option                                                                                 | `BaselineGenerateCommand::doExecute()`                                                                               | **Yes**                             | Plain human text tail with room for one more line, same shape as `hook:install`'s tips                                                                                                                                                                                                                     |
| `baseline:update`          | Yes — plain `writeln()` (`Baseline updated: ...` / `No entry moved...`)                                                                     | `BaselineUpdateCommand::doExecute()`                                                                                 | **Yes**                             | Same as above                                                                                                                                                                                                                                                                                              |
| `baseline:cleanup`         | Yes — plain `writeln()` (several outcome messages)                                                                                          | `BaselineCleanupCommand::doExecute()`                                                                                | **Yes**                             | Same as above                                                                                                                                                                                                                                                                                              |
| `baseline:explain`         | Yes — plain `writeln()` boundary explanation, no `--format` option                                                                          | `BaselineExplainCommand::doExecute()`                                                                                | **Yes**                             | Same as above; always human text                                                                                                                                                                                                                                                                           |
| `baseline:rename-channels` | Yes, and it is the *only* one of the five `baseline:*` commands with `--format text\|json`                                                  | `ChannelRenameReporter::report()` (text branch: `writeln`; json branch: ad hoc `json_encode` with no `meta` wrapper) | **Debatable**                       | Text branch: yes, same as the other baseline commands. JSON branch: no existing `meta` slot — the ad hoc shape is `{written, entries, renamed, rows, idle_rows, unreadable}`; adding a pointer means adding a new top-level key to a shape with no precedent for one, unlike `json`/`metrics`/`suppressed` |
| `debug:layer-assignment`   | Yes — plain text tail with an explicit "Diagnostic hint:" section already, or `--format=json` (own `json_encode`, no `meta` wrapper)        | `Debug/LayerAssignmentCommand::execute()`                                                                            | **Debatable**, leaning yes for text | Text branch already has a "hint" convention (`Diagnostic hint:` block) to extend; JSON branch has no `meta` precedent, same caveat as `baseline:rename-channels`                                                                                                                                           |
| `directives`               | Yes — text output, or `--format=json`/other `MachineReadableFormats`-classified formats reusing the shared envelope machinery               | `DirectivesCommand::execute()`/`audit()`                                                                             | **Sporno**                          | Not read in full during this enumeration (only `configure()`/`execute()` dispatch and `setHelp()` were read); its exact success-path JSON shape and whether it has a `meta`-like slot needs its own read before judging                                                                                    |
| `graph:export`             | No — `execute()` writes the DOT/JSON *graph document itself* to stdout or `--output` file, nothing else                                     | `GraphExportCommand::doExecute()` (`OutputHelper::write($output, $content)` or `writeToFile()`)                      | **No**                              | The entire stdout content is the deliverable artifact (a graph in DOT or JSON) consumed by Graphviz or another tool; there is no separate "tail" — appending anything corrupts the DOT syntax or the JSON document                                                                                         |
| `hook:install`             | Yes — plain `writeln()` with explicit next-step tips already (`Hook path:`, `Runs:`, `The hook will run...`, `To bypass the hook, use:...`) | `HookInstallCommand::execute()`                                                                                      | **Yes**                             | Already a "next steps" tail convention; a doc pointer is the same genre of line                                                                                                                                                                                                                            |
| `hook:uninstall`           | Yes — same shape, outcome + tips                                                                                                            | `HookUninstallCommand::execute()`                                                                                    | **Yes**                             | Same as `hook:install`                                                                                                                                                                                                                                                                                     |
| `hook:status`              | Yes — extensive human-readable status report already ending in actionable tips (`To install Qualimetrix hook, run: ...`)                    | `HookStatusCommand::execute()`                                                                                       | **Yes**                             | Same convention, most tip-heavy of the three hook commands                                                                                                                                                                                                                                                 |
| `rules`                    | Yes — delegates to `RuleListingPresenter::present()`, which already ends with a `Usage:` block (`bin/qmx check --disable-rule=... \| ...`)  | `RuleListingPresenter::present()`                                                                                    | **Yes**                             | The `Usage:` block is exactly the kind of "what to do next" footer a pointer belongs in                                                                                                                                                                                                                    |

Note: `directives` and `graph:export` are the two commands that own an
independent refusal ladder rather than relying purely on
`Application::doRun()` — see §5.

## §4 — `setHelp()` presence (Symfony `Help:` section, all 13 commands)

| Command                    | Has `setHelp()`? |
| -------------------------- | ---------------- |
| `check`                    | Yes              |
| `baseline:generate`        | Yes              |
| `baseline:update`          | Yes              |
| `baseline:cleanup`         | Yes              |
| `baseline:explain`         | Yes              |
| `baseline:rename-channels` | Yes              |
| `debug:layer-assignment`   | Yes              |
| `directives`               | Yes              |
| `graph:export`             | **No**           |
| `hook:install`             | **No**           |
| `hook:uninstall`           | **No**           |
| `hook:status`              | **No**           |
| `rules`                    | **No**           |

8 of 13 have a `Help:` section; 5 do not. All 8 with `setHelp()` already
contain worked `<info>bin/qmx ...</info>` examples, several already pointing
at another command (`check`'s help literally says "Run `bin/qmx rules`..."),
making `Help:` sections a naturally hospitable place for one more sentence.
The 5 without `setHelp()` (`graph:export`, all three `hook:*` commands,
`rules`) still show Symfony's synthesized default help (just the description
+ options), so adding a `setHelp()` call to any of them is a new code path,
not an edit to an existing string.

## §5 — Refusals

Governed by two files, read together:
`src/Infrastructure/Console/Refusal/RefusalPresenter.php` (the two rendering
shapes) and `src/Infrastructure/Console/Refusal/MachineReadableFormats.php`
(which of the 12 registered formats select which shape — a closed,
test-enforced list per that class's own docblock).

| Refusal shape                                                                               | Where written                                   | Triggering formats                                                                                                                                           | Pointer fits? | Reason                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| ------------------------------------------------------------------------------------------- | ----------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `<error>{message}</error>` on stderr, `VERBOSITY_QUIET`                                     | `RefusalPresenter::present()` → `writeStderr()` | `text`, `text-verbose`, `summary`, `checkstyle`, `github`, `html`, and no format at all (pre-command-construction failures caught by `Application::doRun()`) | **Yes**       | This is exactly the moment the brief named -- an agent reads output precisely when it is stuck; an agent hitting a refusal is already reading stderr looking for what to do; one more clause naming the docs URL is low-risk since the shape is free text already                                                                                                                                                                                                                                                            |
| `{"error": "...", "exit_code": N}` JSON envelope on stdout, `OUTPUT_RAW \| VERBOSITY_QUIET` | `RefusalPresenter::writeEnvelope()`             | `json`, `sarif`, `gitlab`, `metrics`, `health`, `suppressed` (`MachineReadableFormats::JSON_DOCUMENT_FORMATS`, closed list)                                  | **Sporno**    | Schema is fixed at exactly two keys, documented in the class's own docblock as "the shape every command's refusal and internal-error path shares"; a script parsing `{error, exit_code}` today would tolerate a third key (nothing here suggests strict-mode parsing), but the docblock's own framing ("origin()/position() ... reach the reader through the wording of $message instead") suggests the authors deliberately keep this envelope minimal — widening it needs the same deliberateness, not a drive-by addition |

Both `graph:export` and `directives` route through this same
`RefusalPresenter`, just via their own local `try/catch` instead of letting
`Application::doRun()`'s ladder catch it — the *shape* printed is identical
either way, so this table's verdict is not command-specific.

## §6 — HTML report (`html-report/` + `src/Reporting/Formatter/Html/`)

| Element                          | Source                                                                               | Rendered by                                                                                                                                                                                  | Pointer fits?                                               | What rebuilding it requires                                                                                                                                                                                                                                                               |
| -------------------------------- | ------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `#report-footer`                 | `html-report/report.html:38` (empty `<footer>` shell)                                | `html-report/src/main.js:823-841` — `renderFooter()`, which sets the generated-date/version line and, when `project.docs`/`project.llmsTxt` are present, appends the two documentation links | **Yes**                                                     | Edit `main.js`, then `composer build:js` (or `cd html-report && npm run build`) to regenerate `html-report/dist/report.min.js`, which `HtmlFormatter::format()` reads verbatim and inlines via the `__APP_JS__` placeholder — a PHP-only change would not touch the shipped report at all |
| `#node-summary` / detail sidebar | `html-report/report.html:30`                                                         | Populated per-node by JS during interaction, not a static "about" area                                                                                                                       | **No** (wrong slot)                                         | N/A — this is per-selection data, not a place for a constant, run-independent string                                                                                                                                                                                                      |
| Coverage-incomplete banner       | `HtmlFormatter::format()`, PHP-side `str_replace('<body>', '<body>' . $banner, ...)` | PHP, not JS — the one part of the HTML report actually assembled server-side                                                                                                                 | **No** (wrong slot; conditional, not "almost every output") | Only appears when `!$report->coverage->isComplete()`, i.e. exactly the opposite of "almost any output"                                                                                                                                                                                    |

This enumeration predates the stage-04 package that closed this gap. As
measured after that package landed: `html-report/tests/main.test.js` now
asserts `renderFooter()`'s output against a fake DOM (`describe('renderFooter'
...)`, five cases) and, separately, that `init()` still calls
`renderFooter(DATA.project)` (an AST-structural check, since driving `init()`
live needs a DOM environment this suite does not have). Neither exercises the
*shipped* `html-report/dist/report.min.js` `HtmlFormatter` actually inlines —
that is `scripts/check-html-bundle-freshness.php`'s job
(`governance/GeneratedArtifactFreshness/HtmlBundleFreshnessTest.php`,
`composer html-bundle:check`), which rebuilds the bundle and fails if it
disagrees with the committed one, byte for byte.

## Assumptions made

- "Almost any output" in the user's framing is read as: every channel a human
  operator or an AI agent might be the first reader of, excluding channels
  whose sole consumer is a non-negotiable third-party schema (Checkstyle,
  GitLab Code Quality, GitHub Actions annotations, and — with the caveat
  above — SARIF's core `driver` fields and `--version`'s one-line contract).
- `directives`' exact success-path output shape was read only far enough to
  confirm it has `--format=json` support and a rich `setHelp()`; its full
  body (`audit()`/whatever renders the success payload) was not read line by
  line, hence the "Sporno" verdict in §3 rather than a firm yes/no.
- The Application-header rows (§2) are judged against *this* Symfony Console
  version's default rendering, since `Application.php` defines no override;
  a future Symfony upgrade could change what actually prints there without
  this repository's own code changing.
- The finding-gate answer assumes the pointer is inserted as literal text
  appearing in the corpus's existing case output (i.e., every corpus case
  that already exercises `summary`/`json`/`metrics`/`sarif` picks it up
  identically) — a conditional insertion (e.g., only under `--verbose`) would
  change which corpus cases the diff touches and possibly its declared-delta
  size, but not the mechanism (`declared-delta.tsv` + `--derive-declared-delta`)
  itself.

## Definition of Done — status

- [x] File exists at `docs/internal/plans/agent-discoverability/enumeration-output-channels.md`.
- [x] All six requested groups enumerated, each with counts in its own table.
- [x] Explicit gate answer given, naming `finding-gate/declared-delta.tsv` and
      its `surface, file, reason` row shape, plus the `--derive-declared-delta`
      derivation path and the `finding-gate/declared-delta/` diff directory.
- [x] "How each group was derived, and what that method cannot see" section
      present, one entry per group.
- [ ] `git status` shows exactly one new file and no modified tracked file —
      to be confirmed by the caller after this file is written; no existing
      file was opened with a write/edit tool during this task.

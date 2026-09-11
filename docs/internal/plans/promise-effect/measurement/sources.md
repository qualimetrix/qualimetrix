**Чем получено:** — configuration sources and the order their values are applied,
on tree `6a833ab8fcd5004e964e32a59bfeb9978c39739c` (branch `x17-promise-effect`,
`git status --short` empty at measurement time; every `file:line` below is reproducible
only against that commit):

```
git log -1 --format=%H
grep -rn "PRIORITY" src/Analysis/Configuration/Pipeline/Stage/*.php
sed -n '1,80p' src/Analysis/Configuration/Pipeline/ConfigurationPipeline.php
sed -n '1,90p' src/Infrastructure/Console/ConfigurationInputAdapter.php
grep -n "function contributions" -A 25 src/Analysis/Configuration/Contract/ConfigurationDocument.php
grep -n "" src/Analysis/Finding/Configuration/FindingConfigurationResolver.php
sed -n '1,200p' src/Analysis/Finding/RuleConfiguration/RuleOptionsFactory.php
sed -n '1,200p' src/Infrastructure/Console/CliOptionsParser.php
sed -n '40,130p' src/Analysis/Finding/RuleConfiguration/RuleOptionsRegistry.php
grep -rn "configFileOptions\|cliOptions\|setConfigFileOptions\|setCliOptions" src/ --include=*.php
grep -rn "withOverride" src/ --include=*.php
grep -rnE "getenv\(|\$_ENV|\$_SERVER" src/ --include=*.php
grep -rn "new ConfigurationResolutionRequest" src/ --include=*.php
grep -rn -iE "priorit|overrid|precede|order|later|wins" website/docs/getting-started/configuration.md
grep -rn "addCliOption|configureCli|setCliOptions|setConfigFileOptions" src/ --include='*.php'
grep -rn "PRESET" src/Analysis/Configuration/ConfigSchema.php
grep -rn "getThresholdOverride|thresholdOverrides" src/ --include='*.php'
grep -rn -- "->analyze(" src/ --include='*.php'
grep -rl "getEffectiveSeverity|getEffectiveOptions" $(find src -name '*Rule.php') | wc -l
```

**Чего этот способ не видит:**

- Reading is by hand off five entry files plus targeted grep. The "no other writer" claim
  was checked separately and **verified**:
  `grep -rn "addCliOption|configureCli|setCliOptions|setConfigFileOptions" src/ --include='*.php'`
  returns 6 hits, all of them the declaration of the method itself (5 in
  `RuleOptionsRegistry`, 1 in `RuleConfigurationInterface:16`) — no caller in `src/`.
  `RuleOptionsRegistry::replace()` is therefore the only live writer. `tests/` was not
  scanned, and a call written as a DI container string would still be invisible.
- `getenv` grep covers `src/` only. `scripts/`, `composer.json` and CI were not scanned,
  so environment variables that shape a *run* (e.g. `QMX_MKDOCS`, `QMX_PRIVATE_TERMS`)
  are out of scope by construction; the claim below is only "nothing in `src/` reads an
  env var into a rule option".
- Ordering is read off the `priority()` constants and `ConfigurationPipeline::stages()`;
  it is not confirmed by executing the pipeline. A stage registered twice, or a
  compiler-pass ordering effect, would not show up.
- The pairs table is derived from *which code path can write a rule-option key*, not from
  an executed conflict. It states capability, not observed behaviour.

---

## 1. The pipeline: five stages, one ordered document list

`ConfigurationPipeline::resolve()` —
`src/Analysis/Configuration/Pipeline/ConfigurationPipeline.php:28`. It walks
`stages()` (`:53`, insertion-sorted by ascending `priority()`) and appends one
`{source, values}` entry per layer; a layer carrying `documents` contributes **one entry
per document** (`:36-42`).

| #   | Stage                    | file:line (class / PRIORITY)                  | priority | `source` tag     | What it contributes                                                                                         | Can it write `rules.*`? |
| --- | ------------------------ | --------------------------------------------- | -------- | ---------------- | ----------------------------------------------------------------------------------------------------------- | ----------------------- |
| 1   | `DefaultsStage`          | `Stage/DefaultsStage.php:16` / `:18`          | 0        | `defaults`       | `new ConfigurationLayer('defaults', [])` — literally empty (`:30-32`)                                       | **no** (empty array)    |
| 2   | `ComposerDiscoveryStage` | `Stage/ComposerDiscoveryStage.php:19` / `:21` | 10       | `composer.json`  | only `ConfigSchema::PATHS` (`:47-49`)                                                                       | **no**                  |
| 3   | `PresetStage`            | `Stage/PresetStage.php:25` / `:27`            | 15       | `preset:<names>` | **N documents**, one per `--preset` name, in the order given (`:105-119`), each a whole normalized YAML doc | **yes**                 |
| 4   | `ConfigFileStage`        | `Stage/ConfigFileStage.php:22` / `:24`        | 20       | (config path)    | the whole normalized `qmx.yaml`                                                                             | **yes**                 |
| 5   | `CliStage`               | `Stage/CliStage.php:16` / `:18`               | 30       | `cli`            | `$request->cliValues` (`:36`)                                                                               | **no** — see §2         |

So the document list is, in order: `defaults`, `composer.json`, `preset#1 … preset#N`,
config file, `cli`. Count of stages = **5**; count of documents = **4 + N** minus any
stage that returned `null`.

### Where the source identity is lost

`ConfigurationDocument::contributions()` —
`src/Analysis/Configuration/Contract/ConfigurationDocument.php:19-29` — returns a bare
`list<mixed>` of the values for one top-level key. The `source` tag is dropped at `:24`.
`appliedSources()` (`:32-35`) is the only thing that ever reads it, and it returns the set
of names, not a per-value attribution.

`FindingConfigurationResolver::resolve()` —
`src/Analysis/Finding/Configuration/FindingConfigurationResolver.php:21-25` — folds
`contributions(ConfigSchema::RULES)` left to right into one array. **From this point on,
"which layer wrote this option" is not recoverable.** `RuleInputValidator:148-150` already
documents that fact in a comment for the sibling "unknown owner" refusal.

## 2. CLI is two separate doors, and the pipeline one carries no rule options

`ConfigurationInputAdapter::overrides()` —
`src/Infrastructure/Console/ConfigurationInputAdapter.php:47-66` — builds `cliValues`.
Its key list is `mappedOptions()` (`:70-80`) plus `paths`, `no-cache`,
`include-generated`, `workers`. **`ConfigSchema::RULES` is not among them**, so the
`cli` document (priority 30) never carries a rule option. `--disable-rule` / `--only-rule`
do travel this door, but they select rules, they do not set an option value.

Rule options from the command line travel a **second, parallel door**:

- `RuleInputValidator::resolve()` — `src/Infrastructure/Console/RuleInputValidator.php:38-43`
  builds `new FindingCliOverrides($cliRuleOptions)` from
  `CliOptionsParser::parseRuleOptions()`.
- `CliOptionsParser::parseRuleOptions()` — `src/Infrastructure/Console/CliOptionsParser.php:29-60`.
  It parses `--rule-opt` first (`:31-34`), then every registered `#[CliAlias]` short
  alias (`:37-57`). The alias write is `??=` at `:55-56`, i.e. **`--rule-opt` beats a
  short alias for the same rule+option** — merge point #1.
- `RuleOptionsRegistry::replace()` — `src/Analysis/Finding/RuleConfiguration/RuleOptionsRegistry.php:58-63`
  puts the folded `rules:` array into `configFileOptions` and the CLI overrides into
  `cliOptions`. Two buckets reach the factory, not five.

## 3. The merge points that decide a key's effect

| #   | Merge point                                              | file:line                                                                          | What it merges                                                                                                                                                                                                                                                 |
| --- | -------------------------------------------------------- | ---------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `CliOptionsParser::parseRuleOptions()` `??=`             | `src/Infrastructure/Console/CliOptionsParser.php:55-56`                            | `#[CliAlias]` under `--rule-opt`                                                                                                                                                                                                                               |
| 2   | `FindingConfigurationResolver::mergeRules()`             | `src/Analysis/Finding/Configuration/FindingConfigurationResolver.php:43-56`        | one rule's entry across documents; a non-array or list value **replaces** wholesale (`:52`)                                                                                                                                                                    |
| 3   | `FindingConfigurationResolver::mergeRuleOptions()`       | same file `:64-78`                                                                 | recursive per-key overlay of preset(s) and config file; calls `evictOverriddenMode` at `:66` before each level                                                                                                                                                 |
| 4   | `RuleOptionsFactory::deepMerge()`                        | `src/Analysis/Finding/RuleConfiguration/RuleOptionsFactory.php:403-416`            | file bucket ← CLI bucket; calls `evictOverriddenMode` at `:405`                                                                                                                                                                                                |
| 5   | `RuleOptionThresholdModeResolver::evictOverriddenMode()` | `src/Analysis/Finding/RuleConfiguration/RuleOptionThresholdModeResolver.php:84-91` | strips the lower layer's keys of the mode the higher layer switched away from, **per declared group**, from `RuleThresholdKeyGroupRegistry::groupsFor($ruleName, $path)`; falls back to a prefix heuristic when no entry exists (`:88-90`, rationale `:31-63`) |
| 6   | `RuleOptionsFactory::create()` step 4                    | `src/Analysis/Finding/RuleConfiguration/RuleOptionsFactory.php:124`                | `$merged = $userConfig === [] ? $defaults : $userConfig` — constructor defaults are an **all-or-nothing fallback**, never merged per key                                                                                                                       |
| 7   | `Options::fromArray()` flat branch                       | see `hierarchical-options.tsv`                                                     | a top-level `threshold` short-circuits the nested level configs entirely                                                                                                                                                                                       |
| 8a  | `AbstractRule::getEffectiveOptions()`                    | `src/Analysis/Finding/Contract/Rule/AbstractRule.php:118-130`                      | applies one inline `@qmx-threshold` via `$options->withOverride(...)` at `:126`, **after** every merge above, per subject. `getEffectiveSeverity()` (`:142-149`) is not a second applier — it delegates here.                                                  |
| 8b  | `LongParameterListRule::checkVoConstructor()`            | `src/Analysis/Evidence/CodeSmell/LongParameterListRule.php:179-182`                | the one rule that reads `getThresholdOverride()` outside `AbstractRule` and applies it through `withVoOverride()` instead of `withOverride()`, to the VO-constructor threshold pair                                                                            |

## 4. The full source set — what is in and what is out

| Source                                       | In the set?                                         | Entry point (file:line)                                                                                                                                                                                                                                               | Writes rule options at (merge point) |
| -------------------------------------------- | --------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------ |
| Constructor defaults                         | **yes**                                             | `RuleOptionsFactory::extractDefaults()` `:199`                                                                                                                                                                                                                        | #6, whole-array fallback only        |
| `DefaultsStage` (the pipeline stage)         | no (empty)                                          | `Stage/DefaultsStage.php:30-32`                                                                                                                                                                                                                                       | —                                    |
| composer.json discovery                      | no                                                  | `Stage/ComposerDiscoveryStage.php:47-49`                                                                                                                                                                                                                              | writes `paths` only                  |
| Preset #1…#N (`--preset`, comma or repeated) | **yes**                                             | `Stage/PresetStage.php:105-119`                                                                                                                                                                                                                                       | #2, #3                               |
| `qmx.yaml` (`--config` or discovered)        | **yes**                                             | `Stage/ConfigFileStage.php:56`                                                                                                                                                                                                                                        | #2, #3                               |
| `cli` pipeline layer                         | no                                                  | `ConfigurationInputAdapter.php:70-80` (no `RULES` key)                                                                                                                                                                                                                | —                                    |
| `#[CliAlias]` short flags                    | **yes**                                             | `CliOptionsParser.php:37-57`                                                                                                                                                                                                                                          | #1, then #4                          |
| `--rule-opt RULE:OPTION=VALUE`               | **yes**                                             | `CliOptionsParser.php:31-34`, parser `RuleOptionsParser`                                                                                                                                                                                                              | #1, then #4                          |
| inline `@qmx-threshold`                      | **yes**                                             | parsed per file by `Analysis/Policy/Inline/Contract/ThresholdOverrideExtractor`; carried `SourceControls.php:21` → `FileProcessor.php:92` → `CollectionOrchestrator.php:119-120` → `AnalysisPipeline.php:98`; read by `AnalysisContext::getThresholdOverride()` `:59` | #8a, #8b                             |
| inline `@qmx-ignore` / `@qmx-ignore-file`    | **no** — suppresses a finding, sets no option value | `Analysis/Policy/Inline/`                                                                                                                                                                                                                                             | —                                    |
| Environment variables                        | **no**                                              | only two `getenv` in `src/`: `QMX_ASCII` (`src/Reporting/Formatter/Summary/SummaryFormatter.php:40`) and `NUMBER_OF_PROCESSORS` (`src/Infrastructure/Parallel/Strategy/WorkerCountDetector.php:25`)                                                                   | —                                    |

Checked and **negative**: `ConfigSchema` declares no preset key
(`grep -rn "PRESET" src/Analysis/Configuration/ConfigSchema.php` → 0 hits), so a preset
cannot be named from `qmx.yaml`; and `PresetResolver` only maps a name or path to a file
(`src/Analysis/Configuration/Preset/PresetResolver.php:22-49`) — no preset includes
another. The preset document count is therefore exactly the number of `--preset` names
after dedup (`PresetStage.php:74-93`).

**Rule-option writers: 6** (defaults, preset, config file, `#[CliAlias]`, `--rule-opt`,
`@qmx-threshold`).

## 5. The promised priority

Two promises, of different width:

- Website, `website/docs/getting-started/configuration.md:604`: *"Presets are applied after
  `composer.json` discovery but before `qmx.yaml`. Your config file always overrides preset
  values."*
- Website, `:606`: *"When combining presets, they are merged left-to-right — later presets
  override earlier ones, except list keys like `disabled_rules` which accumulate."*
- Website, `:168`: *"a higher-priority layer (config file over a preset, CLI over the config
  file) may freely switch mode for a rule level"*.
- Code docblock, narrower: `RuleOptionsFactory` — *"Priority: defaults → config file → CLI
  options"* (`src/Analysis/Finding/RuleConfiguration/RuleOptionsFactory.php:24`). It does
  not mention presets or inline directives.

Derived total order, low → high:
`defaults < preset#1 < … < preset#N < qmx.yaml < #[CliAlias] < --rule-opt < @qmx-threshold`.

## 6. Ordered pairs able to dispute one option

A pair = (lower, higher) by the promised order above. Over the 6 writers that is
C(6,2) = **15**, plus the intra-preset pair (preset#i, preset#j, i<j) = **1**.
**Total: 16 ordered pairs.**

| #   | Lower         | Higher           | Merge point that decides it | Note                                                               |
| --- | ------------- | ---------------- | --------------------------- | ------------------------------------------------------------------ |
| 1   | defaults      | preset           | #6                          | not per-key: any user key at all discards the whole defaults array |
| 2   | defaults      | qmx.yaml         | #6                          | same                                                               |
| 3   | defaults      | `#[CliAlias]`    | #6                          | same                                                               |
| 4   | defaults      | `--rule-opt`     | #6                          | same                                                               |
| 5   | defaults      | `@qmx-threshold` | #8a / #8b                   | override applied to the already-built options object               |
| 6   | preset#i      | preset#j (i<j)   | #2/#3                       | document order inside one `ConfigurationLayer`                     |
| 7   | preset        | qmx.yaml         | #2/#3                       |                                                                    |
| 8   | preset        | `#[CliAlias]`    | #4 (after #1)               | crosses the two-bucket boundary                                    |
| 9   | preset        | `--rule-opt`     | #4                          | the pair named in the docs at `:168`                               |
| 10  | preset        | `@qmx-threshold` | #8a / #8b                   | only for the 27 `ThresholdAwareOptionsInterface` classes           |
| 11  | qmx.yaml      | `#[CliAlias]`    | #4                          |                                                                    |
| 12  | qmx.yaml      | `--rule-opt`     | #4                          |                                                                    |
| 13  | qmx.yaml      | `@qmx-threshold` | #8a / #8b                   |                                                                    |
| 14  | `#[CliAlias]` | `--rule-opt`     | #1 (`??=`)                  | both land in the same `cliOptions` bucket before #4                |
| 15  | `#[CliAlias]` | `@qmx-threshold` | #8a / #8b                   |                                                                    |
| 16  | `--rule-opt`  | `@qmx-threshold` | #8a / #8b                   |                                                                    |

Two structural facts about this table:

- Pairs 6, 7 are decided **inside one bucket** (`configFileOptions`); pairs 8-13 are decided
  **between the two buckets** at #4; pair 14 is decided **before** either bucket exists.
  Merge points #7 (the flat branch in `fromArray()`) and #8a/#8b run after all of them and sees no
  source identity at all.
- Pairs 5, 10, 13, 15, 16 need two things, and the two counts differ:
  **capability** — 27 option classes implement `ThresholdAwareOptionsInterface`
  (`grep -rl "ThresholdAwareOptionsInterface" $(find src -name '*Options.php') | wc -l` → 27);
  **reachability** — 26 rule classes actually route through the override
  (`grep -rl "getEffectiveSeverity\|getEffectiveOptions" $(find src -name '*Rule.php') | wc -l`
  → 26), out of 36 classes extending `AbstractRule`. A rule that calls
  `$options->getSeverity()` directly never sees an inline override, whatever its options
  class implements.

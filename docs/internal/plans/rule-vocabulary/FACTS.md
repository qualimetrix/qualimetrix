# Rule vocabulary — measured facts

Everything here was generated from the code, not written by hand. Re-measure
before relying on it; the scripts are one-off and live outside the repo.

Measured on `qmx-baseline.json` v13 (51 036 B) and `src/` at 2026-08-22.

## Channel inventory

63 channels: 57 statically declared + 6 built-in computed metrics
(`HealthDimension`), plus any user-defined computed metric.

| Shape of the `ruleName#violationCode` pair | Count | What the left half is                                       |
| ------------------------------------------ | ----- | ----------------------------------------------------------- |
| `x#x`                                      | 44    | the rule that owns options, and the finding kind            |
| `x#x.suffix`                               | 13    | the rule that owns options (finding kind is the right half) |
| `x#x`, but `x` names no rule               | 10    | nothing: no options, no rule class                          |
| `computed.health#health.*`                 | 6+N   | the producing rule class, unrelated to the code             |

The 10 pseudo-named: 6 from `LayerViolationRule` (`NAME = architecture.layer-violation`)
and 4 from `InlineDirectiveRule` (`NAME = annotation.directive`).

## The left half is already unused

- `ChannelUniverse::producerOf()` resolves from the **code alone** — static map,
  then the computed-definition catalog. No pair parsing.
- `ChannelUniverse::expand()` filters on `$selector->matches($channel->violationCode)`.
  The left half never participates in selector resolution.
- SARIF `ruleId`, GitLab `check_name`, Checkstyle `source` publish the code only.
  The pair appears solely in the JSON `channel` field and the baseline key.
- `ChannelDeclarationCompilerPass.php:125` fails the container build on a
  duplicate code: "A code names exactly one channel."

Latent hole: on a static/computed code collision the static one silently wins in
`producerOf`. `ComputedMetricDefinition::validateName` only forbids the reverse
direction.

## Shape is a property of the rule, not of the channel

31 magnitude channels, 26 occurrence. Exactly **one** producer of 41 mixes both
shapes: `architecture.layer-violation` (6 occurrence + `architecture.unassigned-class`
magnitude) — the rule that is really several rules in one class.

## The level suffix depends on sibling count, not on meaning

Of 31 magnitude channels, 13 carry a suffix and 18 do not. Same level, different
convention:

```
cohesion.lcom              magnitude, class level, no suffix
coupling.cbo.class         magnitude, class level, suffix
complexity.wmc             magnitude, class level, no suffix
complexity.cognitive.class magnitude, class level, suffix
```

The rule is "a suffix appears iff the producer emits more than one channel", so
adding a second level later is a breaking rename of the first. The suffix also
carries two grammars: measurement level (`class`/`namespace`/`callable`) and
measured aspect (`param`/`property`/`return`).

## Findings that are not violations

8 channels declare `ChannelAcceptability::ConfigurationError` — they report a
mistake in the user's configuration, not debt in the code, and can never be
baselined:

- `architecture.coverage`, `architecture.unreachable-layer`,
  `architecture.potential-shadow`, `architecture.empty-template`,
  `architecture.pending-layer-matched`
- `annotation.unresolved-directive`, `annotation.invalid-threshold`,
  `annotation.unsupported-threshold`

Every one of them is also one of the 10 pseudo-named channels. The two anomalies
are the same defect seen twice.

## Four parallel vocabularies

| Vocabulary           | Form                                                               | Count        |
| -------------------- | ------------------------------------------------------------------ | ------------ |
| rule name prefix     | dotted, kebab-case                                                 | 13 families  |
| `RuleCategory`       | enum                                                               | 11 cases     |
| capability directory | `Analysis/{Evidence,Policy}/X`                                     | 17           |
| `MetricName`         | flat, unprefixed, mixed casing (`ccn`, `classRank`, `ce_packages`) | 71 constants |

The first three agree on every producer except three:

```
architecture.circular-dependency  Architecture  Analysis/Evidence/CircularDependency
architecture.layer-violation      Architecture  Analysis/Policy/Architecture
annotation.directive              Annotation    Analysis/Policy/Inline
```

`MetricName` shares no convention with the other three.

## Baseline key composition (51 036 B total)

| Part                                           | Bytes  | Share  |
| ---------------------------------------------- | ------ | ------ |
| subject keys                                   | 25 140 | 49.3 % |
| — namespace of the FQN                         | 7 011  | 13.7 % |
| — short class name                             | 3 037  | 6.0 %  |
| — member (`::method`)                          | 1 517  | 3.0 %  |
| — file path                                    | 9 382  | 18.4 % |
| — `declaration:` prefix                        | 1 992  | 3.9 %  |
| `channel` field (243 occurrences, 22 distinct) | 11 211 | 22.0 % |
| — left half                                    | 4 932  | 9.7 %  |
| declaration ordinal                            | 0      | 0 %    |

The path is derivable from the FQN by PSR-4 for 165 of 166 declarations. The one
miss is `Qualimetrix\Analysis\Evidence\Coupling\ClassRfcData`, declared in
`RfcVisitor.php` — a second class in the file, which PSR-4 permits.

`MetricSubject::toCanonical()` is published as the `subject` field in the JSON
and HTML reports and documented in `website/docs/usage/output-formats.md`.
Nothing parses it back: no `fromCanonical` exists.

## Blast radius of a vocabulary change

Measured 2026-08-22 by grepping for `MetricName::` and for dotted rule names.

| Area                                                                   | What is affected                                                                                                                                                | Size                                                                       |
| ---------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------- |
| `src/Analysis`                                                         | the subject itself                                                                                                                                              | 58 files use `MetricName::`                                                |
| `src/Infrastructure` + `src/Reporting` + `src/Core`                    | DI configurators enumerate exact rule/collector roots; compiler passes; console name validators; formatters                                                     | 30 files carry dotted rule names                                           |
| `src/Reporting`                                                        | publishes `violationCode` as SARIF `ruleId`, GitLab `check_name`, Checkstyle `source`; `channel`/`rule`/`code` in JSON; the HTML template reads raw metric keys | 3 external contracts                                                       |
| `tests`                                                                | tests follow their owning subject, so they move with it                                                                                                         | 492 of 674 files under `tests/Analysis`; 234 files carry dotted rule names |
| `docs/internal/modular-architecture-manifest.json` + 20 generated TSVs | the manifest is the authority on every namespace owner; moving namespaces forces regeneration, gated by `composer architecture:check`                           | 21 artifacts                                                               |
| `qmx.yaml`, `qmx-baseline.json`, `qmx.yaml.example`, presets           | 26 rule keys in `qmx.yaml`; the baseline needs a format version and a mapping migration                                                                         | 4 artifacts                                                                |
| `website/docs`                                                         | rule and metric pages, both languages                                                                                                                           | 41 files                                                                   |
| `benchmarks/`                                                          | renamed metric keys invalidate collected data and the expected ranges of `benchmark:check`                                                                      | recalibration                                                              |

## Metrics without rules, rules without metrics

- `RfcCollector` produces `rfc`, `rfc_own`, `rfc_external`. No rule and no computed
  formula reads them; only `MetricHintCatalog` shows them. Kept deliberately as
  groundwork for a future rule.
- `WmcRule` lives in `Complexity/`, but `wmc` is derived in
  `Measurement/Aggregation/CallableToClassAggregator`, not in that family.

## The measurement level is encoded three times

1. channel name suffix — `coupling.cbo.class`
2. options class prefix — `ClassCboOptions`, `NamespaceCboOptions`,
   `MethodComplexityOptions`
3. `MetricSubject` kind

Nothing checks the three against each other.

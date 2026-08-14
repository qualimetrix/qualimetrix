# Inline source policy

`Analysis\\Policy\\Inline` owns source annotations: extraction, declaration
binding, threshold validation, and annotation suppression. Run parses and
measures a file, then calls the Inline-owned extraction contract once; it owns
no annotation policy state.

## Layout

```text
Inline/
├── Contract/
│   ├── Suppression/             # suppression value and type
│   ├── Threshold/               # annotation diagnostic value
│   ├── AnnotationSuppressionInterface.php
│   ├── AnnotationSuppressionResult.php
│   ├── SourceControlExtractorInterface.php
│   ├── SourceControls.php       # immutable extraction result
│   ├── SuppressionExtractor.php
│   ├── ThresholdOverrideExtractor.php
│   └── RuleValidatorMapFactory.php
├── Extraction/
│   ├── DeclarationControlBindings.php
│   └── SourceControlExtractor.php
├── Suppression/
│   └── SuppressionFilter.php   # internal annotation matching
└── ThresholdOverrideExtractionResult.php
```

## Public contracts

- `SourceControlExtractorInterface` promises source-annotation interpretation
  to the named Run consumer `FileProcessor`. It accepts the parsed AST,
  relative file path, callable measurement facts, and class measurement map.
- `SourceControls` returns the three ordered worker-safe lists: suppressions,
  threshold overrides, and threshold diagnostics. Suppression and diagnostic
  values stay with Inline; Finding owns the shared `ControlScope` and
  `ThresholdOverride` vocabulary that Inline produces and Run transports.
- `SuppressionExtractor` and `ThresholdOverrideExtractor` preserve the exact
  physical and declaration annotation syntax. `RuleValidatorMapFactory`
  supplies rule-specific threshold validation to sequential and worker paths.
- `AnnotationSuppressionInterface` exposes one stateless projection operation
  to Reporting. Its immutable result separates kept and suppressed findings.
- Internal `SuppressionFilter` implements annotation matching without exposing
  its indexes or incremental operations across the owner boundary.

`Extraction\\DeclarationControlBindings` is internal. It maps collected
declaration facts onto AST nodes while extracting controls and never crosses
the Run boundary or the serialized worker payload.
`Extraction\\SourceControlExtractor` is the private implementation of the Run
port and returns the immutable `SourceControls` result. Its class-level
`health.cohesion` exception records metric inapplicability: the one public
operation uses both collaborators, while its private static methods only
decompose that operation; TCC therefore has no public method pair to compare.

## Change recipe

When changing an inline annotation or its wire value:

1. update the Inline contract/value and its subject-owned unit tests;
2. preserve declaration-collision, physical-control, and diagnostic ordering;
3. exercise both `FileProcessor` and worker serialization round trips;
4. prove sequential and real parallel collection return identical controls;
5. update the manifest and generated architecture inventory in the publication
   package; never expose `Extraction` internals to Run.

## Definition of Done

- Run imports only Inline contracts and stores no policy state.
- Source controls survive PHP and igbinary worker round trips unchanged.
- Two sequential runs cannot retain a previous suppression or threshold set.
- Inline has no dependency on Baseline or Reporting.

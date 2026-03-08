# Coupling Rules

Coupling rules measure how tightly your classes depend on each other. When classes are tightly coupled, changing one class can break many others. Loosely coupled code is easier to test (you can isolate a class), easier to change (fewer side effects), and easier to reuse.

Think of coupling like wires connecting boxes. The more wires between two boxes, the harder it is to move one without disturbing the other.

---

## CBO -- Coupling Between Objects

**Rule ID:** `coupling.cbo`

### What it measures

CBO counts the total number of **other classes that this class is connected to**. A "connection" means either:

- This class **uses** another class (outgoing dependency, called "efferent coupling" or Ce), or
- Another class **uses** this class (incoming dependency, called "afferent coupling" or Ca)

CBO = Ca + Ce.

For example, if `UserService` uses `UserRepository`, `Logger`, `Validator`, and `Mailer`, and is used by `UserController` and `AdminController`, its CBO = 4 + 2 = 6.

### Thresholds

**Class level** (enabled by default):

| Level   | Threshold | Severity |
| ------- | --------- | -------- |
| Warning | > 14      | Warning  |
| Error   | > 20      | Error    |

**Namespace level** (enabled by default, requires at least 3 classes in the namespace):

| Level   | Threshold | Severity |
| ------- | --------- | -------- |
| Warning | > 14      | Warning  |
| Error   | > 20      | Error    |

### Example

```php
class ReportGenerator
{
    public function __construct(
        private UserRepository $userRepo,      // +1
        private OrderRepository $orderRepo,    // +1
        private ProductRepository $productRepo, // +1
        private Logger $logger,                // +1
        private CacheInterface $cache,         // +1
        private EventDispatcher $dispatcher,   // +1
        private TemplateEngine $templates,     // +1
        private PdfGenerator $pdf,             // +1
        private CsvExporter $csv,              // +1
        private EmailService $email,           // +1
        private TranslatorInterface $translator, // +1
        private ConfigProvider $config,        // +1
        private MetricsCollector $metrics,     // +1
        private SecurityChecker $security,     // +1
        private AuditLogger $audit,            // +1
    ) {}
    // Ce = 15, and if nothing depends on this class, CBO = 15 -> WARNING
}
```

### How to fix

- **Split the class.** A class with 15+ dependencies is doing too much. Extract groups of related dependencies into focused services (e.g., `ReportDataProvider`, `ReportExporter`).
- **Use interfaces** instead of concrete classes. This does not reduce CBO directly, but makes the coupling looser and more flexible.
- **Apply dependency injection.** Avoid creating dependencies inside the class with `new`. Inject them through the constructor so they can be replaced.
- **Consider the Facade pattern.** Wrap groups of related services behind a single interface.

### Configuration

```yaml
# aimd.yaml
rules:
  coupling.cbo:
    class:
      warning: 18
      error: 25
    namespace:
      enabled: true
      min_class_count: 5
```

```bash
bin/aimd analyze src/ --rule-opt="coupling.cbo:class.warning=18"
bin/aimd analyze src/ --rule-opt="coupling.cbo:class.error=25"
bin/aimd analyze src/ --rule-opt="coupling.cbo:namespace.min_class_count=5"
bin/aimd analyze src/ --rule-opt="coupling.cbo:namespace.enabled=false"
```

---

## Instability

**Rule ID:** `coupling.instability`

### What it measures

Instability measures the **direction of dependencies** for a class or namespace. It answers the question: "Does this class mostly depend on others, or do others mostly depend on it?"

The formula is:

```
I = Ce / (Ca + Ce)
```

Where:

- **Ca (Afferent coupling)** = how many other classes depend on THIS class (incoming)
- **Ce (Efferent coupling)** = how many classes THIS class depends on (outgoing)

The result is a number between 0.0 and 1.0:

- **I = 0.0 (maximally stable)** -- many classes depend on this one, but it depends on nothing. Like a foundation: you cannot move it without breaking everything above. Stable classes should be abstract (interfaces, abstract classes) so they are safe to depend on.
- **I = 1.0 (maximally unstable)** -- this class depends on many others, but nobody depends on it. Like a leaf on a tree: easy to change without affecting anything. Concrete implementations should typically be unstable.

!!! note "Why is high instability bad?"
    An instability close to 1.0 means the class has many outgoing dependencies and nobody depends on it. While this sounds "free to change," it also means the class is very sensitive to changes in its dependencies. If any of those dependencies change, this class might break.

### Thresholds

**Class level** (enabled by default):

| Level   | Threshold | Severity |
| ------- | --------- | -------- |
| Warning | >= 0.8    | Warning  |
| Error   | >= 0.95   | Error    |

**Namespace level** (enabled by default, requires at least 3 classes):

| Level   | Threshold | Severity |
| ------- | --------- | -------- |
| Warning | >= 0.8    | Warning  |
| Error   | >= 0.95   | Error    |

### Example

```php
// Highly unstable class (I ≈ 1.0): depends on 5 things, nobody depends on it
class DailyReportJob
{
    public function __construct(
        private UserRepository $users,
        private ReportGenerator $generator,
        private EmailService $email,
        private Logger $logger,
        private Clock $clock,
    ) {}
    // Ca = 0 (nothing depends on this)
    // Ce = 5 (depends on 5 classes)
    // I = 5 / (0 + 5) = 1.0 -> ERROR
}
```

### How to fix

- **Reduce outgoing dependencies.** Fewer `use` statements and constructor parameters mean lower Ce.
- **Introduce abstractions.** If the class is a service that others could benefit from, extract an interface. This increases Ca (other classes will depend on the interface) and reduces I.
- **Accept instability when it makes sense.** Entry points like console commands, controllers, and cron jobs are naturally unstable (high Ce, low Ca). Consider disabling the rule for these or raising the threshold.

### Configuration

```yaml
# aimd.yaml
rules:
  coupling.instability:
    class:
      max_warning: 0.9
      max_error: 1.0
    namespace:
      min_class_count: 5
```

```bash
bin/aimd analyze src/ --rule-opt="coupling.instability:class.max_warning=0.9"
bin/aimd analyze src/ --rule-opt="coupling.instability:class.max_error=1.0"
bin/aimd analyze src/ --rule-opt="coupling.instability:namespace.min_class_count=5"
```

---

## Distance from Main Sequence

**Rule ID:** `coupling.distance`

### What it measures

This rule checks the **balance between abstractness and stability** of a namespace (group of classes). It is based on the idea that:

- **Stable packages should be abstract.** If many classes depend on your package, it should consist of interfaces and abstract classes. That way, when you need to change behavior, you add a new implementation rather than modifying what others depend on.
- **Unstable packages should be concrete.** If your package depends on many others but nothing depends on it, it should contain concrete implementations. There is no point making it abstract if nobody is going to implement the abstractions.

The formula is:

```
D = |A + I - 1|
```

Where:

- **A (Abstractness)** = ratio of abstract classes and interfaces to total classes in the namespace (0.0 = all concrete, 1.0 = all abstract)
- **I (Instability)** = the instability metric described above

The result is a number between 0.0 and 1.0:

- **D = 0.0** -- the namespace sits on the "main sequence," meaning it has a balanced mix of abstractness and stability.
- **D = 1.0** -- the namespace is far from the ideal balance.

There are two bad zones:

- **Zone of Pain** (bottom-left): stable + concrete. Many classes depend on this package, but it is all concrete code. Any change will ripple outward. Solution: add interfaces.
- **Zone of Uselessness** (top-right): unstable + abstract. The package is full of abstractions that nobody implements. Dead weight.

### Thresholds

| Level   | Threshold | Severity |
| ------- | --------- | -------- |
| Warning | >= 0.3    | Warning  |
| Error   | >= 0.5    | Error    |

Only namespaces with at least 3 classes are analyzed (configurable via `minClassCount`).

### Example

Consider a namespace `App\Payment` with 10 classes:

- 0 interfaces or abstract classes (A = 0.0)
- Many incoming dependencies from other modules (I = 0.2, quite stable)
- D = |0.0 + 0.2 - 1| = 0.8 -- far from the main sequence (Error)

This namespace is in the **Zone of Pain**: it is stable (hard to change without breaking others) but has no abstractions (every change requires modifying concrete code).

### How to fix

- **For packages in the Zone of Pain:** Add interfaces. Extract contracts that other modules can depend on, while keeping implementations as details.
- **For packages in the Zone of Uselessness:** Remove unused abstractions or make them concrete. Abstract classes that nobody extends are overhead.
- **Aim for the main sequence:** Stable packages should be abstract; unstable packages should be concrete.

### Configuration

```yaml
# aimd.yaml
rules:
  coupling.distance:
    max_distance_warning: 0.4
    max_distance_error: 0.6
    min_class_count: 5
    include_namespaces:
      - App\Domain
      - App\Infrastructure
    exclude_namespaces:
      - App\Tests
```

```bash
bin/aimd analyze src/ --rule-opt="coupling.distance:max_distance_warning=0.4"
bin/aimd analyze src/ --rule-opt="coupling.distance:max_distance_error=0.6"
bin/aimd analyze src/ --rule-opt="coupling.distance:min_class_count=5"
```

By default, project namespaces are auto-detected from `composer.json` (`autoload.psr-4`).

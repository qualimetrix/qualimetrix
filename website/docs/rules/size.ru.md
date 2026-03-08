# Правила размера (Size)

Правила размера выявляют классы и пространства имен, которые стали слишком большими. Большие классы сложнее понять, протестировать и поддерживать. Эти правила устанавливают верхние границы допустимого размера.

---

## Количество методов (Method Count)

**Идентификатор правила:** `size.method-count`

### Что измеряет

Подсчитывает количество методов в классе. Класс со слишком большим количеством методов, вероятно, делает слишком много и должен быть разделен на более мелкие, сфокусированные классы.

### Пороговые значения

| Значение | Серьезность | Значение                                      |
|----------|-------------|-----------------------------------------------|
| 1--19    | OK          | Приемлемый размер класса                      |
| 20--29   | Warning     | Класс становится большим, подумайте о разделении |
| 30+      | Error       | Класс слишком большой, нужен рефакторинг      |

### Конфигурация

| Опция     | По умолчанию | Описание                                    |
|-----------|-------------|---------------------------------------------|
| `enabled` | `true`      | Включить или выключить правило              |
| `warning` | `20`        | Количество методов для предупреждения       |
| `error`   | `30`        | Количество методов для ошибки               |

```yaml
# aimd.yaml
rules:
  size.method-count:
    warning: 20
    error: 30
```

```bash
bin/aimd analyze src/ --rule-opt="size.method-count:warning=25"
bin/aimd analyze src/ --rule-opt="size.method-count:error=40"
```

### Пример

```php
class OrderService
{
    public function createOrder() { /* ... */ }
    public function updateOrder() { /* ... */ }
    public function deleteOrder() { /* ... */ }
    public function findOrder() { /* ... */ }
    public function listOrders() { /* ... */ }
    public function validateOrder() { /* ... */ }
    public function calculateTotal() { /* ... */ }
    public function applyDiscount() { /* ... */ }
    public function sendConfirmation() { /* ... */ }
    public function generateInvoice() { /* ... */ }
    public function processPayment() { /* ... */ }
    public function refundOrder() { /* ... */ }
    public function shipOrder() { /* ... */ }
    public function trackShipment() { /* ... */ }
    public function notifyCustomer() { /* ... */ }
    public function exportToCSV() { /* ... */ }
    public function importFromCSV() { /* ... */ }
    public function archiveOrder() { /* ... */ }
    public function restoreOrder() { /* ... */ }
    public function auditOrder() { /* ... */ }
    // ...и еще -- этот класс делает всё!
}
```

### Как исправить

1. **Определите группы методов.** Найдите кластеры методов, которые работают вместе (например, все методы оплаты, все методы уведомлений).

2. **Извлеките сервисные классы.** Переместите каждую группу в свой класс:

    ```php
    class OrderService       { /* create, update, delete, find */ }
    class OrderPricing       { /* calculateTotal, applyDiscount */ }
    class PaymentProcessor   { /* processPayment, refundOrder */ }
    class OrderNotifier      { /* sendConfirmation, notifyCustomer */ }
    class OrderExporter      { /* exportToCSV, importFromCSV */ }
    ```

3. **Используйте композицию.** Если исходный класс должен оркестрировать эти операции, внедрите новые сервисы как зависимости.

---

## Количество классов (Class Count)

**Идентификатор правила:** `size.class-count`

### Что измеряет

Подсчитывает количество классов в пространстве имен (пакете). Измеряется на уровне пространства имен, а не класса. Пространство имен со слишком большим количеством классов трудно обозревать, и его область ответственности, скорее всего, слишком широка.

### Пороговые значения

| Значение | Серьезность | Значение                                               |
|----------|-------------|--------------------------------------------------------|
| 1--14    | OK          | Сфокусированное пространство имен                     |
| 15--24   | Warning     | Пространство имен переполнено                         |
| 25+      | Error       | Нужно разделить на подпространства имен               |

### Конфигурация

| Опция     | По умолчанию | Описание                                      |
|-----------|-------------|-----------------------------------------------|
| `enabled` | `true`      | Включить или выключить правило                |
| `warning` | `15`        | Количество классов для предупреждения         |
| `error`   | `25`        | Количество классов для ошибки                 |

```yaml
# aimd.yaml
rules:
  size.class-count:
    warning: 15
    error: 25
```

```bash
bin/aimd analyze src/ --rule-opt="size.class-count:warning=20"
bin/aimd analyze src/ --rule-opt="size.class-count:error=30"
```

### Пример

```
App\Service\                  # 28 классов -- слишком много!
├── UserService.php
├── OrderService.php
├── PaymentService.php
├── NotificationService.php
├── ReportService.php
├── ... (еще 23 файла)
```

### Как исправить

1. **Сгруппируйте связанные классы в подпространства имен:**

    ```
    App\Service\User\           # 5 классов
    App\Service\Order\          # 6 классов
    App\Service\Payment\        # 4 класса
    App\Service\Notification\   # 3 класса
    ```

2. **Следуйте принципу единственной ответственности** на уровне пространства имен -- каждое пространство имен должно представлять одну связную концепцию.

---

## Количество свойств (Property Count)

**Идентификатор правила:** `size.property-count`

### Что измеряет

Подсчитывает количество свойств (полей) в классе. Класс с большим количеством свойств часто имеет слишком много ответственностей или хранит слишком много состояния.

!!! note "Примечание"
    Это правило использует строгое сравнение (`>` вместо `>=`). Класс с ровно 15 свойствами **не** вызовет предупреждение; нужно 16 или более.

### Пороговые значения

| Значение | Серьезность | Значение                                              |
|----------|-------------|-------------------------------------------------------|
| 1--15    | OK          | Приемлемое количество свойств                         |
| 16--20   | Warning     | Слишком много свойств, подумайте об извлечении объектов |
| 21+      | Error       | Значительно слишком много, нужен рефакторинг          |

### Конфигурация

| Опция                 | По умолчанию | Описание                                                               |
|-----------------------|-------------|------------------------------------------------------------------------|
| `enabled`             | `true`      | Включить или выключить правило                                         |
| `warning`             | `15`        | Количество свойств, выше которого выдается предупреждение              |
| `error`               | `20`        | Количество свойств, выше которого выдается ошибка                      |
| `excludeReadonly`     | `true`      | Пропускать `readonly` классы (DTO, value objects)                      |
| `excludePromotedOnly` | `true`     | Пропускать классы, где все свойства -- promoted-параметры конструктора  |

```yaml
# aimd.yaml
rules:
  size.property-count:
    warning: 15
    error: 20
    exclude_readonly: true
    exclude_promoted_only: true
```

```bash
bin/aimd analyze src/ --rule-opt="size.property-count:warning=18"
bin/aimd analyze src/ --rule-opt="size.property-count:error=25"
```

### Пример

```php
class ReportGenerator
{
    private string $title;
    private string $subtitle;
    private string $author;
    private \DateTimeInterface $createdAt;
    private string $format;
    private string $orientation;
    private float $marginTop;
    private float $marginBottom;
    private float $marginLeft;
    private float $marginRight;
    private string $headerText;
    private string $footerText;
    private string $fontFamily;
    private int $fontSize;
    private string $colorScheme;
    private bool $includeCharts;
    private bool $includeTables;
    private string $outputPath;
    // 18 свойств -- этот класс знает слишком много!
}
```

### Как исправить

1. **Извлеките value objects.** Сгруппируйте связанные свойства в небольшие, сфокусированные объекты:

    ```php
    class PageMargins
    {
        public function __construct(
            public readonly float $top,
            public readonly float $bottom,
            public readonly float $left,
            public readonly float $right,
        ) {}
    }

    class ReportStyle
    {
        public function __construct(
            public readonly string $fontFamily,
            public readonly int $fontSize,
            public readonly string $colorScheme,
        ) {}
    }

    class ReportGenerator
    {
        public function __construct(
            private ReportMetadata $metadata,
            private PageMargins $margins,
            private ReportStyle $style,
            private ReportOptions $options,
        ) {}
    }
    ```

2. **Ищите кластеры свойств** -- свойства, которые всегда используются вместе, являются кандидатами на извлечение.

!!! tip "Совет"
    Классы, где все свойства -- promoted-параметры конструктора (типично для DTO), по умолчанию исключены через `excludePromotedOnly`. Это позволяет избежать ложных срабатываний на классах вроде `CreateUserRequest(string $name, string $email, ...)`.

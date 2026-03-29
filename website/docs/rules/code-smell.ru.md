# Правила запахов кода (Code Smell)

Запахи кода -- это паттерны, которые указывают на возможные проблемы. Они не обязательно являются багами, но обозначают места, где код можно улучшить. Эти правила обнаруживают распространенные плохие практики, которых обычно стоит избегать.

Все правила запахов кода можно включать и выключать по отдельности.

---

## Булевые аргументы (Boolean Arguments)

**Идентификатор правила:** `code-smell.boolean-argument`
**Серьезность:** Warning

<!-- llms:skip-begin -->
### Что измеряет

Обнаруживает методы, принимающие параметры типа `bool`. Булевый аргумент обычно означает, что метод делает две разные вещи в зависимости от флага, что нарушает принцип единственной ответственности.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Пример

```php
// Плохо: что означает `true`? Трудно читать в месте вызова.
$user->save(true);

// Сигнатура метода раскрывает проблему:
public function save(bool $sendNotification): void
{
    // ...сохраняет пользователя...
    if ($sendNotification) {
        // ...отправляет уведомление...
    }
}
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Как исправить

1. **Разделите на два метода** с описательными именами:

    ```php
    public function save(): void { /* ... */ }
    public function saveAndNotify(): void { /* ... */ }
    ```

2. **Используйте enum,** если вариантов больше двух:

    ```php
    enum SaveMode {
        case Silent;
        case WithNotification;
        case WithAuditLog;
    }

    public function save(SaveMode $mode = SaveMode::Silent): void { /* ... */ }
    ```

---

<!-- llms:skip-end -->

## count() в цикле (count() in Loop)

**Идентификатор правила:** `code-smell.count-in-loop`
**Серьезность:** Warning

<!-- llms:skip-begin -->
### Что измеряет

Обнаруживает вызовы `count()` (или `sizeof()`) внутри условий цикла. Когда `count()` находится в условии цикла, он пересчитывается на каждой итерации, что расточительно.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Пример

```php
// Плохо: count() выполняется на каждой итерации
for ($i = 0; $i < count($items); $i++) {
    processItem($items[$i]);
}
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Как исправить

Сохраните результат count() в переменную перед циклом:

```php
// Хорошо: count() выполняется один раз
$itemCount = count($items);
for ($i = 0; $i < $itemCount; $i++) {
    processItem($items[$i]);
}

// Еще лучше: используйте foreach, когда возможно
foreach ($items as $item) {
    processItem($item);
}
```

---

<!-- llms:skip-end -->

## Отладочный код (Debug Code)

**Идентификатор правила:** `code-smell.debug-code`
**Серьезность:** Error

<!-- llms:skip-begin -->
### Что измеряет

Обнаруживает отладочные функции, оставленные в коде: `var_dump()`, `print_r()`, `debug_print_backtrace()`, `debug_zval_dump()` и подобные.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Пример

```php
public function processPayment(Order $order): void
{
    var_dump($order);          // забытый отладочный вывод
    print_r($order->items);   // забытый отладочный вывод

    // основная логика...
}
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Как исправить

Удалите все отладочные вызовы перед коммитом. Если нужно инспектировать данные:

- Используйте настоящий логгер (`$this->logger->debug(...)`)
- Используйте отладчик (Xdebug)
- Используйте инструменты профилирования

!!! warning "Внимание"
    Отладочный вывод может раскрыть конфиденциальную информацию (пароли, токены, персональные данные) конечным пользователям. Поэтому правило имеет уровень **Error**, а не Warning.

---

<!-- llms:skip-end -->

## Пустой catch (Empty Catch)

**Идентификатор правила:** `code-smell.empty-catch`
**Серьезность:** Error

<!-- llms:skip-begin -->
### Что измеряет

Обнаруживает блоки `catch`, которые полностью пусты -- перехватывают исключение и не делают с ним абсолютно ничего. Это беззвучно проглатывает ошибки, делая баги крайне трудными для диагностики.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Пример

```php
// Плохо: исключение беззвучно игнорируется
try {
    $this->sendEmail($user);
} catch (\Exception $e) {
    // ничего -- если отправка не удастся, никто не узнает
}
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Как исправить

1. **Залогируйте исключение:**

    ```php
    try {
        $this->sendEmail($user);
    } catch (\Exception $e) {
        $this->logger->error('Failed to send email', ['exception' => $e]);
    }
    ```

2. **Перебросьте как доменное исключение:**

    ```php
    try {
        $this->sendEmail($user);
    } catch (\Exception $e) {
        throw new NotificationFailedException('Email sending failed', previous: $e);
    }
    ```

3. **Обработайте ошибку явно,** если она ожидаема и восстановима.

---

<!-- llms:skip-end -->

## Подавление ошибок (Error Suppression)

**Идентификатор правила:** `code-smell.error-suppression`
**Серьезность:** Warning

<!-- llms:skip-begin -->
### Что измеряет

Обнаруживает использование оператора подавления ошибок `@`. Оператор `@` скрывает ошибки и предупреждения PHP, затрудняя поиск и исправление проблем.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Пример

```php
// Плохо: если файл не существует, вы не узнаете, почему дальше что-то ломается
$data = @file_get_contents('/path/to/file');

// Плохо: подавление предупреждений от функции
$result = @json_decode($input);
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Как исправить

Обрабатывайте ошибки явно:

```php
// Хорошо: проверяем перед вызовом
if (!file_exists($path)) {
    throw new FileNotFoundException($path);
}
$data = file_get_contents($path);

// Хорошо: используем функции обработки ошибок
$result = json_decode($input, flags: JSON_THROW_ON_ERROR);
```

---

<!-- llms:skip-end -->

## eval()

**Идентификатор правила:** `code-smell.eval`
**Серьезность:** Error

<!-- llms:skip-begin -->
### Что измеряет

Обнаруживает использование `eval()`, которая выполняет произвольный PHP-код из строки. Это серьезный риск безопасности: если пользовательский ввод достигнет `eval()`, злоумышленник может выполнить любой код на вашем сервере.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Пример

```php
// Плохо: уязвимость безопасности
$formula = $_GET['formula'];
$result = eval("return $formula;");

// Плохо: даже с "безопасным" вводом eval трудно отлаживать и поддерживать
eval('$config = ' . var_export($data, true) . ';');
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Как исправить

- **Используйте замыкания или вызываемые объекты** вместо генерации кода в виде строк.
- **Используйте паттерн Strategy** вместо динамического выполнения кода.
- **Используйте `json_decode()`** для парсинга данных, а не `eval()`.
- **Используйте шаблонизаторы** для генерации динамического вывода.

---

<!-- llms:skip-end -->

## exit() / die()

**Идентификатор правила:** `code-smell.exit`
**Серьезность:** Warning

<!-- llms:skip-begin -->
### Что измеряет

Обнаруживает использование `exit()` и `die()`. Эти функции немедленно завершают весь PHP-процесс, что:

- Предотвращает правильную обработку ошибок
- Делает код нетестируемым (PHPUnit не может перехватить `exit`)
- Обходит обработчики завершения и деструкторы

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Пример

```php
// Плохо: нетестируемо, нет обработки ошибок
if (!$user->isAdmin()) {
    die('Access denied');
}

// Плохо: предотвращает правильную обработку ответа
if ($error) {
    exit(1);
}
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Как исправить

Бросайте исключения вместо этого:

```php
// Хорошо: можно перехватить, протестировать и обработать
if (!$user->isAdmin()) {
    throw new AccessDeniedException('Access denied');
}
```

!!! note "Примечание"
    `exit()` допустим в точках входа CLI (например, `bin/console`), где он устанавливает код выхода процесса. Это правило в основном касается использования `exit`/`die` внутри логики приложения.

---

<!-- llms:skip-end -->

## goto

**Идентификатор правила:** `code-smell.goto`
**Серьезность:** Error

<!-- llms:skip-begin -->
### Что измеряет

Обнаруживает использование оператора `goto`. `goto` делает поток управления непредсказуемым -- читателю приходится искать целевую метку, которая может быть в любом месте функции. Это делает код очень трудным для чтения и отладки.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Пример

```php
// Плохо: спагетти-поток управления
function process(array $items): void
{
    foreach ($items as $item) {
        if ($item->isInvalid()) {
            goto cleanup;
        }
        // обработка элемента...
    }

    cleanup:
    // код очистки
}
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Как исправить

Используйте стандартные конструкции управления потоком:

```php
// Хорошо: понятный поток управления
function process(array $items): void
{
    foreach ($items as $item) {
        if ($item->isInvalid()) {
            $this->cleanup();
            return;
        }
        // обработка элемента...
    }
}
```

Используйте циклы, функции, ранние возвраты или исключения -- все они выражают намерение яснее, чем `goto`.

---

<!-- llms:skip-end -->

## Суперглобальные переменные (Superglobals)

**Идентификатор правила:** `code-smell.superglobals`
**Серьезность:** Warning

<!-- llms:skip-begin -->
### Что измеряет

Обнаруживает прямой доступ к суперглобальным переменным PHP: `$_GET`, `$_POST`, `$_REQUEST`, `$_SERVER`, `$_SESSION`, `$_COOKIE`, `$_FILES`, `$_ENV`.

Прямой доступ к суперглобальным переменным создает скрытые зависимости от глобального состояния, делая код трудным для тестирования и непредсказуемым.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Пример

```php
// Плохо: жестко связан с глобальным состоянием
class UserController
{
    public function register(): void
    {
        $name = $_POST['name'];
        $email = $_POST['email'];
        $ip = $_SERVER['REMOTE_ADDR'];
        // ...
    }
}
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Как исправить

Используйте внедрение зависимостей с объектами запроса:

```php
// Хорошо: зависимости явные и тестируемые
class UserController
{
    public function register(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $name = $body['name'];
        $email = $body['email'];
        $ip = $request->getServerParams()['REMOTE_ADDR'];
        // ...
    }
}
```

Распространенные абстракции запросов:

- **PSR-7:** `ServerRequestInterface` (фреймворко-независимый)
- **Symfony:** `Request` из HttpFoundation
- **Laravel:** `Illuminate\Http\Request`

---

<!-- llms:skip-end -->

## Избыточное внедрение в конструктор (Constructor Over-injection)

**Идентификатор правила:** `code-smell.constructor-overinjection`

<!-- llms:skip-begin -->
### Что измеряет

Обнаруживает конструкторы со слишком большим количеством внедрённых зависимостей. Длинный список параметров конструктора в кодовых базах с DI -- прямой сигнал нарушения принципа единственной ответственности (SRP): класс имеет слишком много коллабораторов.

<!-- llms:skip-end -->

### Пороговые значения

| Значение | Серьёзность | Что означает                                       |
| -------- | ----------- | -------------------------------------------------- |
| 8        | Warning     | Слишком много зависимостей, рассмотрите разделение |
| 12+      | Error       | Класс явно нарушает SRP                            |

<!-- llms:skip-begin -->
### Пример

```php
// Плохо: конструктор зависит от слишком многих сервисов
class OrderProcessor
{
    public function __construct(
        private UserRepository $users,
        private ProductRepository $products,
        private InventoryService $inventory,
        private PricingService $pricing,
        private TaxCalculator $tax,
        private ShippingService $shipping,
        private NotificationService $notifications,
        private AuditLogger $audit,
        private MetricsCollector $metrics,
    ) {}
}
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Как исправить

1. **Разделите класс** на меньшие, сфокусированные сервисы (например, `OrderValidator`, `OrderFulfiller`, `OrderNotifier`).
2. **Используйте Фасад или Медиатор** для группировки связанных зависимостей за единым интерфейсом.
3. **Внедрите Command Bus** -- вместо прямого внедрения обработчиков отправляйте команды.

<!-- llms:skip-end -->

### Конфигурация

```yaml
# qmx.yaml
rules:
  code-smell.constructor-overinjection:
    warning: 8
    error: 12
```

```bash
bin/qmx check src/ --rule-opt="code-smell.constructor-overinjection:warning=6"
```

---

## Длинный список параметров (Long Parameter List)

**Идентификатор правила:** `code-smell.long-parameter-list`

<!-- llms:skip-begin -->
### Что измеряет

Обнаруживает методы и функции со слишком большим количеством параметров. Длинный список параметров затрудняет правильный вызов метода, его тестирование и часто указывает на то, что метод делает слишком много. Рассмотрите использование объекта параметров или разделение метода.

<!-- llms:skip-end -->

### Пороговые значения

**Стандартные пороги** (методы и функции):

| Значение | Серьёзность | Что означает                                    |
| -------- | ----------- | ----------------------------------------------- |
| 4        | Warning     | Стоит сгруппировать параметры                   |
| 6+       | Error       | Слишком много параметров, необходим рефакторинг |

**Пороги для VO конструкторов** (readonly класс, все promoted, пустое тело):

| Значение | Серьёзность | Что означает                                |
| -------- | ----------- | ------------------------------------------- |
| 8        | Warning     | Стоит разделить value object                |
| 12+      | Error       | Слишком много полей, разделите value object |

<!-- llms:skip-begin -->

#### Исключение для конструкторов Value Object

Readonly классы с promoted-параметрами конструктора (PHP 8.2+) рассматриваются как Value Objects.
`readonly class` с 6 promoted-свойствами и без логики в теле — это корректный дизайн, типизированный
контейнер данных, а не признак плохой декомпозиции. Для таких конструкторов используются отдельные,
повышенные пороговые значения.

**Критерии определения VO** (все должны быть истинны):

1. Класс имеет модификатор `readonly`
2. Все параметры конструктора — promoted-свойства (имеют модификатор видимости)
3. Тело конструктора пустое (нет операторов)

Если **любое** условие не выполнено, применяются стандартные пороги.

### Пример

```php
// Плохо: слишком много параметров, трудно запомнить порядок
public function createUser(
    string $name,
    string $email,
    string $phone,
    string $address,
    string $city,
    string $country,
    string $zipCode,
): User {
    // ...
}
```

```php
// OK: readonly VO с promoted-свойствами использует повышенные пороги
final readonly class CreateUserRequest
{
    public function __construct(
        public string $name,
        public string $email,
        public string $phone,
        public string $address,
        public string $city,
        public string $country,
        public string $zipCode,  // 7 параметров — ниже vo-warning=8
    ) {}
}
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Как исправить

1. **Используйте объект параметров (DTO):**

    ```php
    final readonly class CreateUserRequest
    {
        public function __construct(
            public string $name,
            public string $email,
            public string $phone,
            public Address $address,
        ) {}
    }

    public function createUser(CreateUserRequest $request): User { /* ... */ }
    ```

2. **Разделите метод,** если параметры относятся к разным ответственностям.

<!-- llms:skip-end -->

### Конфигурация

```yaml
# qmx.yaml
rules:
  code-smell.long-parameter-list:
    warning: 4        # стандартные методы/функции
    error: 6
    vo-warning: 8     # readonly VO конструкторы
    vo-error: 12
```

```bash
bin/qmx check src/ --rule-opt="code-smell.long-parameter-list:warning=5"
bin/qmx check src/ --rule-opt="code-smell.long-parameter-list:error=8"
bin/qmx check src/ --rule-opt="code-smell.long-parameter-list:vo-warning=10"
bin/qmx check src/ --rule-opt="code-smell.long-parameter-list:vo-error=15"
```

---

## Идентичные подвыражения (Identical Sub-expression)

**Идентификатор правила:** `code-smell.identical-subexpression`
**Серьезность:** Warning

<!-- llms:skip-begin -->
### Что измеряет

Обнаруживает идентичные подвыражения, указывающие на ошибки копирования или логические баги. Правило выявляет четыре паттерна:

1. **Одинаковые операнды в бинарных операциях** -- одно и то же выражение по обе стороны оператора (например, `$a === $a`, `$a - $a`, `$a && $a`).
2. **Дублирующиеся условия в цепочках if/elseif** -- одно и то же условие проверяется дважды, что делает вторую ветку мёртвым кодом.
3. **Одинаковые ветки тернарного оператора** -- тернарный оператор, в котором ветки «истина» и «ложь» совпадают, что делает условие бессмысленным.
4. **Дублирующиеся условия в match** -- повторяющиеся условия в выражении `match`, где выполнится только первый вариант.

Операторы с легитимным использованием одинаковых операндов не помечаются: `+`, `*`, `.`, `&`, `|`, `<<`, `>>`.

Выражения с побочными эффектами (вызовы функций, вызовы методов и т.д.) исключаются, так как последовательные вызовы могут возвращать разные результаты.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Пример

```php
class OrderService
{
    public function validate(Order $order): bool
    {
        // Плохо: одинаковые операнды -- всегда true, вероятно опечатка
        if ($order->total === $order->total) {
            // ...
        }

        // Плохо: вычитание значения из самого себя -- всегда 0
        $diff = $order->price - $order->price;

        // Плохо: дублирующееся условие -- вторая ветка -- мёртвый код
        if ($order->isPaid()) {
            return true;
        } elseif ($order->isPaid()) {
            return false;
        }

        // Плохо: одинаковые ветки тернарного оператора -- условие бессмысленно
        $status = $order->isActive() ? 'pending' : 'pending';

        // Плохо: дублирующееся условие match
        return match ($order->type) {
            'retail' => $this->handleRetail($order),
            'wholesale' => $this->handleWholesale($order),
            'retail' => $this->handleSpecial($order),  // никогда не выполнится
        };
    }
}
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Как исправить

Это почти всегда баги -- проверьте каждое вхождение и исправьте предполагаемую логику:

1. **Одинаковые операнды:** Одна из сторон обычно является опечаткой. Замените на правильную переменную:

    ```php
    // Было: $order->total === $order->total (всегда true)
    // Исправление: сравнить с ожидаемым значением
    if ($order->total === $order->expectedTotal) {
        // ...
    }
    ```

2. **Дублирующиеся условия:** Удалите дублирующую ветку или исправьте условие:

    ```php
    if ($order->isPaid()) {
        return true;
    } elseif ($order->isRefunded()) {
        return false;
    }
    ```

3. **Одинаковые ветки тернарного оператора:** Либо условие не нужно, либо одна из веток содержит неверное значение:

    ```php
    $status = $order->isActive() ? 'active' : 'pending';
    ```

4. **Дублирующиеся условия match:** Удалите дубликат или исправьте значение условия.

---

<!-- llms:skip-end -->

## Неиспользуемые приватные члены (Unused Private Members)

**Идентификатор правила:** `code-smell.unused-private`
**Серьезность:** Warning

<!-- llms:skip-begin -->
### Что измеряет

Обнаруживает приватные методы, свойства и константы, которые объявлены, но нигде не используются внутри класса. Неиспользуемые приватные члены -- это мёртвый код: они создают шум, увеличивают когнитивную нагрузку и могут указывать на незавершённый рефакторинг.

Правило учитывает граничные случаи:

- **Магические методы:** классы с `__call`/`__callStatic` пропускают проверку методов; классы с `__get`/`__set` пропускают проверку свойств
- **Промоутнутые свойства:** свойства, продвигаемые через конструктор, отслеживаются корректно
- **Анонимные классы:** приватные члены анонимных классов изолированы и не «утекают» в родительский класс
- **Исключённые типы:** интерфейсы, трейты и перечисления не анализируются
- **Паттерны доступа:** распознаёт `$this->method()`, `self::method()`, `static::method()`, доступ к свойствам и константам
- **Разрешение трейтов:** вызовы методов, определённых в трейтах, используемых тем же классом (в том же файле), распознаются, что снижает количество ложных срабатываний

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Пример

```php
class OrderService
{
    private string $unusedField = '';  // нигде не читается и не записывается

    private const LEGACY_LIMIT = 100;  // нигде не используется

    public function process(Order $order): void
    {
        // ... использует $order, но не обращается к $unusedField и LEGACY_LIMIT
    }

    private function oldHelper(): void  // нигде не вызывается
    {
        // осталось от предыдущей реализации
    }
}
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Как исправить

- **Удалите** неиспользуемый член, если это действительно мёртвый код.
- **Измените видимость** на `protected` или `public`, если член используется подклассами или внешним кодом.
- Если член намеренно сохранён для будущего использования, подавите предупреждение с помощью `@qmx-ignore code-smell.unused-private`.

---

<!-- llms:skip-end -->

## Недостижимый код (Unreachable Code)

**Идентификатор правила:** `code-smell.unreachable-code`

<!-- llms:skip-begin -->
### Что измеряет

Обнаруживает код, который никогда не может быть выполнен, потому что находится после терминального оператора (`return`, `throw`, `exit`/`die`, `continue`, `break`, `goto`). Мёртвый код добавляет шум, сбивает с толку читателей и может указывать на логическую ошибку.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Пример

```php
public function process(Order $order): string
{
    if ($order->isPaid()) {
        return 'processed';
    }

    return 'pending';

    // Плохо: этот код никогда не выполнится
    $this->logger->info('Processing complete');
    $this->notify($order);
}
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Как исправить

Удалите недостижимый код. Если код должен был выполняться, исправьте поток управления:

```php
public function process(Order $order): string
{
    if ($order->isPaid()) {
        $this->logger->info('Processing complete');
        $this->notify($order);
        return 'processed';
    }

    return 'pending';
}
```

<!-- llms:skip-end -->

### Конфигурация

```yaml
# qmx.yaml
rules:
  code-smell.unreachable-code:
    warning: 1
    error: 1
```

```bash
bin/qmx check src/ --rule-opt="code-smell.unreachable-code:warning=1"
bin/qmx check src/ --rule-opt="code-smell.unreachable-code:error=1"
```

---

## Конфигурация

Все правила запахов кода имеют одинаковую простую конфигурацию -- просто включить или выключить:

```yaml
# qmx.yaml
rules:
  code-smell.boolean-argument:
    enabled: true
  code-smell.debug-code:
    enabled: true
  code-smell.empty-catch:
    enabled: true
  code-smell.eval:
    enabled: false    # выключить, если есть легитимное использование eval
  code-smell.exit:
    enabled: true
  code-smell.goto:
    enabled: true
  code-smell.superglobals:
    enabled: true
  code-smell.constructor-overinjection:
    warning: 8
    error: 12
  code-smell.count-in-loop:
    enabled: true
  code-smell.error-suppression:
    enabled: true
  code-smell.long-parameter-list:
    warning: 4
    error: 6
  code-smell.unreachable-code:
    warning: 1
    error: 1
  code-smell.unused-private:
    enabled: true
  code-smell.identical-subexpression:
    enabled: true
```

Также можно отключить отдельные правила через опцию `--disable-rule` в CLI:

```bash
# Отключить конкретное правило
bin/qmx check src/ --disable-rule=code-smell.exit

# Отключить все правила запахов кода сразу (сопоставление по префиксу)
bin/qmx check src/ --disable-rule=code-smell
```

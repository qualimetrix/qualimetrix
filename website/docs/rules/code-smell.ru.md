# Правила запахов кода (Code Smell)

Запахи кода -- это паттерны, которые указывают на возможные проблемы. Они не обязательно являются багами, но обозначают места, где код можно улучшить. Эти правила обнаруживают распространенные плохие практики, которых обычно стоит избегать.

Все правила запахов кода можно включать и выключать по отдельности.

---

## Булевые аргументы (Boolean Arguments)

**Идентификатор правила:** `code-smell.boolean-argument`
**Серьезность:** Warning

### Что измеряет

Обнаруживает методы, принимающие параметры типа `bool`. Булевый аргумент обычно означает, что метод делает две разные вещи в зависимости от флага, что нарушает принцип единственной ответственности.

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

## count() в цикле (count() in Loop)

**Идентификатор правила:** `code-smell.count-in-loop`
**Серьезность:** Warning

### Что измеряет

Обнаруживает вызовы `count()` (или `sizeof()`) внутри условий цикла. Когда `count()` находится в условии цикла, он пересчитывается на каждой итерации, что расточительно.

### Пример

```php
// Плохо: count() выполняется на каждой итерации
for ($i = 0; $i < count($items); $i++) {
    processItem($items[$i]);
}
```

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

## Отладочный код (Debug Code)

**Идентификатор правила:** `code-smell.debug-code`
**Серьезность:** Error

### Что измеряет

Обнаруживает отладочные функции, оставленные в коде: `var_dump()`, `print_r()`, `debug_print_backtrace()`, `debug_zval_dump()` и подобные.

### Пример

```php
public function processPayment(Order $order): void
{
    var_dump($order);          // забытый отладочный вывод
    print_r($order->items);   // забытый отладочный вывод

    // основная логика...
}
```

### Как исправить

Удалите все отладочные вызовы перед коммитом. Если нужно инспектировать данные:

- Используйте настоящий логгер (`$this->logger->debug(...)`)
- Используйте отладчик (Xdebug)
- Используйте инструменты профилирования

!!! warning "Внимание"
    Отладочный вывод может раскрыть конфиденциальную информацию (пароли, токены, персональные данные) конечным пользователям. Поэтому правило имеет уровень **Error**, а не Warning.

---

## Пустой catch (Empty Catch)

**Идентификатор правила:** `code-smell.empty-catch`
**Серьезность:** Error

### Что измеряет

Обнаруживает блоки `catch`, которые полностью пусты -- перехватывают исключение и не делают с ним абсолютно ничего. Это беззвучно проглатывает ошибки, делая баги крайне трудными для диагностики.

### Пример

```php
// Плохо: исключение беззвучно игнорируется
try {
    $this->sendEmail($user);
} catch (\Exception $e) {
    // ничего -- если отправка не удастся, никто не узнает
}
```

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

## Подавление ошибок (Error Suppression)

**Идентификатор правила:** `code-smell.error-suppression`
**Серьезность:** Warning

### Что измеряет

Обнаруживает использование оператора подавления ошибок `@`. Оператор `@` скрывает ошибки и предупреждения PHP, затрудняя поиск и исправление проблем.

### Пример

```php
// Плохо: если файл не существует, вы не узнаете, почему дальше что-то ломается
$data = @file_get_contents('/path/to/file');

// Плохо: подавление предупреждений от функции
$result = @json_decode($input);
```

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

## eval()

**Идентификатор правила:** `code-smell.eval`
**Серьезность:** Error

### Что измеряет

Обнаруживает использование `eval()`, которая выполняет произвольный PHP-код из строки. Это серьезный риск безопасности: если пользовательский ввод достигнет `eval()`, злоумышленник может выполнить любой код на вашем сервере.

### Пример

```php
// Плохо: уязвимость безопасности
$formula = $_GET['formula'];
$result = eval("return $formula;");

// Плохо: даже с "безопасным" вводом eval трудно отлаживать и поддерживать
eval('$config = ' . var_export($data, true) . ';');
```

### Как исправить

- **Используйте замыкания или вызываемые объекты** вместо генерации кода в виде строк.
- **Используйте паттерн Strategy** вместо динамического выполнения кода.
- **Используйте `json_decode()`** для парсинга данных, а не `eval()`.
- **Используйте шаблонизаторы** для генерации динамического вывода.

---

## exit() / die()

**Идентификатор правила:** `code-smell.exit`
**Серьезность:** Warning

### Что измеряет

Обнаруживает использование `exit()` и `die()`. Эти функции немедленно завершают весь PHP-процесс, что:

- Предотвращает правильную обработку ошибок
- Делает код нетестируемым (PHPUnit не может перехватить `exit`)
- Обходит обработчики завершения и деструкторы

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

## goto

**Идентификатор правила:** `code-smell.goto`
**Серьезность:** Error

### Что измеряет

Обнаруживает использование оператора `goto`. `goto` делает поток управления непредсказуемым -- читателю приходится искать целевую метку, которая может быть в любом месте функции. Это делает код очень трудным для чтения и отладки.

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

## Суперглобальные переменные (Superglobals)

**Идентификатор правила:** `code-smell.superglobals`
**Серьезность:** Warning

### Что измеряет

Обнаруживает прямой доступ к суперглобальным переменным PHP: `$_GET`, `$_POST`, `$_REQUEST`, `$_SERVER`, `$_SESSION`, `$_COOKIE`, `$_FILES`, `$_ENV`.

Прямой доступ к суперглобальным переменным создает скрытые зависимости от глобального состояния, делая код трудным для тестирования и непредсказуемым.

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

## Избыточное внедрение в конструктор (Constructor Over-injection)

**Идентификатор правила:** `code-smell.constructor-overinjection`

### Что измеряет

Обнаруживает конструкторы со слишком большим количеством внедрённых зависимостей. Длинный список параметров конструктора в кодовых базах с DI -- прямой сигнал нарушения принципа единственной ответственности (SRP): класс имеет слишком много коллабораторов.

### Пороговые значения

| Значение | Серьёзность | Что означает                                       |
| -------- | ----------- | -------------------------------------------------- |
| 8        | Warning     | Слишком много зависимостей, рассмотрите разделение |
| 12+      | Error       | Класс явно нарушает SRP                            |

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

### Как исправить

1. **Разделите класс** на меньшие, сфокусированные сервисы (например, `OrderValidator`, `OrderFulfiller`, `OrderNotifier`).
2. **Используйте Фасад или Медиатор** для группировки связанных зависимостей за единым интерфейсом.
3. **Внедрите Command Bus** -- вместо прямого внедрения обработчиков отправляйте команды.

### Конфигурация

```yaml
# aimd.yaml
rules:
  code-smell.constructor-overinjection:
    warning: 8
    error: 12
```

```bash
bin/aimd check src/ --rule-opt="code-smell.constructor-overinjection:warning=6"
```

---

## Класс данных (Data Class)

**Идентификатор правила:** `code-smell.data-class`
**Серьезность:** Warning

### Что измеряет

Обнаруживает классы с высокой публичной поверхностью (WOC -- Weight of Class, % публичных методов), но низкой сложностью (WMC -- Weighted Methods per Class). Такие классы в основном предоставляют данные через геттеры/сеттеры без инкапсуляции значимого поведения. Основано на метриках Lanza & Marinescu.

Намеренные DTO исключаются: readonly-классы, классы только с promoted properties и классы, помеченные как data-классы, не вызывают предупреждений.

### Пороговые значения

| Метрика         | Условие  | По умолчанию |
| --------------- | -------- | ------------ |
| WOC             | ≥ порога | 80%          |
| WMC             | ≤ порога | 10           |
| Минимум методов | ≥        | 3            |

### Пример

```php
// Помечается: высокая публичная поверхность, низкая сложность, не readonly
class UserProfile
{
    private string $name;
    private string $email;
    private string $phone;

    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): void { $this->email = $email; }
    public function getPhone(): string { return $this->phone; }
    public function setPhone(string $phone): void { $this->phone = $phone; }
}

// Не помечается: намеренный DTO (readonly)
readonly class UserDTO
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}
```

### Как исправить

1. **Инкапсулируйте поведение** -- перенесите операции, использующие эти данные, внутрь самого класса.
2. **Превратите в DTO** -- если класс намеренно является только данными, сделайте его `readonly` для выражения намерения.
3. **Объедините с потребителем** -- если класс только хранит данные для другого класса, рассмотрите встраивание.

### Конфигурация

```yaml
# aimd.yaml
rules:
  code-smell.data-class:
    woc_threshold: 80
    wmc_threshold: 10
    min_methods: 3
    exclude_readonly: true
    exclude_promoted_only: true
```

---

## God Class (Божественный класс)

**Идентификатор правила:** `code-smell.god-class`
**Серьезность:** Warning (3+ критерия) / Error (все оцениваемые критерии)

### Что измеряет

Обнаруживает God-классы -- чрезмерно сложные, крупные классы с низкой связностью. Использует мульти-критериальный подход Lanza & Marinescu: класс помечается, когда он соответствует минимум `minCriteria` из до 4 оцениваемых критериев.

Критерии (всего 4):

| Критерий  | Условие  | По умолчанию | Описание                                |
| --------- | -------- | ------------ | --------------------------------------- |
| WMC       | ≥ порога | 47           | Взвешенные методы класса                |
| LCOM4     | ≥ порога | 3            | Недостаток связности                    |
| TCC       | < порога | 0.33         | Тесная связность класса (инвертировано) |
| Class LOC | ≥ порога | 300          | Физические строки кода                  |

Недостающие метрики уменьшают количество оцениваемых критериев. Если оцениваемых критериев меньше `minCriteria`, нарушение не создаётся.

### Пример

```php
// Помечается: высокий WMC, высокий LCOM, низкий TCC, большой размер
class ApplicationManager
{
    // 400+ LOC, 25 методов, обрабатывает:
    // - аутентификацию пользователей
    // - управление сессиями
    // - маршрутизацию запросов
    // - форматирование ответов
    // - обработку ошибок
    // - логирование
    // - кеширование
}
```

### Как исправить

1. **Извлеките классы по ответственности** -- определите кластеры методов, работающих с одними данными, и выделите их в отдельные классы.
2. **Применяйте принцип единственной ответственности** -- каждый класс должен иметь одну причину для изменения.
3. **Используйте композицию** -- замените иерархии наследования составными объектами.

### Конфигурация

```yaml
# aimd.yaml
rules:
  code-smell.god-class:
    wmc_threshold: 47
    lcom_threshold: 3
    tcc_threshold: 0.33
    class_loc_threshold: 300
    min_criteria: 3
    min_methods: 3
    exclude_readonly: true
```

---

## Длинный список параметров (Long Parameter List)

**Идентификатор правила:** `code-smell.long-parameter-list`

### Что измеряет

Обнаруживает методы и функции со слишком большим количеством параметров. Длинный список параметров затрудняет правильный вызов метода, его тестирование и часто указывает на то, что метод делает слишком много. Рассмотрите использование объекта параметров или разделение метода.

### Пороговые значения

| Значение | Серьёзность | Что означает                                    |
| -------- | ----------- | ----------------------------------------------- |
| 4        | Warning     | Стоит сгруппировать параметры                   |
| 6+       | Error       | Слишком много параметров, необходим рефакторинг |

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

### Конфигурация

```yaml
# aimd.yaml
rules:
  code-smell.long-parameter-list:
    warning: 4
    error: 6
```

```bash
bin/aimd check src/ --rule-opt="code-smell.long-parameter-list:warning=5"
bin/aimd check src/ --rule-opt="code-smell.long-parameter-list:error=8"
```

---

## Идентичные подвыражения (Identical Sub-expression)

**Идентификатор правила:** `code-smell.identical-subexpression`
**Серьезность:** Warning

### Что измеряет

Обнаруживает идентичные подвыражения, указывающие на ошибки копирования или логические баги. Правило выявляет четыре паттерна:

1. **Одинаковые операнды в бинарных операциях** -- одно и то же выражение по обе стороны оператора (например, `$a === $a`, `$a - $a`, `$a && $a`).
2. **Дублирующиеся условия в цепочках if/elseif** -- одно и то же условие проверяется дважды, что делает вторую ветку мёртвым кодом.
3. **Одинаковые ветки тернарного оператора** -- тернарный оператор, в котором ветки «истина» и «ложь» совпадают, что делает условие бессмысленным.
4. **Дублирующиеся условия в match** -- повторяющиеся условия в выражении `match`, где выполнится только первый вариант.

Операторы с легитимным использованием одинаковых операндов не помечаются: `+`, `*`, `.`, `&`, `|`, `<<`, `>>`.

Выражения с побочными эффектами (вызовы функций, вызовы методов и т.д.) исключаются, так как последовательные вызовы могут возвращать разные результаты.

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

## Неиспользуемые приватные члены (Unused Private Members)

**Идентификатор правила:** `code-smell.unused-private`
**Серьезность:** Warning

### Что измеряет

Обнаруживает приватные методы, свойства и константы, которые объявлены, но нигде не используются внутри класса. Неиспользуемые приватные члены -- это мёртвый код: они создают шум, увеличивают когнитивную нагрузку и могут указывать на незавершённый рефакторинг.

Правило учитывает граничные случаи:

- **Магические методы:** классы с `__call`/`__callStatic` пропускают проверку методов; классы с `__get`/`__set` пропускают проверку свойств
- **Промоутнутые свойства:** свойства, продвигаемые через конструктор, отслеживаются корректно
- **Анонимные классы:** приватные члены анонимных классов изолированы и не «утекают» в родительский класс
- **Исключённые типы:** интерфейсы, трейты и перечисления не анализируются
- **Паттерны доступа:** распознаёт `$this->method()`, `self::method()`, `static::method()`, доступ к свойствам и константам
- **Разрешение трейтов:** вызовы методов, определённых в трейтах, используемых тем же классом (в том же файле), распознаются, что снижает количество ложных срабатываний

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

### Как исправить

- **Удалите** неиспользуемый член, если это действительно мёртвый код.
- **Измените видимость** на `protected` или `public`, если член используется подклассами или внешним кодом.
- Если член намеренно сохранён для будущего использования, подавите предупреждение с помощью `@aimd-ignore code-smell.unused-private`.

---

## Недостижимый код (Unreachable Code)

**Идентификатор правила:** `code-smell.unreachable-code`

### Что измеряет

Обнаруживает код, который никогда не может быть выполнен, потому что находится после терминального оператора (`return`, `throw`, `exit`/`die`, `continue`, `break`, `goto`). Мёртвый код добавляет шум, сбивает с толку читателей и может указывать на логическую ошибку.

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

### Конфигурация

```yaml
# aimd.yaml
rules:
  code-smell.unreachable-code:
    warning: 1
    error: 1
```

```bash
bin/aimd check src/ --rule-opt="code-smell.unreachable-code:warning=1"
bin/aimd check src/ --rule-opt="code-smell.unreachable-code:error=1"
```

---

## Конфигурация

Все правила запахов кода имеют одинаковую простую конфигурацию -- просто включить или выключить:

```yaml
# aimd.yaml
rules:
  code-smell.boolean-argument:
    enabled: true
  code-smell.data-class:
    woc_threshold: 80
    wmc_threshold: 10
    min_methods: 3
    exclude_readonly: true
    exclude_promoted_only: true
  code-smell.debug-code:
    enabled: true
  code-smell.empty-catch:
    enabled: true
  code-smell.eval:
    enabled: false    # выключить, если есть легитимное использование eval
  code-smell.exit:
    enabled: true
  code-smell.god-class:
    wmc_threshold: 47
    lcom_threshold: 3
    tcc_threshold: 0.33
    class_loc_threshold: 300
    min_criteria: 3
    min_methods: 3
    exclude_readonly: true
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
bin/aimd check src/ --disable-rule=code-smell.exit

# Отключить все правила запахов кода сразу (сопоставление по префиксу)
bin/aimd check src/ --disable-rule=code-smell
```

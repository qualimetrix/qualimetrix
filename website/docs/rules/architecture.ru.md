# Правила архитектуры (Architecture)

Правила архитектуры выявляют структурные проблемы в кодовой базе, которые могут привести к кошмарам при поддержке. Эти проблемы часто незаметны в повседневной работе, но причиняют значительную боль, когда нужно провести рефакторинг, протестировать или развернуть части приложения независимо.

---

## Циклические зависимости (Circular Dependencies)

**Идентификатор правила:** `architecture.circular-dependency`

<!-- llms:skip-begin -->
### Что измеряет

Обнаруживает ситуации, когда классы зависят друг от друга по кругу. Зависимость означает, что один класс использует другой (через внедрение в конструктор, вызовы методов, указания типов и т.д.).

**Прямой цикл (размер 2):**

```
OrderService --> PaymentService --> OrderService
```

OrderService использует PaymentService, а PaymentService использует OrderService. Ни один из них не может существовать без другого.

**Транзитивный цикл (размер 3+):**

```
A --> B --> C --> A
```

A зависит от B, B зависит от C, а C зависит обратно от A. Петля длиннее, но проблема та же.

<!-- llms:skip-end -->

### Почему это важно

Циклические зависимости вызывают реальные проблемы:

- **Невозможно тестировать изолированно.** Чтобы протестировать класс A, нужен класс B, которому нужен класс C, которому снова нужен A.
- **Невозможно развертывать независимо.** Если пакеты A, B и C образуют цикл, они должны всегда развертываться вместе.
- **Жесткая связанность.** Изменения в любом классе цикла могут сломать все остальные классы в цикле.
- **Труднее понять.** Нет четкого "верха" или "низа" -- нельзя читать код в линейном порядке.

<!-- llms:skip-begin -->
### Пороговые значения

| Тип цикла                | Серьезность | Значение                                     |
| ------------------------ | ----------- | -------------------------------------------- |
| Прямой (размер 2)        | Error       | Два класса напрямую зависят друг от друга    |
| Транзитивный (размер 3+) | Warning     | Более длинная цепочка классов образует петлю |

!!! note "Примечание"
    Прямые циклы (A зависит от B, B зависит от A) по умолчанию отмечаются как **Error**, потому что они представляют наиболее жесткую связанность. Транзитивные циклы отмечаются как **Warning**, так как их обычно легче разорвать.
<!-- llms:skip-end -->

### Настройки

| Опция           | По умолчанию | Описание                                               |
| --------------- | ------------ | ------------------------------------------------------ |
| `enabled`       | `true`       | Включить или выключить правило                         |
| `maxCycleSize`  | `0`          | Максимальный размер цикла для отчета (0 = все размеры) |
| `directAsError` | `true`       | Считать прямые циклы (размер 2) ошибками               |

### Пример конфигурации

```yaml
# qmx.yaml
rules:
  architecture.circular-dependency:
    maxCycleSize: 5        # игнорировать очень большие циклы
    directAsError: true    # прямые циклы -- ошибки
```

<!-- llms:skip-begin -->
### Пример

```php
// OrderService.php
class OrderService
{
    public function __construct(
        private PaymentService $paymentService,  // зависит от PaymentService
    ) {}

    public function createOrder(Cart $cart): Order
    {
        $order = new Order($cart);
        $this->paymentService->charge($order);
        return $order;
    }

    public function getOrderTotal(int $orderId): float
    {
        // ...
        return $total;
    }
}

// PaymentService.php
class PaymentService
{
    public function __construct(
        private OrderService $orderService,  // зависит от OrderService -- ЦИКЛ!
    ) {}

    public function charge(Order $order): void
    {
        $total = $this->orderService->getOrderTotal($order->id);
        // обработка платежа...
    }
}
```

`OrderService` зависит от `PaymentService`, а `PaymentService` зависит от `OrderService`. Это прямой цикл размера 2.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Как исправить

1. **Введите интерфейс (инверсия зависимостей).** Пусть один класс зависит от абстракции, а не от конкретного класса:

    ```php
    interface OrderTotalProviderInterface
    {
        public function getOrderTotal(int $orderId): float;
    }

    class OrderService implements OrderTotalProviderInterface
    {
        public function __construct(
            private PaymentService $paymentService,
        ) {}

        public function getOrderTotal(int $orderId): float { /* ... */ }
    }

    class PaymentService
    {
        public function __construct(
            private OrderTotalProviderInterface $totalProvider,  // нет цикла!
        ) {}
    }
    ```

2. **Вынесите общую логику в третий класс.** Если обоим классам нужны одни и те же данные, извлеките их:

    ```php
    class OrderRepository
    {
        public function getTotal(int $orderId): float { /* ... */ }
    }

    // Оба сервиса зависят от OrderRepository, а не друг от друга
    ```

3. **Используйте события.** Вместо прямых вызовов генерируйте событие, на которое подписывается другой сервис:

    ```php
    class OrderService
    {
        public function createOrder(Cart $cart): Order
        {
            $order = new Order($cart);
            $this->eventDispatcher->dispatch(new OrderCreated($order));
            return $order;
        }
    }

    // PaymentService подписан на OrderCreated -- нет прямой зависимости
    ```

!!! tip "Совет"
    Используйте опцию `maxCycleSize`, чтобы сначала сосредоточиться на самых критичных циклах. Прямые циклы (размер 2) легче всего исправить и они наиболее вредны. Начните с них, затем переходите к более крупным циклам.

<!-- llms:skip-end -->

---

## Нарушения слоёв (Layer Violations)

**Идентификатор правила:** `architecture.layer-violation`

<!-- llms:skip-begin -->
### Что измеряет

Обнаруживает зависимости между **именованными слоями** проекта, которые не разрешены архитектурной политикой.

Слои объявляются как **упорядоченный список** записей `name`/`patterns`. Каждый класс проекта относится **не более чем к одному** слою на основании совпадения по неймспейсу — если FQN класса совпадает с паттернами нескольких слоёв, **побеждает первый по порядку объявления** (тот же механизм, что в deptrac, ArchUnit, `.gitignore`, Apache). Для каждой грани графа зависимостей (`extends`, `implements`, тип-хинт, вызов метода и т.д.) правило вычисляет слой источника и слой цели; если грань пересекает два объявленных слоя и allow-list политики этого направления не разрешает — фиксируется нарушение.

Концы вне слоёв (класс, не подходящий ни под один объявленный шаблон) по умолчанию молча игнорируются — это позволяет внедрять правило постепенно: начать с самых важных слоёв и расширять покрытие со временем.

<!-- llms:skip-end -->

### Почему это важно

Слоистая архитектура — это контракт: каждому слою разрешено зависеть от фиксированного набора других. Когда контракт размывается, проблемы накапливаются:

- **Реализация просачивается через границы.** Контроллеры лезут в репозитории, сервисы обходят домен, репозитории вызывают инфраструктуру. Каждый "сокращённый путь" облегчает следующий.
- **Рефакторинг становится опасным.** Перенос класса ломает код там, где никто не ожидал. "Радиус поражения" растёт неограниченно.
- **Тесты перестают быть изолированными.** Юнит-тесту сервиса вдруг требуется слой контроллеров из-за случайной зависимости вверх по стеку.
- **Архитектурные документы лгут.** На диаграмме написано "Controller -> Service -> Repository", а реальные грани образуют сетку. Новички сначала учат диаграмму, потом учат, что кодовая база её игнорирует.

Объявление слоёв в YAML и их проверка в CI превращают архитектурную диаграмму в то, что сборка может верифицировать.

<!-- llms:skip-begin -->
### Настройки

`architecture.layers` — это **упорядоченный список** записей слоёв. У каждой записи есть `name` и список `patterns`. Если FQN класса совпадает с паттернами нескольких слоёв, **побеждает первый по порядку объявления** — тот же механизм, что в deptrac, ArchUnit, `.gitignore`, Apache.

```yaml
# qmx.yaml
architecture:
  layers:
    - name: controller
      patterns: ['App\Controller\**']
    - name: service
      patterns: ['App\Service\**']
    - name: repository
      patterns: ['App\Repository\**']
    - name: domain
      patterns: ['App\Domain\**']
    - name: doctrine
      patterns: ['Doctrine\**']        # вендорный слой как полноправный

  allow:
    controller: [service]                 # контроллерам можно только сервисы
    service:    [domain, repository]      # сервисам можно репозитории и домен
    repository: [domain, doctrine]        # репозиториям -- домен и Doctrine
    domain:     []                        # домен самодостаточен

  # Необязательно. Что делать с гранями, источник или цель которых не попали ни в один слой.
  # См. раздел "Режимы покрытия (coverage)".
  coverage: ignore
```

Шаблоны поддерживают и префиксное сопоставление (без подстановок, например `App\Controller`), и glob-сопоставление (`*`, `**`, `?`, `[…]`). Зависимости внутри одного слоя всегда разрешены (изоляция подмодулей намеренно вынесена за рамки MVP).

**Порядок и catch-all-идиома.** Порядок объявления значим. Сначала указывайте **узкие** слои, потом **широкие** — `App\Service\Internal\**` до `App\Service\**`. Чтобы захватить всё оставшееся, объявите финальный слой с паттерном `**`:

```yaml
architecture:
  layers:
    - name: service
      patterns: ['App\Service\**']
    - name: catchall
      patterns: ['**']                # ловит каждый оставшийся класс
  allow:
    service:  [catchall]
    catchall: []
```

Catch-all-слой заменяет старую идиому `coverage: warn` для сценария "покажи всё, что я ещё не классифицировал". Механизм `architecture.coverage` по-прежнему работает (см. "Режимы покрытия" ниже), но при наличии catch-all-слоя он обычно не нужен.

**Семантика слияния YAML.** Если и пресет, и проектный конфиг определяют `architecture.layers`, **последний источник заменяет весь список целиком** — порядок задаёт намерения пользователя, а слияние двух упорядоченных списков тихо это намерение бы разрушило. Карта `architecture.allow` по-прежнему сливается по слою-источнику, а скаляр `architecture.coverage` переопределяется поздним источником.

#### Пример конфигурации с вендорным и общим слоями

```yaml
architecture:
  layers:
    - name: domain
      patterns: ['App\Domain\**']
    - name: app
      patterns: ['App\Application\**']
    - name: infra
      patterns: ['App\Infrastructure\**']
    - name: web
      patterns: ['App\UserInterface\Web\**']
    - name: cli
      patterns: ['App\UserInterface\Cli\**']
    - name: symfony
      patterns: ['Symfony\**']
    - name: doctrine
      patterns: ['Doctrine\**']

  allow:
    domain:   []
    app:      [domain]
    infra:    [domain, app, doctrine]
    web:      [app, symfony]
    cli:      [app, symfony]
    # symfony и doctrine отсутствуют -- это "листовые" вендорные слои, обходить которые никто не вправе
```

<!-- llms:skip-end -->

### Принадлежность за пределами namespace-паттернов

Phase 1 определяла принадлежность к слою исключительно по совпадению FQN класса с `patterns`. Phase 2 добавляет ещё четыре критерия — `suffix`, `attributes`, `implements`, `extends` — и переключатель `match: any | all`, управляющий их комбинированием. По умолчанию `any`, чтобы правило встречало унаследованный код там, где конвенции непоследовательны (`*Repository`, живущий в `App\Service\`, всё равно остаётся репозиторием).

| Критерий     | Срабатывает, когда…                                                                                   |
| ------------ | ----------------------------------------------------------------------------------------------------- |
| `patterns`   | FQN класса соответствует одному из перечисленных glob-паттернов (поведение Phase 1).                  |
| `suffix`     | Короткое имя класса оканчивается одной из перечисленных строк (например, `Repository`, `Controller`). |
| `attributes` | Класс помечен одним из перечисленных PHP-атрибутов по FQN (с учётом use-statement-резолвинга).        |
| `implements` | Класс реализует один из перечисленных интерфейсов по FQN — напрямую или транзитивно.                  |
| `extends`    | Один из перечисленных классов по FQN присутствует где-либо в цепочке родителей.                       |

Внутри одного критерия списки всегда объединяются как OR (`attributes: [A, B]` означает «имеет A или B»). `match` управляет тем, как комбинируются критерии **разных** видов.

```yaml
# Дружественный к миграции дефолт (match: any)
- name: repository
  patterns: ['App\Repository\**']
  suffix: ['Repository']
  implements: ['Doctrine\Persistence\ObjectRepository']
  # Принадлежит, если класс живёт в App\Repository ИЛИ оканчивается на Repository,
  # ИЛИ реализует ObjectRepository.
```

```yaml
# Строгая конвенция (match: all)
- name: command-handler
  match: all
  attributes: ['App\Messenger\AsCommandHandler']
  suffix: ['Handler']
  patterns: ['App\Handler\**']
  # Принадлежит только если все три условия выполнены одновременно.
```

```yaml
# Сочетание extends и implements
- name: domain-aggregate
  match: all
  extends: ['App\Domain\AggregateRoot']
  implements: ['App\Domain\HasIdentity']
```

Опущенный критерий считается **тривиально удовлетворённым** при `match: all` — не нужно писать пустое `patterns: []` чтобы его выключить. Имена атрибутов должны быть **полностью квалифицированы** (парсер откажется от голого `Entity`); `implements` и `extends` обходят цепочку супертипов, поэтому объявление базового интерфейса или класса покрывает всех потомков без перечисления.

### Шаблонные слои

Перечислять `domain-Order`, `domain-Inventory`, `domain-Billing`, … в YAML перестаёт масштабироваться, как только в проекте появляется больше горстки bounded contexts. Phase 2 позволяет одной записи слоя нести **переменную захвата** (capture variable) в имени и паттернах; после фазы Collection движок проходит по обнаруженному множеству классов, фиксирует, какие наборы значений (binding tuples) реально появляются, и создаёт по одному конкретному слою на каждый набор — никогда не делая декартова произведения.

```yaml
architecture:
  layers:
    - name: 'domain-{module}'
      patterns: ['App\Module\{module}\Domain\**']
    - name: 'app-{module}'
      patterns: ['App\Module\{module}\Application\**']
    - name: shared-kernel
      patterns: ['App\Shared\**']

  allow:
    'domain-*': [shared-kernel]
    'app-*':
      - 'domain-*'      # ПЕРМИССИВНО — любой app-* может зависеть от любого domain-*
      - shared-kernel
```

Конкретные слои из шаблона появляются на позиции шаблона в объявленном списке, в лексикографическом порядке захваченных значений. Селекторы в allow-list для развёрнутых слоёв используют существующую glob-форму (`'domain-*': [...]`).

#### Грамматика переменных захвата

- Ссылка имеет вид `{name}`, где `name` соответствует `[A-Za-z_][A-Za-z0-9_]*` (как PHP-идентификатор). Имена **регистрозависимы**.
- Захваченное значение по умолчанию матчит **один сегмент namespace** — `[^\\]+`, без обратных слэшей. Регистр сохраняется ровно в том виде, в котором значение появляется в FQN класса.
- Для многосегментного захвата используйте явную форму `{name:**}` — она матчит один или более сегментов.
- Переменные в шаблоне имени ОБЯЗАНЫ присутствовать как минимум в одном capture-producing критерии. Повторное использование одной и той же переменной в разных критериях привязывает её к одному значению (co-binding в пределах записи слоя).
- Переменные в разных записях слоёв независимы — глобального namespace переменных нет.
- Имена слоёв и паттерны не могут содержать литеральные `*`, `?`, `[`, `{`, `}` вне синтаксиса селектора — эти символы зарезервированы.
- Несбалансированные скобки (`'domain-{module'`) отклоняются на этапе загрузки конфигурации с `ConfigLoadException`, а не молча трактуются как exact-match.

#### Same-instance allows (capture-binding в allow-list)

Wildcard-allow вида `'app-*': ['domain-*']` позволяет `app-Order` зависеть от каждого `domain-X`, разрушая изоляцию bounded contexts. Phase 2 вводит **capture-binding** для этого случая:

```yaml
allow:
  'app-{m}':
    - 'domain-{m}'      # только same-{m} — app-Order может использовать domain-Order, НЕ domain-Inventory
    - shared-kernel
```

`{m}` со стороны источника устанавливает binding; `{m}` со стороны цели требует **то же** захваченное значение. Имя переменной локально для записи — `{m}` здесь не связан ни с каким `{m}` в других местах.

Запись с wildcard на обеих сторонах вроде `'domain-*': ['domain-*']` всё ещё легальна, но эмитит config-load **warning** (`architecture.warning`) — почти наверняка вы имели в виду `'domain-{m}': ['domain-{m}']`. Чтобы заглушить warning, когда all-to-all действительно намерен, переключитесь на long-form и поставьте `allow_cross_instance: true`:

```yaml
allow:
  'domain-*':
    - target: 'domain-*'
      allow_cross_instance: true   # подтверждение — любой domain-* может зависеть от любого domain-*
```

#### Лимиты раскрытия

Кумулятивное раскрытие по всем шаблонам ограничено `architecture.max_expanded_layers` (по умолчанию **500**). Патологически широкие шаблоны, превышающие предел, отклоняются на стадии раскрытия с понятной ошибкой (шаблон, итоговое количество, текущий предел). Поднимайте предел явно, когда монорепо легитимно содержит больше bounded contexts, чем дефолт:

```yaml
architecture:
  max_expanded_layers: 2000
```

### Исключение поддеревьев внутри слоя (`exclude:`)

Слой может нести блок `exclude:` той же формы, что и положительные критерии (`patterns`, `suffix`, `attributes`, `implements`, `extends`). Классы, попавшие под exclude-блок, удаляются из слоя независимо от положительной принадлежности — `exclude:` это жёсткий фильтр, который запускается после положительных критериев.

```yaml
- name: service
  patterns: ['App\Service\**']
  exclude:
    patterns: ['App\Service\Legacy\**']
    suffix: ['LegacyService']
    match: any                 # дефолт — класс исключается, если совпал ХОТЯ БЫ ОДИН exclude-критерий
```

`exclude.match: all` тоже поддерживается — полезно для узких случаев «исключить суффикс X только внутри namespace Y». Блок должен содержать как минимум один критерий (пустой `exclude:` — ошибка конфигурации). Для шаблонных слоёв exclude-критерии могут ссылаться на **те же** переменные захвата, что и имя слоя (`exclude: { patterns: ['App\Module\{module}\Generated\**'] }`) — они фильтруют внутри same-binding-инстанса. Exclude не может вводить новые переменные захвата, не появляющиеся в имени слоя.

При declaration-order matching того же эффекта часто можно добиться, объявив более узкий слой раньше. `exclude:` правильный инструмент, когда исключённое поддерево должно остаться **по-настоящему неклассифицированным** (чтобы провалиться в catch-all или диагностику покрытия) или когда положительные критерии смешивают `patterns` с `suffix`/`implements`/`extends` и одна ранняя запись не может чисто выразить вырез.

### Ограничение разрешённых зависимостей по типу связи (`relations:`)

Allow-list Phase 1 отвечает «может ли A зависеть от B?» через yes/no. Long-form-цель allow в Phase 2 добавляет необязательный whitelist `relations:`, ограничивающий, **как** зависимость может быть выражена.

```yaml
allow:
  domain:
    - target: contracts
      relations: [implements, extends]    # только наследование — никаких method calls или инстанцирования
    - target: vendor
      relations: [extends]                # только сабклассинг вендорных типов
```

Голые allow-записи (`allow: { domain: [contracts] }`) сохраняют семантику «любая связь разрешена» — полная обратная совместимость.

Доступные токены связи приходят из двух источников. **Прямые значения** зеркалят `Qualimetrix\Core\Dependency\DependencyType`:

```
extends, implements, trait_use,
new,
static_call, static_property_fetch, class_const_fetch,
type_hint, property_type, intersection_type, union_type,
catch, instanceof,
attribute
```

**Алиасы** — это сокращения configuration-уровня, раскрывающиеся в составляющие прямые значения:

| Алиас            | Раскрывается в                                                  |
| ---------------- | --------------------------------------------------------------- |
| `inheritance`    | `extends`, `implements`, `trait_use`                            |
| `static_access`  | `static_call`, `static_property_fetch`, `class_const_fetch`     |
| `type_reference` | `type_hint`, `property_type`, `intersection_type`, `union_type` |
| `runtime_check`  | `catch`, `instanceof`                                           |

`attribute` стоит особняком — у него нет группы. Алиасы и прямые значения можно смешивать в одном `relations:`-списке; после раскрытия дубликаты убираются. Прямые значения валидируются против `DependencyType::cases()` рефлективно, поэтому добавление нового вида зависимости в коллектор автоматически становится принимаемым в YAML без релиза.

Когда несколько allow-целей в одном источнике резолвятся в один и тот же целевой слой (например, через перекрывающиеся glob-селекторы), их разрешения **объединяются (UNION)**. Если хоть одна совпадающая запись использует bare/short-form (без `relations:`), объединение становится «все связи разрешены» — short-form доминирует.

> **Замечание.** В коллекторе сейчас нет вида связи instance method-call — только `static_call`. Отслеживайте instance-вызовы через более широкий алиас `type_reference`, если ваша политика должна их ограничивать.

### Режимы покрытия (coverage)

`architecture.coverage` определяет, что делать с гранями, где источник или цель не относятся ни к одному объявленному слою. Это независимо от самого `architecture.layer-violation`: диагностики покрытия публикуются под отдельным именем правила `architecture.coverage`, поэтому их можно бейзлайнить, подавлять и фильтровать отдельно.

| Режим              | Поведение                                                                                                                                                                                           |
| ------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ignore` (default) | Грани вне слоёв молча пропускаются. Позволяет внедрять правило постепенно, без шума.                                                                                                                |
| `warn`             | Одна сводная диагностика `architecture.coverage` за прогон с severity `Info`, со списком примеров неклассифицированных классов. Информационная; при дефолтном `fail_on: warning` прогон не валится. |
| `error`            | То же самое, но с severity `Error` — подходит для жёстких CI-гейтов, когда вы хотите покрыть весь код.                                                                                              |

<!-- llms:skip-begin -->
Сообщение диагностики выглядит так:

```
Architecture coverage: 12 edge(s) with unmatched source layer, 5 edge(s) with unmatched target layer,
3 class(es) outside all declared layers.
Examples of unclassified classes: App\Legacy\Foo, App\Legacy\Bar, App\Legacy\Baz. ...
```

Чтобы убрать диагностику для известного набора неклассифицированных классов, объявите catch-all слой, покрывающий их (или согласитесь с пропуском, оставив `coverage: ignore`).
<!-- llms:skip-end -->

### Диагностика недостижимого слоя

`architecture.unreachable-layer` (severity `Info`) публикуется один раз на каждый объявленный слой — или на каждый конкретный инстанс, развёрнутый из шаблона, — чьи паттерны не совпали ни с одним классом за прогон. Три возможные причины:

1. **Слой перекрыт более широким, объявленным раньше.** Паттерн вроде `'**'` или `'App\**'` перед более узким захватывает все классы первым.
2. **Паттерн не совпадает ни с одним классом в анализируемой кодовой базе.** Слой объявлен для неймспейса, которого ещё нет — или неймспейс был переименован.
3. **DTO-слой без исходящих зависимостей, в котором пока нет классов.** Подсчёт попаданий идёт по всем проанализированным классам (не по графу зависимостей), поэтому слои с классами, но без исходящих зависимостей, всё равно набирают попадания — этот случай возникает только когда слой реально не содержит ни одного класса.

Для развёрнутых из шаблона слоёв per-instance вариант означает, что конкретный binding tuple был создан, но все классы-кандидаты для этого инстанса перекрыты более ранним слоем или удалены блоком `exclude:`.

Поскольку severity `Info`, диагностика не валит прогон по умолчанию. Установите `fail_on: info`, чтобы получить строгое поведение в CI. Используйте [`qmx debug:layer-assignment <class>`](#debug-layer-assignment), чтобы инспектировать конкретные классы при разборе.

### Диагностика пустого шаблона

`architecture.empty-template` (severity `Warning`) публикуется один раз на каждый шаблонный слой, развернувшийся в **ноль** конкретных инстансов — обычно из-за опечатки в шаблоне паттерна, исключённого модуля или односегментного `{var}`, использованного там, где binding охватывает несколько сегментов namespace (используйте `{var:**}` для cross-segment-захватов).

Severity намеренно `Warning`, а не `Info`: шаблон, развернувшийся в ноль инстансов, **тихо отключает** связанную с ним политику, и этот режим отказа заслуживает внимания. Три типичные причины:

1. **Опечатка в шаблоне паттерна.** `App\Modul\{module}\Domain\**` вместо `App\Module\{module}\Domain\**` — ни один класс не совпадает, ни один инстанс не создаётся.
2. **Исключённые модули.** Каждый класс-кандидат удалён блоком `exclude:`, через `exclude_paths` или просто находится в неанализируемой директории.
3. **Односегментный захват, охватывающий разделители namespace.** `App\{path}\Domain\**`, где `path` должен захватить `Module\Order` (два сегмента). Переключитесь на `{path:**}`, чтобы разрешить cross-segment-захваты.

Дефолтный `fail_on: error` не валит прогон на warnings. Переключитесь на `fail_on: warning` (или строже), если хотите, чтобы CI блокировал пустые шаблоны.

### Диагностика потенциального затенения

`architecture.potential-shadow` (severity `Info`) ловит тихий режим отказа declaration-order-сопоставления: когда класс матчится несколькими слоями, побеждает только первый, и более ранние слои могут тихо отбирать классы, которые пользователь рассчитывал отдать более позднему, более узкому слою.

Обнаружение **доказательное** (evidence-based). Правило обходит каждый класс, собирает все слои, чьи паттерны совпали, и фиксирует пары `(assigned, shadowed)`, которые реально встречаются в коде. Это ловит каждое реальное затенение, какой бы формы паттерны ни были — наложение префиксов (`App\**\Foo` затеняет `App\Service\**`), кража по суффиксу (`**\*Service` затеняет `App\Domain\**`), любое другое пересечение.

На каждую пару `(assigned, shadowed)` публикуется одна диагностика с примером до 5 FQN классов (отсортированных лексикографически). Вывод **детерминирован между прогонами** — список пар сортируется перед публикацией, поэтому CI-диффы стабильны.

Решение — одно из двух:
- Переставить слои так, чтобы более специфичный был объявлен раньше (часто именно это и имелось в виду), или
- Сузить более широкий паттерн так, чтобы слои больше не пересекались.

Используйте [`qmx debug:layer-assignment <class>`](#debug-layer-assignment) для проверки исправления по конкретному классу.

### Инспекция назначения слоя для одного класса { #debug-layer-assignment }

Когда класс попадает в неожиданный слой — или когда нужно проверить исправление диагностики `architecture.unreachable-layer` или `architecture.potential-shadow` — используйте команду `debug:layer-assignment` для покласовой инспекции:

```bash
bin/qmx debug:layer-assignment 'App\Service\Foo'
bin/qmx debug:layer-assignment 'App\Service\Foo' --config qmx.yaml
```

Команда делегирует тот же `LayerRegistry::resolveAll()`, который использует runtime-правило, — поэтому назначение, которое она показывает, в точности совпадает с тем, что `architecture.layer-violation` увидит во время анализа: параллельной реализации сопоставления, которая могла бы разойтись с runtime, не существует. Команда обходит сконфигурированные слои в declaration order, показывает слой, к которому класс отнесён, и перечисляет все остальные слои, чьи паттерны тоже совпали бы (потенциальный источник затенения, если бы они были объявлены раньше).

Пример вывода для однозначно отнесённого класса:

```
Class: App\Service\UserService

  Assigned to: service
    Matching pattern: App\Service\**

  Would also match (in declaration order):
    (none — the assignment is unique)
```

Пример вывода для затенённого класса:

```
Class: App\Service\Foo

  Assigned to: any-foo
    Matching pattern: App\**\Foo

  Would also match (in declaration order):
    - service (pattern: 'App\Service\**')

  Diagnostic hint:
    Class is shadowed: would have matched 'service' if 'any-foo' was declared later.
    See architecture.potential-shadow diagnostic for the broader picture.
```

Коды выхода соответствуют стандартному соглашению: `0` для любого информационного результата (включая "класс не соответствует ни одному объявленному слою"), `2` для некорректного ввода (пустой или некорректный FQN), `1` для ошибок загрузки конфигурации.

### Настройки

| Опция      | По умолчанию | Описание                                                                                                                                        |
| ---------- | ------------ | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| `enabled`  | `true`       | Включить/выключить правило. При выключении правило не обходит граф зависимостей. Также правило является no-op, если `architecture.layers` пуст. |
| `severity` | `warning`    | Severity для каждого зарегистрированного нарушения. Допустимо: `warning`, `error`.                                                              |

```yaml
rules:
  architecture.layer-violation:
    enabled: true
    severity: error
```

CLI-алиас `--layer-violation` переключает опцию `enabled`, как и у других правил архитектуры.

<!-- llms:skip-begin -->
### Пример

**Запрещено — контроллер обращается напрямую к репозиторию:**

```php
// src/Controller/UserController.php
namespace App\Controller;

use App\Repository\UserRepository;   // ПЛОХО: controller -> repository
use Symfony\Component\HttpFoundation\Response;

final class UserController
{
    public function __construct(private UserRepository $users) {}

    public function show(int $id): Response
    {
        return new Response($this->users->find($id)->getName());
    }
}
```

С политикой `controller: [service]` это даёт по одному нарушению на каждое место использования (тип-хинт в конструкторе плюс любые вызовы методов) под `architecture.layer-violation`.

**Разрешено — пройти через сервисный слой:**

```php
// src/Controller/UserController.php
namespace App\Controller;

use App\Service\UserPresenter;       // OK: controller -> service
use Symfony\Component\HttpFoundation\Response;

final class UserController
{
    public function __construct(private UserPresenter $presenter) {}

    public function show(int $id): Response
    {
        return new Response($this->presenter->render($id));
    }
}

// src/Service/UserPresenter.php
namespace App\Service;

use App\Repository\UserRepository;   // OK: service -> repository

final class UserPresenter
{
    public function __construct(private UserRepository $users) {}

    public function render(int $id): string
    {
        return $this->users->find($id)->getName();
    }
}
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Подавление нарушений

`@qmx-ignore` для класса или метода работает так же, как и для любого другого правила:

```php
/**
 * Временный шорткат на время внедрения нового презентера.
 *
 * @qmx-ignore architecture.layer-violation reason="legacy hotfix, см. тикет #1234"
 */
final class LegacyAdminController
{
    public function __construct(private UserRepository $users) {}
    // ...
}
```

Чтобы подавить все нарушения слоёв в проекте, используйте стандартную префиксную форму: `@qmx-ignore architecture` (заодно перекроет `architecture.circular-dependency`) или `@qmx-ignore architecture.layer-violation`.

Baseline-файл хранит нарушения слоёв по слою-источнику, слою-цели, FQN целевого класса и типу зависимости — не по номеру строки — поэтому переформатирование или перенос места использования внутри того же файла baseline не ломает. Несколько мест использования одной и той же запрещённой грани в baseline схлопываются в одну запись.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Особенности реализации

- **Пять критериев принадлежности, дефолт `match: any`.** Принадлежность определяется через `patterns`, `suffix`, `attributes`, `implements`, `extends` — комбинируются для каждой записи через `match: any` (дефолт) или `match: all`. Дефолт даёт правилу шанс встретить унаследованный код, где конвенции имён и неймспейсов непоследовательны. См. [ADR 0007](https://github.com/qualimetrix/qualimetrix/blob/main/docs/adr/0007-architecture-rules-phase-2-design.md).
- **Один слой на класс, declaration-order-сопоставление.** Каждый класс попадает не более чем в один слой. Если паттерны двух слоёв подходят к одному классу, побеждает **слой, объявленный раньше** в `architecture.layers` (тот же механизм, что у deptrac, ArchUnit, `.gitignore`, Apache). Никакой specificity нет — порядок и есть инструмент пользователя для выражения намерений, и движок его не оспаривает. См. [ADR 0006](https://github.com/qualimetrix/qualimetrix/blob/main/docs/adr/0006-architecture-rules-declaration-order.md).
- **Шаблоны разворачиваются по наблюдаемым binding tuples, после Collection.** Шаблонный слой вроде `'domain-{module}'` разворачивается стадией `LayerExpansionStage` (между Collection и RuleExecution), производя один конкретный `LayerDefinition` на каждый binding tuple, реально наблюдаемый в коде, — никогда декартова произведения различных значений. Capture-binding в allow-list (`'app-{m}': ['domain-{m}']`) поставляется в том же релизе, что и сами шаблоны, не как follow-up. См. [ADR 0007](https://github.com/qualimetrix/qualimetrix/blob/main/docs/adr/0007-architecture-rules-phase-2-design.md).
- **`relations:` — это whitelist; алиасы раскрываются рефлективно.** Long-form-цели allow принимают список `relations:`, ограничивающий, какие виды `DependencyType` разрешены. Прямые значения валидируются против `DependencyType::cases()` рефлективно, поэтому добавление нового вида зависимости в коллектор автоматически становится принимаемым в YAML. `forbid_relations:` нет — whitelist-only исключает неоднозначность резолвинга и стоимость поддержки параллельного enum.
- **Вендорные неймспейсы — полноправные слои.** Объявите слой `doctrine` или `symfony` с паттернами `Doctrine\**` / `Symfony\**`, чтобы писать политику против вендорных граней (например, "Doctrine может использовать только репозиторий"). Вендорные слои ведут себя идентично проектным.
- **Зависимости внутри одного слоя всегда разрешены** в MVP. Изоляция подмодулей внутри слоя отложена на Phase 2.
- **Гранулярность отчётности — на каждое место использования.** Каждая запрещённая грань в `Qualimetrix\Analysis\Collection\Dependency\DependencyGraph` даёт одно нарушение. Если класс нарушает политику через пять разных вызовов методов — получите пять нарушений. По identity в baseline они схлопываются в одну запись (см. "Подавление нарушений" выше).
- **Концы вне слоёв молча игнорируются** для целей layer-violation. Их количество отдельно публикуется через режим `coverage`.
- **Включено по умолчанию, но без слоёв ничего не делает.** Правило стартует с `enabled: true` и сразу выходит из `analyze()`, если `architecture.layers` пусто. Поэтому проекты без архитектурной конфигурации не платят за наличие правила.
- **Защитные сети, а не ошибки неоднозначности.** Прежний алгоритм на основе specificity отбраковывал неоднозначные конфигурации при загрузке. При declaration-order-сопоставлении неоднозначности не существует — порядок её разрешает — но пользователь всё ещё может **перепутать порядок**. Две диагностики severity `Info` ловят это: `architecture.unreachable-layer` (слой ничего не захватил) и `architecture.potential-shadow` (более ранний слой тихо отобрал классы у более позднего). См. отдельные разделы выше.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Ограничения и планы

- **Нет `forbid_relations:`.** Phase 2 — whitelist-only: `relations:` перечисляет, что разрешено, всё остальное неявно запрещено. Ключевое слово `forbid_relations:` отклоняется как избыточное; если возникнет реальный запрос, его можно добавить позже без поломки whitelist-пользователей.
- **Нет вида связи instance method-call.** Коллектор отслеживает `static_call`, но не вызовы методов экземпляра. Используйте более широкий алиас `type_reference`, если ваша политика должна ограничивать instance-зависимости. Подключение instance-call-связи требует расширения коллектора и является кандидатом для Phase 3.
- **Нет per-edge severity.** Allow-записи не несут поля `level:` — каждое layer-violation использует общую опцию `severity` правила. Обходной путь: разбить политику на два именованных правила с разной severity, если нужна более тонкая градация.
- **Изоляция подмодулей отложена.** Сейчас нельзя запретить грани внутри одного слоя. Шаблонные слои уменьшают потребность (`domain-{m}` производит по одному слою на модуль, поэтому cross-module-грани естественно cross-layer), но флаг `allow_same_layer: false` всё ещё запланирован для команд, которым нужны границы внутри слоя.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Источники

Для пользователей, переходящих с отдельных архитектурных инструментов:

- [**deptrac**](https://github.com/qossmic/deptrac) — ближайший аналог. После Phase 2 Qualimetrix покрывает ту же территорию для распространённых случаев: multi-criterion-принадлежность (`patterns` + `suffix` + `attributes` + `implements` + `extends`), шаблонные слои с capture-binding для DDD bounded contexts, исключение поддеревьев внутри слоя и whitelist `relations:` на allow-целях. Поверхность всё ещё меньше deptrac (один allow-list на слой-источник, нет полного predicate-DSL), но правило закрывает long-tail без второго инструмента в CI.
- [**ArchUnit**](https://www.archunit.org/) — вдохновение из Java-мира, модель "архитектура как тест". Capture-binding-форма allow (`'app-{m}': ['domain-{m}']`) концептуально похожа на ArchUnit `slices()`. На PHP модель ложится не хуже.

Для обоснования дизайна Phase 2 — почему шаблоны разворачиваются по наблюдаемым binding tuples, почему capture-binding обязателен, почему `relations:` whitelist-only — см. [ADR 0007: Architecture Rules Phase 2 design decisions](https://github.com/qualimetrix/qualimetrix/blob/main/docs/adr/0007-architecture-rules-phase-2-design.md).

<!-- llms:skip-end -->

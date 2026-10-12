# Polyzon SDK для PHP

Официальный клиент PHP для [публичного API Polyzon](https://api.polyzon.org): поиск 3D-моделей по
Thingiverse, Printables, MakerWorld, Cults3D и ещё 20 площадкам и карточки моделей. Нужны только
`ext-curl` и `ext-json`; внутри — неизменяемые типизированные результаты, keep-alive соединения,
параллельная пакетная загрузка через `curl_multi`, автоматические повторы, поддержка прокси и
типизированные исключения.

[English version](README.md)

## Требования

- PHP 8.2 или новее (проверено на 8.2, 8.3, 8.4 и 8.5) с расширениями `curl` и `json`.
- Токен API из [polyzon.org/account](https://polyzon.org/account/) (раздел «API»).

## Установка

```sh
composer require polyzon/polyzon-php
```

## Быстрый старт

```php
<?php

require __DIR__ . '/vendor/autoload.php';

$client = new Polyzon\Client(); // берёт токен из POLYZON_API_KEY

$page = $client->search('дракон', size: 10, free: true);
foreach ($page->items as $item) {
    echo "[{$item->source}] {$item->title} — {$item->url}\n";
}
```

Больше примеров в [`examples/`](examples): быстрый старт, постраничный обход, параллельная загрузка, прокси
(`php examples/quickstart.php` после `composer install`).

## Авторизация

Каждый запрос подписывается личным токеном (`pzk_…`). Передайте его явно или задайте переменную окружения
`POLYZON_API_KEY`:

```php
$client = new Polyzon\Client(apiKey: $token);
```

Значение по умолчанию читается через `getenv()`. Если фреймворк загружает `.env` без `putenv()` (например,
Symfony Dotenv), передайте `apiKey: $_ENV['POLYZON_API_KEY']`. Конструктор проверяет токен (1–512 видимых
символов ASCII) и никогда его не печатает: `var_dump($client)` показывает `pzk_Q7xR…`.

## Использование

Все методы синхронные и принимают именованные аргументы. Результаты — неизменяемые `readonly`-объекты.

### Поиск

```php
use Polyzon\Lang;
use Polyzon\License;
use Polyzon\Sort;
use Polyzon\Source;

$page = $client->search(
    'органайзер для инструментов',      // обязательно, по-русски или по-английски, до 200 символов
    page: 1,                            // 1..100
    size: 20,                           // 1..50, page × size ≤ 2400
    sort: Sort::POPULAR,                // relevant | popular | newest | likes | makes | downloads
    free: true,                         // только бесплатные
    sources: [Source::PRINTABLES, Source::THINGIVERSE],
    formats: ['stl', '3mf'],
    licenses: [License::CC0, License::CC_BY],
    lang: Lang::EN,                     // ru (по умолчанию) | en — машинный перевод названий и тегов
);

$page->total;          // 1873
$page->totalRelation;  // 'eq' (точно) или 'gte' (не меньше)
$page->hasMore;        // есть ли следующая страница в пределах 2400 результатов
$page->partial;        // часть каталога не ответила
$page->correctedQuery; // исправленный запрос, по которому искал сервер, или null
$page->items[0];       // SearchItem: source, id, title, url, thumbnail, creator, license, price, formats, tags, stats, publishedAt
```

Незаданные аргументы не отправляются. В `Sort`, `Lang`, `Source` и `License` известные значения собраны в
константы (и в `ALL`), но каждый аргумент и каждое поле — обычная строка: новые площадки появляются.

### Постраничный обход

`searchAll` принимает те же аргументы и возвращает ленивый `Generator` моделей:

```php
foreach ($client->searchAll('shelf bracket', size: 50) as $i => $item) {
    echo $item->title, "\n";
    if ($i >= 119) {
        break; // 3 страницы = 3 запроса
    }
}
```

Обход начинается со страницы `page` (по умолчанию 1) и заканчивается, когда `hasMore` — false, страница
пустая или достигнута страница 100 (предел сервера). **Каждая страница — один запрос суточной квоты**:
берите `size: 50` и выходите из цикла, как только данных достаточно. Неверные аргументы бросают исключение
сразу, ошибки запросов — во время обхода.

### Карточка модели

```php
$model = $client->getModel('thingiverse', 'tv-1234567', lang: Lang::EN);
$model->description;
$model->images;  // адреса на CDN площадки
$model->license; // ModelLicense { id: 'cc-by-nc', raw: 'Creative Commons - Attribution - Non-Commercial' } или null
$model->copies;  // та же модель на других площадках: список ModelRef { source, id }
```

`source` и `id` — те же, что в элементах поиска. Они проверяются до запроса (`source` —
`^[a-z0-9]{1,32}$`, `id` — `^[A-Za-z0-9._-]{1,255}$`, но не `.` и не `..`); неверное значение бросает
`InvalidArgumentException`.

### Много моделей сразу

```php
$results = $client->getModels($page->items, concurrency: 4, lang: Lang::EN);
foreach ($results as $result) {
    if ($result->isOk()) {
        echo $result->model->title, "\n";
    } else {
        echo "{$result->ref->source}/{$result->ref->id}: {$result->error->getMessage()}\n";
    }
}
```

`getModels` принимает объекты `ModelRef`, элементы поиска, модели или пары `[source, id]` (любой iterable).
Он ведёт настоящие параллельные передачи через `curl_multi`, не больше `concurrency` одновременно (по
умолчанию 4), и возвращает по `ModelResult` на каждую ссылку **в исходном порядке**. Из-за одной модели он
не бросает исключений: в каждом результате либо `model`, либо `error`, а неудачные запросы повторяются, как
и любые другие. После `AuthenticationException`, `PermissionDeniedException` или `QuotaExceededException`
ещё не начатые ссылки получают ту же ошибку без запроса. Каждая модель — один запрос суточной квоты.

### Аккаунт и лимиты

```php
$me = $client->me();         // Me { account: { id, displayName, createdAt }, token: { id, name, prefix, createdAt } }
$limits = $client->limits(); // Limits { perMinute: { limit, used, remaining, resetAt }, daily: { … } }
```

Суточную квоту они не тратят (минутный лимит — тратят).

## Метаданные ответа

У каждого результата есть свойство `meta`:

```php
$page = $client->search('ваза');
$page->meta->requestId; // X-Request-ID — назовите его, обращаясь в поддержку
$page->meta->rateLimit; // RateLimit { limit: 200, remaining: 163, resetAt: 1791590400 } — суточная квота, или null
```

## Ошибки

Неудачный запрос бросает наследника `Polyzon\Exception\PolyzonException` (это `RuntimeException`):

| Исключение                  | Когда                                                                                                           |
| --------------------------- | --------------------------------------------------------------------------------------------------------------- |
| `ApiConnectionException`    | сбой сети: DNS, TCP, TLS, прокси, обрыв соединения                                                              |
| `ApiTimeoutException`       | попытка длилась дольше `timeout` (наследник `ApiConnectionException`)                                           |
| `InvalidResponseException`  | ответ 2xx, тело которого не JSON-объект или больше 8 МиБ                                                        |
| `BadRequestException`       | 400: `bad_request` (см. `$e->param`) или `page_too_deep`                                                        |
| `AuthenticationException`   | 401: `unauthorized` (нет токена) или `invalid_token` (неверный или отозван)                                     |
| `PermissionDeniedException` | 403 `account_action_required`: примите действующие условия в [polyzon.org/account](https://polyzon.org/account/) |
| `NotFoundException`         | 404 `not_found`                                                                                                 |
| `GoneException`             | 410 `gone`: модель удалена по требованию правообладателя — **удалите её и у себя**                              |
| `RateLimitException`        | 429 `rate_limited`: больше 20 запросов в минуту (повторяется автоматически)                                     |
| `QuotaExceededException`    | 429 `quota_exceeded`: суточная квота исчерпана до 00:00 UTC (наследник `RateLimitException`, не повторяется)    |
| `InternalServerException`   | 5xx: `unavailable`, `internal`, ошибки пограничного узла                                                        |
| `ApiException`              | любая другая ошибка HTTP, в том числе неожиданный 3xx (редиректы не выполняются); базовый класс ошибок HTTP     |

У `ApiException` есть readonly-свойства `status`, `errorCode` (устойчивый машинный код или `null`; он не
называется `code`, потому что `getCode()` уже возвращает статус HTTP), `title`, `detail` (обычно по-русски),
`param`, `requestId`, `retryAfter` (секунды или `null`), `headers` и `body`. Сообщение выглядит так:
`401 invalid_token: Токен не найден или отозван. (request id …)`.

```php
use Polyzon\Exception\GoneException;
use Polyzon\Exception\NotFoundException;
use Polyzon\Exception\QuotaExceededException;

try {
    $client->getModel('printables', 'pr-123456');
} catch (GoneException) {
    $db->delete('printables', 'pr-123456');
} catch (NotFoundException) {
    echo "такой модели нет\n";
} catch (QuotaExceededException $e) {
    echo "квота исчерпана, повтор через {$e->retryAfter} с\n";
}
```

Неверные аргументы бросают стандартный `InvalidArgumentException` до запроса; это не `PolyzonException`.

## Повторы

Неудачный запрос повторяется до `maxRetries` раз (по умолчанию 2) при сбоях соединения, таймаутах, HTTP
500, 502, 503, 504 и 429 `rate_limited`. Пауза — `Retry-After` сервера, если он есть (секунды или дата HTTP;
не больше 60 секунд: если сервер просит дольше, исключение бросается сразу), иначе экспоненциальная
`min(8 с, 0,5 с × 2^попытка)` со случайным разбросом. `quota_exceeded`, 429 без кода и остальные 4xx не
повторяются.

```php
$client = new Polyzon\Client(maxRetries: 5); // или 0, чтобы отключить
```

## Таймауты

`timeout` ограничивает каждую попытку (соединение + ответ), в секундах; по умолчанию 30, из них на
соединение — не больше 10. Попытка, вышедшая за таймаут, (после повторов) бросает `ApiTimeoutException`.

```php
$client = new Polyzon\Client(timeout: 10.0);
$quick = $client->withOptions(timeout: 2.0, maxRetries: 0);
```

## Прокси

```php
$client = new Polyzon\Client(proxy: 'http://user:password@proxy.example.com:3128');
```

- Поддерживаются прокси `http://`, `https://`, `socks5://` и `socks5h://` (DNS через прокси) — всё силами
  libcurl. Логин и пароль из адреса (в percent-encoding) уходят как Basic-авторизация прокси; в логи и
  сообщения об ошибках они не попадают никогда. Без порта берутся 80 / 443 / 1080.
- HTTPS-запросы идут через туннель HTTP `CONNECT` со сквозным TLS до `api.polyzon.org`: прокси не видит ваш
  токен. Туннель живёт между запросами.
- Без опции `proxy` клиент берёт окружение ровно так, как его читает libcurl: `https_proxy` /
  `HTTPS_PROXY`, `all_proxy` / `ALL_PROXY` и `no_proxy` / `NO_PROXY`. Адреса loopback через прокси из
  окружения не ходят. `proxy: false` отключает любой прокси.
- **Защита от httpoxy:** SDK никогда не читает `HTTP_PROXY` — под CGI / PHP-FPM его может подставить
  заголовок запроса `Proxy:` (libcurl тоже игнорирует это имя в верхнем регистре, а обычный HTTP разрешён
  только для loopback).

## TLS и сертификаты

Проверка TLS (сертификат и имя хоста) включена всегда и не отключается. За корпоративным прокси, вскрывающим
TLS, доверьте его корневой сертификат:

```php
$client = new Polyzon\Client(caBundle: '/etc/ssl/corporate-root-ca.pem');
```

Этот набор заменяет хранилище по умолчанию для API и для прокси `https://`. Без `caBundle` libcurl берёт
системное хранилище или настройку `curl.cainfo` в php.ini (в Windows укажите в `curl.cainfo` файл
`cacert.pem`, если у PHP его нет).

## Параллельность

Клиент неизменяем и держит соединения открытыми (общими с клиентами из `withOptions`), так что создайте один
и используйте его для последовательных запросов и пакетов. Для параллельных запросов есть `getModels`: он
ведёт до `concurrency` передач одновременно через `curl_multi` со скользящим окном.

```php
// Те же соединения, другие настройки — например, клиент на токен каждого пользователя:
$forUser = $client->withOptions(apiKey: $userToken, maxRetries: 0);
```

`withOptions` принимает `apiKey`, `timeout`, `maxRetries`, `userAgent` и `logger`; базовый адрес, прокси и
сертификаты остаются от исходного клиента. Соединения закрываются, когда сборщик мусора удаляет последний
использующий их клиент.

Клиент нельзя делить между потоками (ext-parallel, pthreads): у каждого потока должен быть свой клиент.
Сериализовать клиент тоже нельзя.

## Асинхронность

В PHP нет стандартной асинхронной среды, поэтому клиент синхронный; параллельную загрузку закрывает
`getModels`. В приложениях на ReactPHP / Amp вызывайте его из рабочего процесса или потока со своим
клиентом.

## Логи

```php
$client = new Polyzon\Client(logger: function (string $level, string $message, array $context): void {
    error_log("[{$level}] {$message}");
});

// Любой логгер PSR-3 (Monolog, …):
$client = new Polyzon\Client(logger: $psrLogger->log(...));
```

Логгер получает строки `debug` о каждой попытке (метод, путь с запросом, статус, номер попытки, время) и
`warning` о повторах (причина и пауза), те же данные — в `$context`. Заголовки и токен в лог не попадают
никогда. Пакет PSR не нужен.

## Лимиты и квота

- 20 запросов в минуту на аккаунт, все методы.
- 200 запросов в сутки UTC на аккаунт для `search` и `getModel` (`me` и `limits` бесплатны). Каждая
  страница `searchAll` и каждая модель `getModels` считаются.
- Сервер списывает квоту **до** проверки параметров, так что даже ответ `400` стоит запроса.
- Квоты считаются на аккаунт, а не на токен: несколько токенов их не умножают.

Остаток — в `$client->limits()` или `$result->meta->rateLimit` (суточное окно после запроса).
`QuotaExceededException::$retryAfter` подскажет, когда наступят новые сутки.

## Тесты вашего кода

Направьте клиент на локальную заглушку: `http://` разрешён для `localhost`, `127.0.0.1` и `[::1]`.

```php
$client = new Polyzon\Client(apiKey: 'test', baseUrl: 'http://127.0.0.1:8080', proxy: false);
```

## User-Agent

Запросы отправляют `User-Agent: polyzon-php/1.0.0 (php 8.4.13; linux/x86_64)`. Добавьте своё приложение:
`new Polyzon\Client(userAgent: 'my-bot/2.1')`.

## Версии

Пакет следует [семантическому версионированию](https://semver.org/lang/ru/). Несовместимые изменения
публичного API (классов `Polyzon\`, кроме `Polyzon\Internal\` и членов с пометкой `@internal`) выходят только
в новой мажорной версии. Конструкторы классов-результатов внутренние: новые поля могут появиться в минорных
версиях. См. [CHANGELOG.md](CHANGELOG.md).

## Безопасность

**Никогда не встраивайте токен в браузерный код или мобильные приложения — держите его на сервере.** Любой,
у кого он есть, тратит вашу квоту. Клиент ходит только по HTTPS (обычный `http://` разрешён лишь для
`localhost`), не выполняет редиректы, всегда проверяет TLS, ограничивает тело ответа 8 МиБ и не пускает
токен в `var_dump`, `print_r`, `var_export`, `json_encode` и трассировки стека. Об уязвимостях сообщайте,
как описано в [SECURITY.md](SECURITY.md).

## Ссылки

- Документация API и песочница: https://api.polyzon.org
- Токены: https://polyzon.org/account/
- Задачи: https://github.com/PolyzonOrg/polyzon-php/issues

> Если вы пользуетесь бесплатным API Polyzon, будет здорово, если вы упомянете Polyzon (например, ссылкой на [polyzon.org](https://polyzon.org)) на тех страницах вашего проекта, где используется API.

## Лицензия

[MIT](LICENSE)

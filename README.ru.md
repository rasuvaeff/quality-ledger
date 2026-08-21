# rasuvaeff/quality-ledger

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/quality-ledger/v)](https://packagist.org/packages/rasuvaeff/quality-ledger)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/quality-ledger/downloads)](https://packagist.org/packages/rasuvaeff/quality-ledger)
[![Build](https://github.com/rasuvaeff/quality-ledger/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/quality-ledger/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/quality-ledger/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/quality-ledger/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/quality-ledger/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/quality-ledger/php)](https://packagist.org/packages/rasuvaeff/quality-ledger)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
[English version](README.md)

Framework-агностичный ledger для сигналов качества с историей: скармливаешь
ему одноразовый вывод любого CI-инструмента (мутационный прогон, свип flaky-
тестов, проверку доков — что угодно) и получаешь историю между прогонами,
diff между любыми двумя записанными прогонами и ratchet-гейт, падающий
только на **регрессию, реально внесённую этим прогоном** — никогда на фон,
который не связанная правка не создавала.

> Используете AI-ассистента? [llms.txt](llms.txt) содержит компактный API-справочник, который можно передать модели.

## Зачем

Большинство инструментов качества (мутационные тестеры, статические
анализаторы) одноразовые: прогнал, прочитал результат, лог выброшен.
Глобальный порог pass/fail либо блокирует несвязанные PR из-за старого
долга, либо пропускает мелкую регрессию, потому что агрегат всё ещё выше
черты. `quality-ledger` даёт такому инструменту память: id, который был
«хорошим» и только что стал «плохим» — реальная регрессия (`newBad`); id,
который «плохой» уже давно — фоновый долг (`stillBad`, с числом прогонов,
сколько он таким остаётся). Блокировать PR должен только первый случай.

У пакета нет мнения о том, что такое «сигнал качества» — это целиком решает
вызывающий код.

## Требования

- PHP 8.3–8.5
- Нет runtime-зависимостей

## Установка

```bash
composer require rasuvaeff/quality-ledger
```

## Использование

```php doc-exec
use Rasuvaeff\QualityLedger\Datum;
use Rasuvaeff\QualityLedger\DefaultStableId;
use Rasuvaeff\QualityLedger\Ledger;
use Rasuvaeff\QualityLedger\LocalFileStorage;
use Rasuvaeff\QualityLedger\RatchetGate;
use Rasuvaeff\QualityLedger\RunReport;

$ledger = new Ledger(
    id: new DefaultStableId(),
    storage: new LocalFileStorage(sys_get_temp_dir() . '/quality-ledger-readme'),
);

$ledger->append(new RunReport(
    run: 'run-1',
    ts: 1_700_000_000,
    scope: 'acme/widgets',
    data: [
        new Datum(kind: 'mutant', signature: 'src/Foo.php:12:TrueValue', status: 'killed'),
        new Datum(kind: 'mutant', signature: 'src/Foo.php:20:FalseValue', status: 'killed'),
    ],
    metrics: ['msi' => 100.0],
));

$ledger->append(new RunReport(
    run: 'run-2',
    ts: 1_700_003_600,
    scope: 'acme/widgets',
    data: [
        new Datum(kind: 'mutant', signature: 'src/Foo.php:12:TrueValue', status: 'killed'),
        new Datum(kind: 'mutant', signature: 'src/Foo.php:20:FalseValue', status: 'escaped'),
    ],
    metrics: ['msi' => 50.0],
));

$isBad = static fn(string $status): bool => $status === 'escaped';
$diff = $ledger->diff(scope: 'acme/widgets', base: 'run-1', head: 'run-2', isBad: $isBad);

count($diff->newBad);  // => 1
count($diff->fixed);   // => 0
$diff->unchangedCount; // => 1

$gate = (new RatchetGate())->evaluate($diff);

$gate->ok;                   // => false
$gate->regressions[0]->kind; // => 'mutant'

$ledger->trend(scope: 'acme/widgets', metric: 'msi')->points[1]->value; // => 50.0
```

Блок выше исполняется на каждой сборке (`composer docs`, через
[doc-exec](https://github.com/rasuvaeff/doc-exec)) — комментарии `// =>` это
ассерты, а не украшение, так что пример не может разойтись с кодом. В реальном
использовании направьте `LocalFileStorage` в каталог, переживающий процесс;
повторный `append` того же `run` — no-op, именно это делает безопасным
перезапуск CI-шага.


### `StableIdInterface`

Главная точка расширения и единственное место, где решается, что считать
«тем же наблюдением» в двух прогонах:

```php
interface StableIdInterface
{
    /** @return non-empty-string */
    public function id(Datum $datum): string;
}
```

`DefaultStableId` считает sha256 от `kind + "\0" + signature` — этого
достаточно, когда вызывающий код уже нормализовал `signature` до того, что
должно считаться одним и тем же наблюдением. Своя реализация нужна, когда это
не так: мутант, сдвинувшийся на строку, — честно другой id, пока вы не скажете
обратное, а угадать ledger не может.

Любая непустая строка — валидный id, включая цифровые; id-функция, вернувшая
пустую строку, отвергается в `append()`, а не пишется в историю молча.

### `Ledger`

| Метод | Делает |
|---|---|
| `append(RunReport $report)` | Записывает данные и метрики одного прогона. Повторный `$report->run` — no-op. |
| `diff(scope, base, head, isBad)` | Каждый id, наблюдавшийся в `base` и/или `head`, классифицирован в `newBad`, `fixed`, `stillBad` либо свёрнутый `unchangedCount`. |
| `trend(scope, metric, window = null)` | Именованная метрика уровня прогона за последние `window` прогонов (`null` = все, `0` = ни одного). |

`isBad` — `Closure(string): bool`, который задаёте вы сами: ledger хранит
любые строки статусов вашего домена и никогда не интерпретирует их сам.

`diff()` направленный и не сортирует аргументы: он считает изменение *от*
`base` *к* `head`, поэтому если передать более новый прогон как `base`,
регрессия будет показана как исправление. Передавайте их в хронологическом
порядке.

`Datum` и `RunReport` валидируют свои входы: пустые `kind`, `signature`,
`status`, `run`, `scope` или имя метрики — это `InvalidArgumentException` в
конструкторе, а не битая запись, обнаруженная позже.

### Типы результатов

Всё, что возвращают вызовы, — простые readonly value-объекты:

| Тип | Поля |
|---|---|
| `DiffReport` | `base`, `head`, `newBad: list<DiffEntry>`, `fixed: list<DiffEntry>`, `stillBad: list<StillBadEntry>`, `unchangedCount: int`, плюс `totalIdsCompared()` |
| `DiffEntry` | `id`, `kind`, `meta` — `meta` на момент `head`, если id там есть, иначе на момент `base` |
| `StillBadEntry` | те же три плюс `ageRuns`: сколько прогонов id непрерывно «плохой» |
| `GateResult` | `ok: bool`, `regressions: list<DiffEntry>` (возвращает `RatchetGate::evaluate()`) |
| `Trend` | `metric`, `points: list<TrendPoint>`, от старых к новым |
| `TrendPoint` | `run`, `ts`, `value: int\|float` |

Прогон без запрошенной метрики в `Trend` пропускается, а не заполняется нулём.

### Хранилище

`StoragePort` — два метода, `read(scope): ?string` и `write(scope, bytes):
void` — реализуйте под S3, CI-кэш, что угодно. Встроенный `LocalFileStorage`
пишет один файл на scope атомарно (temp-файл + `O_EXCL` create + rename) в
выбранный вами каталог.

### Retention

`RetentionPolicy(tombstoneTtlRuns: 50)` (по умолчанию) удаляет id, если он
отсутствовал дольше указанного числа прогонов — иначе ledger, следящий за
меняющейся кодовой базой, растит одну мёртвую строку на каждый когда-либо
удалённый мутант или тест. id, который присутствует или сейчас «плохой»,
никогда не прунится независимо от этой настройки.

### Бейджи

Число прогона — половина того, что показывает README; вторая половина —
бейдж. `Badge` — это то, **что** написано на бейдже (label, message, цвет из
шестиступенчатой палитры shields.io), а `BadgeSvg` превращает его в
самодостаточный SVG. Внешний сервис не участвует: файл и есть весь артефакт,
поэтому он работает и на офлайн-раннере, и внутри закрытой сети.

```php doc-exec
use Rasuvaeff\QualityLedger\Badge;
use Rasuvaeff\QualityLedger\BadgeSvg;
use Rasuvaeff\QualityLedger\Trend;
use Rasuvaeff\QualityLedger\TrendPoint;

$msi = new Trend('msi', [new TrendPoint(run: 'run-2', ts: 1_700_003_600, value: 98.65)]);
$badge = Badge::fromTrend($msi);

$badge->label;        // => 'msi'
$badge->message;      // => '98.7%'
$badge->color->value; // => 'brightgreen'

$svg = (new BadgeSvg())->render($badge);

str_starts_with($svg, '<svg xmlns="http://www.w3.org/2000/svg"'); // => true
str_contains($svg, '>98.7%<');                                    // => true
```

Запишите `$svg` туда, откуда его достанет README — ветка Pages, ассет
релиза, артефакт — и сошлитесь `<img>`. В CI это обычно делается сразу после
записи прогона:

```bash
mkdir -p build && php bin/render-badge.php > build/msi.svg
```

| Тип | Что это |
|---|---|
| `Badge` | `label`, `message`, `color`; `Badge::forPercentage()` печатает один знак после запятой и красит по привычным порогам, `Badge::fromTrend()` берёт последнюю точку тренда (пустой тренд — `n/a` красным) |
| `BadgeColor` | `BrightGreen` ≥ 95, `Green` ≥ 90, `YellowGreen` ≥ 75, `Yellow` ≥ 60, `Orange` ≥ 40, ниже — `Red`; `hex()` даёт цвет shields.io |
| `BadgeRenderer` | Порт: `render(Badge): string`. Реализуйте его для endpoint-документа shields.io, PNG или строки в терминале |
| `BadgeSvg` | Поставляемая реализация: flat-стиль, высота 20px, ширина текста считается по 6.6px на символ |

## Безопасность

Движок не читает окружение и не зовёт часы или VCS — каждый идентификатор и
timestamp передаёт вызывающий код. Запись `LocalFileStorage` защищена от
symlink-атаки через предсказуемый temp-путь на общем каталоге (`O_EXCL`
create, отказывается идти по существующему пути). Файлы хранилища — обычный
JSON с тем `meta`, что несут ваши `Datum` — относитесь к ним как к
build-артефактам, не как к секретам.

**`append()` не потокобезопасен.** Отдельная запись атомарна, но `append()` —
это read-modify-write по всему scope, а у `StoragePort` нет compare-and-swap:
две CI-задачи, пишущие в один scope одновременно, теряют один из двух
прогонов, без единой ошибки. Держите на scope одного писателя — одна задача на
scope или свой scope на задачу — либо сериализуйте вызовы сами. Чтение
(`diff()`, `trend()`) параллельно записи безопасно: оно видит либо старый файл,
либо новый, но никогда — частичный.

Испорченный или отредактированный руками файл ledger'а отвергается
`RuntimeException` с указанием поля, а не принимается на веру наполовину.

## Примеры

См. [examples/](examples/).

## Разработка

PHP/Composer на хосте нет — всё через Docker (`composer:2` image).

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer docs
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

## Лицензия

[BSD-3-Clause](LICENSE.md)

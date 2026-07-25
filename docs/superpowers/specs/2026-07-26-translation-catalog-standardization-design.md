# Стандартизация PHP-каталогов переводов — дизайн

Дата: 26.07.2026.

Статус: утверждено пользователем.

## Контекст

Портал поддерживает две интерфейсные локали: `ru` и `en`. В каждой локали
находится по 21 PHP-файлу и 4 962 конечных ключа. Текущий read-only аудит
подтвердил полный паритет файлов, ключей, типов и placeholders и отсутствие
duplicate keys, но обнаружил:

- 208 однострочных непустых массивов в `lang/ru`;
- 290 однострочных непустых массивов в `lang/en`;
- разный вложенный порядок трёх ключей в `lang/en/tags.php`;
- смешение американского и британского вариантов английского;
- отдельные обычные английские фразы внутри русского пользовательского
  текста;
- отсутствие общего regression-теста для всех translation domains.

Пользователь утвердил полную нормализацию: каждый непустой массив должен быть
вертикальным, `lang/ru` становится каноническим владельцем структуры и
порядка, а `lang/en` использует последовательный американский английский.

## Цели

1. Сделать все PHP-массивы в `lang/ru/*.php` и `lang/en/*.php`
   многострочными: один элемент на строку, завершающая запятая, вложенность
   четыре пробела.
2. Сохранить одинаковые наборы файлов, рекурсивные ключи, типы, порядок и
   named placeholders для всех поддерживаемых локалей.
3. Исправить подтверждённые смысловые, орфографические и языковые ошибки,
   не меняя stable translation keys.
4. Установить US English как единый редакционный стандарт `lang/en`.
5. Добавить единый автоматический contract, предотвращающий возврат
   горизонтальных массивов, structural drift и placeholder drift.
6. Проверить длинные исправленные подписи на узком телефоне, обычном
   телефоне, планшете и широком TV-подобном viewport без изменения
   server-side device detection.

## Не входит в эту задачу

- новые локали;
- JSON-каталоги или второй translation loader;
- перевод UGC, provider metadata или DB editorial content;
- изменение route, enum, permission, database, API или cache identity;
- автоматический машинный перевод;
- полный redesign всех страниц под TV.

Последний пункт принадлежит следующему отдельному cross-device master plan:
shell, public discovery/catalog, title/player, private pages, administration и
device regression matrix должны улучшаться независимыми проверяемыми
change sets.

## Рассмотренные подходы

### 1. Полная AST-нормализация и общий contract — выбран

Все 42 файла механически печатаются вертикально через уже установленный
development parser. До и после преобразования сравниваются возвращаемые PHP
массивы. Затем отдельным reviewable diff исправляются только подтверждённые
переводы. PHPUnit проверяет структуру и исходный формат.

Преимущества: один стандарт, минимальный риск потери значений, автоматическая
защита от регрессии. Недостаток: большой, но почти полностью механический
diff.

### 2. Исправить только наиболее плотные английские файлы — отклонён

Меньше diff, но 208 горизонтальных массивов остаются в `ru`, а общий стандарт
не достигается.

### 3. Сгенерировать `en` из русского skeleton — отклонён

Упрощает порядок ключей, но создаёт ненужный риск перезаписи качественных
существующих переводов и комментариев.

## Каноническая структура

- Список локалей берётся из существующего project configuration; фактически
  это `ru` и `en`.
- Имена файлов, recursive key paths и порядок ключей задаёт `lang/ru`.
- Каждое значение остаётся scalar того же типа; обычный interface catalog
  использует строки.
- Named placeholders сравниваются без учёта порядка и должны совпадать.
- Plural branches могут различаться числом форм по правилам языка, но каждая
  ветвь должна быть непустой и сохранять одинаковые placeholders.
- Stable keys не переименовываются ради редакционного улучшения текста.
- Бренды, протоколы, форматы, keyboard shortcuts и технические идентификаторы
  сохраняют официальное написание.

## Формат исходного кода

Каждый непустой PHP-массив записывается только так:

```php
'section' => [
    'first' => 'Значение',
    'second' => [
        'nested' => 'Другое значение',
    ],
],
```

Однострочные массивы с элементами запрещены независимо от их длины. Empty
array, если он появится в будущем, может оставаться `[]`. Форматирование не
сортирует ключи по алфавиту и не меняет доменный порядок русского каталога.

## Редакционный стандарт

### Русский

- Весь обычный пользовательский текст пишется по-русски.
- Английский сохраняется только для брендов, официальных названий,
  протоколов, форматов, code-like значений и общепринятых технических
  сокращений.
- Обычные фразы вроде `raw diagnostics`, `workflow`, `cache flush`,
  `high availability` и `zero downtime` переводятся по смыслу.
- Текст остаётся конкретным и не добавляет рекламные обещания или fake
  capability.

### Английский

- Используется US English.
- `catalogue`, `centre`, `behaviour`, `labelled`, `programme`,
  `acknowledgement` и аналогичные британские варианты заменяются
  американскими, если это обычный интерфейсный текст.
- Stable keys, product names и provider names не меняются.
- Значение должно соответствовать русскому смыслу, а не дословно копировать
  структуру предложения.

## Автоматическая проверка

Новый `TranslationCatalogParityTest` должен проверять:

1. exact locale directories и exact PHP file set;
2. syntax/loadability и array return type каждого файла;
3. recursive key, key-order и scalar-type parity относительно `ru`;
4. отсутствие duplicate keys в исходном AST;
5. exact named-placeholder parity;
6. непустые строки и plural branches;
7. многострочность каждого непустого массива и отдельную строку каждого
   элемента;
8. отсутствие утверждённых запрещённых UK spellings в `lang/en`.

Существующий `AdministrationTranslationParityTest` сохраняется как более
узкий regression contract и не дублируется в production code.

## Безопасное преобразование

1. До форматирования сериализовать semantic snapshot каждой пары
   `locale/file/key/value/type`.
2. Механически преобразовать только `lang/*/*.php`.
3. Повторно загрузить массивы и доказать exact equality snapshot.
4. Отдельным небольшим diff применить редакционные исправления.
5. Каждое значение с изменённым текстом перечислить в task evidence.

Так mechanical formatting не может незаметно изменить перевод, ключ,
placeholder или тип.

## Cross-feature impact

| Область | Решение |
| --- | --- |
| Laravel translator/fallback | Существующая PHP architecture и fallback `ru` сохраняются |
| Public/admin UI | Видимый текст исправляется; keys и вызовы `__()` не меняются |
| Livewire | Locale hydration и state не меняются |
| Cache | Существующий translation fingerprint увидит изменённые значения; новые keys или broad flush не нужны |
| Search/SEO/mail/notifications | Structural parity сохраняется; затронутые тексты проверяются тем же общим contract |
| Accessibility/mobile | Long labels и ARIA copy проверяются на целевых viewport |
| API/routes/permissions | Не меняются |
| Database/storage/import | Не меняются |
| Dependencies | Не добавляются и не обновляются |
| Production | Только code/assets deployment; DDL/DML и ручная очистка кеша не нужны |

## Device verification для этой задачи

Translation change проверяется на representative public и administrative
страницах:

- narrow phone: `320×720`;
- standard phone: `390×844`;
- tablet portrait: `768×1024`;
- tablet landscape: `1024×768`;
- desktop: `1440×1200`;
- TV-like browser viewport: `1920×1080`.

Проверяются отсутствие horizontal page overflow, обрезанных подписей,
недоступных controls, raw translation keys и console/page/first-party
ошибок. Это verification переводов, а не утверждение поддержки конкретной
TV OS или пульта.

## Rollback

Rollback — revert task commits. Schema, rows, uploads, cache store, queue,
workers и production content откатывать не требуется. Translation
fingerprint вернётся к значениям предыдущего commit автоматически.

## Acceptance criteria

- все 42 PHP-каталога используют вертикальные непустые массивы;
- обе локали имеют одинаковые 21 файл и 4 962+ рекурсивных ключа после
  учёта concurrent additions;
- key/type/order/placeholder parity и duplicate-key scan проходят;
- подтверждённые RU/EN ошибки исправлены;
- US English не смешивается с утверждёнными UK variants;
- focused и existing translation tests, Pint, syntax, static checks, Vite и
  representative browser matrix проходят либо failure честно записан;
- тематические требования, plan/compliance, README и CHANGELOG обновлены;
- exact commit создаётся только в `main`, foreign shared-tree scope не
  включается, configured push выполняется без force.

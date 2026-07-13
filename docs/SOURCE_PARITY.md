# Паритет страниц источника Seasonvar

Обновлено: 13.07.2026

Этот документ разделяет фактически найденные в настроенной карте сайта Seasonvar страницы и возможности локального приложения. Ненулевой count появляется только после успешного `php artisan seasonvar:import --inventory-only`; наличие enum или будущего route не считается подтверждением source URL.

Handler support также не равен подтверждению источника: actor/genre/country/tag metadata handlers существуют для controlled rollout и fixtures, но defaults оставляют их parsing/publication выключенными, пока новый успешный inventory и отдельное разрешение публикации не подтвердят категорию. Подтверждённый RSS используется только как freshness signal; static/search/sitemap не републикуются.

Inventory сначала применяет `robots.txt`, затем читает sitemap XML/gzip с объявленным crawl delay и хранит только нормализованные разрешённые metadata URL. Он не загружает страницы сериалов, видео, player/playlist endpoints или защищённый контент и не даёт разрешения на копирование описаний, отзывов, брендинга либо иных охраняемых материалов. Публикация локального аналога требует отдельного подтверждения лицензии и product scope.

<!-- project-docs:start -->
## Управляемый снимок source parity

- Последняя попытка: успешно.
- Последний подтверждённый снимок: 13.07.2026 11:03:28.
- Команда: `php artisan seasonvar:import --inventory-only`.
- Карт сайта: 5.
- Нормализованных URL: 47747.
- Неизвестных URL: 0; некорректных: 0; заблокированных: 40.

| Тип источника | Найдено | Parser | Публичный route | SourcePage | В локальном sitemap | Parser class | Route name |
| --- | ---: | --- | --- | --- | --- | --- | --- |
| `serial` | 47725 | да | да | да | да | `App\Services\Seasonvar\SeasonvarCatalogParser` | `titles.show` |
| `static` | 16 | нет | нет | да | нет | — | `home` |
| `rss` | 1 | да | нет | да | нет | `App\Services\Seasonvar\SeasonvarRssFreshnessImporter` | `feed` |
| `sitemap` | 5 | нет | нет | да | нет | — | `sitemap.index` |

- Нет parser support: static, sitemap.
- Нет локальной публичной страницы: static, rss, sitemap.
- Репрезентативные пути: `serial` `/serial-1-4400_psndajw-1-season.html`, `/serial-2-4400_psasqlg-2-season.html`, `/serial-3-4400_psejmfc-3-season.html`; `static` `/`, `/st/online_besplatno.html`, `/st/smotret_onlayn_besplatno.html`; `rss` `/rss.php`; `sitemap` `/sitemap_index.xml`, `/sitemap1.xml.gz`, `/sitemap2.xml.gz`.
- Подтверждённым считается только тип с ненулевым счётчиком последнего успешного inventory. Остальные категории реестра являются возможностями классификатора, а не заявлением о наличии страницы у источника.
- Инвентаризация хранит только разрешённые публичные metadata URL. Видео, player/playlist URL, cookies, credentials, закрытые ответы и защищённый контент в отчёт не входят; публикация локальной страницы требует отдельного подтверждения прав.
<!-- project-docs:end -->

# Хранилище и uploads

Обновлено: 25.07.2026

## Текущее состояние

- Публичных upload-форм и upload-маршрутов сейчас нет.
- Основной filesystem disk остается `local` и указывает на `storage/app/private`.
- Локальная выдача temporary storage URLs выключена через `LOCAL_FILESYSTEM_SERVE=false`.
- Для будущих пользовательских upload-файлов добавлен отдельный private disk `uploads` с корнем `storage/app/private/uploads`.
- Task 28 подтвердил отсутствие `public/storage` symlink: текущие uploads остаются private и это корректно. `storage/` и `bootstrap/cache` writable общей runtime-группой без recursive `777`; active SQLite/WAL/SHM ограничены owner/group mode `0660`.
- Persistent backup обязан охватывать SQLite consistent snapshot и non-reproducible private uploads одной согласованной точкой. Panel archives не считаются verified database backup; scope и restore описаны в [`operations/backup-and-restore.md`](operations/backup-and-restore.md).
- Disk consumers включают большую SQLite database, WAL во время импорта, logs, panel backups, private uploads, temp exports и build artifacts. Cleanup не удаляет active DB/WAL, referenced files, legal/financial evidence или единственный known-good backup.

## Правила upload-функций

- Новый upload endpoint должен быть write/admin/moderation-функцией с явной авторизацией через Form Request, policy, gate или middleware.
- Файл нужно проверять до сохранения: обязательность, тип, расширение, MIME, размер и доменное ограничение конкретной функции.
- Для приватных изображений используйте `App\Support\Uploads\PrivateImageUploadRules`; SVG не разрешен.
- Сохраняйте файлы через `App\Services\Storage\PrivateUploadStorage`, чтобы имя генерировал Laravel, а не клиент.
- `PrivateUploadStorage` принимает только относительные slash-separated пути без NUL, backslash, drive prefix, абсолютного пути и сегментов `.`/`..`; те же правила применяются перед cleanup deletion.
- Не используйте `getClientOriginalName()` или `getClientOriginalExtension()` для формирования пути хранения.
- Не отдавайте private paths, абсолютные пути storage или raw upload metadata в публичный HTML/API.
- Публичная выдача upload-файла должна быть отдельным signed/authorized endpoint с проверкой владельца или права доступа.

## Cleanup

- Если upload заменяет старый файл, новый файл нужно сначала успешно сохранить, а потом удалить старый через `PrivateUploadStorage::delete()`.
- При удалении модели, которая владеет upload-файлом, добавляйте cleanup в service/action или model observer с тестом.
- Для временных upload-областей нужна отдельная команда cleanup с ограниченным scope и тестом на безопасное удаление.
- Дополнительный fake scheduler job не создаётся: cleanup использует только существующий bounded importer/scheduler behavior либо отдельно авторизованную ручную операцию. Legal/financial retention periods не выдумываются.

## Тесты

- Upload-тесты используют `Storage::fake('uploads')` и `UploadedFile::fake()`.
- Проверяйте, что сохраненный путь не содержит клиентское имя файла.
- Проверяйте private visibility и cleanup через `Storage::disk('uploads')->assertExists()` / `assertMissing()`.

## Удалённые обложки подборок

Подборки больше не принимают, не импортируют, не хранят и не выдают собственные изображения. В интерфейсе используется только текстовая карточка; постеры тайтлов, avatar/cover профиля и другие upload-домены не входят в эту границу.

Для контролируемого удаления прежних данных существует `php artisan catalog-collections:purge-covers`. Без параметров команда выполняет только dry-run и выводит агрегированные количества файлов, байт и строк без private paths. Необратимое удаление требует явного `--execute`, использует жёстко заданные disk `uploads` и prefix `catalog-collections/`, очищает legacy metadata подборок/источников bounded-пакетами и не создаёт backup/trash-копию. После выполнения команда должна подтвердить готовность к удалению legacy-колонок; при любой storage/database ошибке она возвращает ненулевой код, и schema migration продолжать нельзя. Повторный запуск безопасен и должен быть no-op.

Проверка после операции:

```bash
php artisan catalog-collections:purge-covers
```

Ожидаются нулевые `Файлов`, `Байт`, `Строк подборок` и `Строк источников`, а также `Готовность к удалению колонок: да`. Путь `storage/app/private/uploads/catalog-collections` не восстанавливается rollback-кодом: возврат удалённых изображений возможен только из отдельно существовавшего внешнего backup, который эта операция по прямому продуктовому правилу не создаёт.

## Avatar и cover профиля

`UserProfileMediaService` accepts bounded JPEG/PNG/WebP sources, then an approved GD processor verifies actual MIME/bytes/pixels, applies JPEG EXIF orientation, center-crops/resamples and writes a fresh WebP: `320×320` for avatar and `1280×360` for cover. Re-encoding strips source metadata/client filename. `PrivateUploadStorage::storeBytes()` saves only below `user-profiles/{public_id}/{avatar|cover}` with generated names and private visibility. Database keeps private disk/path/WebP MIME/size/version metadata; public HTML receives only the same-origin versioned controller URL. Replacement stores and validates the new derivative before the locked DB switch, removes orphan output on failure and deletes only the previous owned path after success. Public delivery remains policy-checked, `private, no-store` and `nosniff`; SVG, executable files, arbitrary paths and public-disk URLs are unsupported.

## Screenshots технических обращений

Task 20 переиспользует `PrivateUploadStorage`, но добавляет более узкий screenshot boundary: максимум три PNG/JPEG/WebP, decoded MIME/dimensions/pixels/bytes validation, GD re-encode, generated filename и ticket-owned prefix. DB хранит только private disk/path, safe display name, trusted MIME/extension/size/dimensions/hash. Выдача разрешает exact ticket/attachment и policy, а не storage URL; merge сохраняет uploader privacy. Retention и scanner limitations описаны в [`technical-issues.md`](technical-issues.md).

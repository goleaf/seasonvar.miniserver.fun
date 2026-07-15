# Хранилище и uploads

Обновлено: 09.07.2026

## Текущее состояние

- Публичных upload-форм и upload-маршрутов сейчас нет.
- Основной filesystem disk остается `local` и указывает на `storage/app/private`.
- Локальная выдача temporary storage URLs выключена через `LOCAL_FILESYSTEM_SERVE=false`.
- Для будущих пользовательских upload-файлов добавлен отдельный private disk `uploads` с корнем `storage/app/private/uploads`.

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

## Тесты

- Upload-тесты используют `Storage::fake('uploads')` и `UploadedFile::fake()`.
- Проверяйте, что сохраненный путь не содержит клиентское имя файла.
- Проверяйте private visibility и cleanup через `Storage::disk('uploads')->assertExists()` / `assertMissing()`.

## Обложки коллекций

Collection cover хранится только через `PrivateUploadStorage` в `catalog-collections/{public_uuid}/` на `config('uploads.disk')`. В БД находятся disk/path/MIME/size и monotonic `cover_version`; client filename и public storage URL не сохраняются. Разрешены те же validated raster formats, что задаёт `PrivateImageUploadRules`; collage generation, signed provider image и request-time image processing не добавлены.

`GET /collections/covers/{publicId}/{version}` не является публичным disk route: controller повторно разрешает collection policy, exact current version, configured disk, owned prefix и traversal guards, затем отдаёт `private, no-store`, `nosniff`, `noindex`. При replace предыдущий реально locked path удаляется после commit; ошибка DB удаляет только новый orphan. Remove/force delete/account delete удаляют owned file, но не fallback poster и не shared catalog media.

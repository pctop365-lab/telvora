# Аккаунты покупателей TELVORA — 27.09.2026

Реализация находится в существующем проекте `C:\Users\ASRock\Downloads\Telvora_for_hosting\Telvora`.
Ничего не опубликовано, production-база не изменялась. AGENTS.md в проекте и родительских каталогах не найден.
Существующие незакоммиченные инструменты очистки товаров и `variant_model_backend_diff.txt` не изменялись.

## Что добавлено

- `/account`: регистрация, вход, выход, профиль и история только своих заказов; ссылка в шапке, мобильная версия, существующие светлая/тёмная темы.
- Логин — никнейм из 3–32 латинских символов, первый символ — буква, далее буквы/цифры/`_.-`; регистр не учитывается. Телефон содержит 10–15 цифр, форматирование удаляется, 11-значный российский номер с `8` приводится к `+7`. Другие номера вводятся с кодом страны. Логин неизменяемый; ФИО и контактный телефон — отдельные поля. Уникальность обеспечивает `customers.uq_customer_login` по ASCII binary нормализованному значению.
- Пароли: `password_hash(PASSWORD_DEFAULT)`, `password_verify`, автоматический rehash; минимум 10 символов, максимум 72 байта. Сессионная cookie `TELVORA_CUSTOMER` отделена от `PHPSESSID` администратора: Secure, HttpOnly, SameSite=Lax, strict mode, ротация ID. Неактивность — 2 часа, максимальный возраст авторизации — 7 дней, cookie без постоянного срока. Пароли и сессионные токены в localStorage не записываются.
- CSRF для регистрации, входа, изменений профиля, выхода, писем/сброса и авторизованного checkout. Счётчики попыток в MySQL: вход 30/IP и 10/логин за 15 минут, регистрация 10/IP и 10/логин; ограничения писем/ссылок отдельно. SQL параметризован.
- Checkout подставляет только пустые, ещё не редактировавшиеся поля. Можно указать другого получателя. Необязательный `Сохранить для следующих заказов` изначально выключен. Сохранение профиля входит в транзакцию заказа. Email необязателен и в checkout.
- `orders.customer_id` заполняется сервером из сессии. Переданный из браузера `customer_id` игнорируется. Гостевые/исторические заказы остаются NULL; совпадения телефона/email ничего не привязывают. Контакты по-прежнему хранятся непосредственно в заказе.
- Админка: вкладка «Клиенты», поиск по логину/ФИО/телефону, страницы по 20 записей, карточка и связанные заказы. Используется существующая серверная проверка администратора.

## Почта и восстановление

В исходниках найден действующий транспорт PHP `mail()` в `callback_request.php` и настройка `callback_mail_from`. Локальной production-конфигурации нет; её значения и фактическая доставка писем на хостинге не проверялись. Само наличие публичного адреса поддержки не считается настройкой отправки.

Новый сервис использует `customer_mail_from`, а при отсутствии — существующий `callback_mail_from`. Значение должно быть корректным адресом отправителя, `mail()` должен быть доступен. Если ни одна настройка не задана, кнопки отправки скрыты, API возвращает 503; фиктивной отправки нет. При отказе `mail()` ссылка удаляется. Запрос восстановления всегда отвечает одинаково для существующего и отсутствующего логина, чтобы не раскрывать аккаунты.

Для подключения на хостинге оператору нужно:

1. Убедиться, что PHP `mail()` настроен через почтовый транспорт хостинга, доменный отправитель разрешён, настроены SPF/DKIM и доставка на внешние адреса работает.
2. В существующий приватный массив `/var/www/u3609206/data/telvora_runtime/telvora_secrets.php` добавить `customer_mail_from` с реальным разрешённым адресом или использовать уже настроенный `callback_mail_from`. Файл не переносить в DocumentRoot.
3. Проверить отправку на контролируемом тестовом аккаунте. Сохранить email в профиле, отдельно нажать подтверждение, открыть ссылку и подтвердить действие; затем проверить восстановление.

Ни регистрация, ни покупки не зависят от email. Восстановление возможно только после отдельного добровольного подтверждения адреса. Ссылки действуют 30 минут, одноразовые; в БД хранится SHA-256 случайного 256-битного токена. Токен находится во fragment URL, не попадает в HTTP access log/Referer и удаляется из адресной строки после открытия. Смена email снимает подтверждение и удаляет прежние ссылки. Сброс пароля отзывает все сессии покупателя. Восстановления по неподтверждённому телефону, через администратора без проверки или другого обхода нет.

## Миграция — точные команды на хостинге

Миграция `database/migrations/20260927_014_customer_accounts.sql` создаёт `customers`, `customer_auth_limits`, `customer_tokens` и добавляет nullable FK в `orders`. Ничего не удаляет, не меняет контакты/состав существующих заказов, не выполняет backfill.

Требуются PHP 8.1+ с PDO MySQL, mbstring и сессиями; InnoDB и права CREATE/ALTER/INDEX/REFERENCES на текущую БД. Рабочий проект использует MySQL 8; тест выполнен на MySQL 8.0.25. До внедрения проверьте свободное место и время ALTER на размере рабочей `orders`: изменение может ждать блокировку таблицы.

Готовый upload-комплект находится в `deployment/customer-accounts/upload/`: `backend/` (пять PHP-файлов), `migration/` (SQL и runner), `tools/` (backup и backend installer), корневой `backend-manifest.txt`. В нём нет секретов, персональных данных и тестов. Ниже команды для найденной в текущем runbook структуры REG.RU. Выполняет оператор после загрузки этого комплекта в **приватную** папку `/var/www/u3609206/data/staging/customer-accounts-20260927`. Не загружать тесты и SQL в публичный корень.

```bash
set -euo pipefail
export TELVORA_RUNTIME_CONFIG=/var/www/u3609206/data/www/telvora.ru/runtime_config.php
export TELVORA_DB_BACKUP_DIR=/var/www/u3609206/data/telvora-db-backups
STAGE=/var/www/u3609206/data/staging/customer-accounts-20260927
php "$STAGE/tools/backup-database.php" --preflight
php "$STAGE/tools/backup-database.php" --backup
php "$STAGE/migration/apply-migration.php" --check
php "$STAGE/migration/apply-migration.php" --apply
php "$STAGE/migration/apply-migration.php" --check
```

Сохранить выведенный `backup_reference` и SHA-256 резервной копии, не публикуя содержимое. Ожидаемые результаты: `PREFLIGHT_OK`, затем `APPLIED_AND_VERIFIED`, затем `ALREADY_APPLIED_AND_VERIFIED`. Полностью установленная миграция повторно ничего не меняет. При частичном применении скрипт останавливается. MySQL DDL не транзакционен: при сбое сначала проверить схему, не удалять таблицы и не повторять SQL вслепую. `--check` выполняет только чтение схемы и advisory lock.

## Пошаговая установка файлов

1. Сначала сохранить текущие файлы/БД и выполнить миграцию выше. На время согласованного обновления backend/frontend исключить новые оформления; не останавливать и не переустанавливать поставщиков/Telegram. Снять ограничение после проверки.
2. В `STAGE/backend/` из комплекта загрузить **ровно пять** файлов по `backend-manifest.txt`. `STAGE/tools/backup-database.php`, `STAGE/tools/deploy-backend.sh`, `STAGE/migration/apply-migration.php` и SQL уже входят в комплект. Существующие `runtime_config.php`, конфигурация, cart/delivery/service helpers должны быть на хостинге. Не копировать весь рабочий каталог: он содержит исторические резервные копии и служебные файлы.
3. В `STAGE/tools/` поместить `deployment/customer-accounts/deploy-backend.sh` и рядом использовать отдельный `backend-manifest.txt`. Это изолированный установочный инструмент аккаунтов; общий backend manifest проекта не изменяется. Запустить его:

```bash
bash "$STAGE/tools/deploy-backend.sh" \
  --payload "$STAGE/backend" \
  --manifest "$STAGE/backend-manifest.txt" \
  --document-root /var/www/u3609206/data/www/telvora.ru \
  --backup-root /var/www/u3609206/data/backend-backups
```

Сохранить точный `backup_reference` из результата. Порядок manifest устанавливает helpers до API. Скрипт проверяет PHP-синтаксис и сохраняет предыдущие версии. При включённом OPcache без проверки timestamp обновить кеш PHP штатной функцией панели хостинга.

4. Для frontend использовать существующий **SEO Production Deploy**, а не простое копирование `dist/index.html`. Сначала зафиксировать и проверить именно эти изменения в отдельном release commit, затем выполнить стандартные preflight/activation с его полным SHA (требования защищённого environment остаются штатными). Команды локальной пересборки:

```powershell
npm.cmd run typecheck
npm.cmd run build:seo
npm.cmd run seo:package
npm.cmd run seo:validate-package
```

`build:seo` только читает публичные catalog/services/images. Перед реальным внедрением пересобрать с актуальным каталогом. Не маркировать архив старым HEAD, пока изменения не закоммичены. Текущая локальная сборка уже выполнена, но git commit/push и production workflow в рамках задачи не запускались.

5. Штатный статический движок обновит HTML, hashed assets и сгенерированную `.htaccess` последней. `/account` включён в оба источника SEO-маршрутов и отдаёт noindex client shell; sitemap аккаунтов не содержит. Не перезаписывать nginx/vhost, `uploads`, PDF, Telegram runtime, секреты, `public_contacts.json`, Yandex verification. Если используется nginx route include вместо штатного Apache, новый include из `seo-artifacts/routes.nginx.conf` также содержит `/account`.
6. Проверить по HTTPS прямое открытие и обновление `/account`, вход/выход, сохранение профиля, checkout гостя и покупателя, личную историю, админку и клиентов. Cookie покупателя должна иметь Secure/HttpOnly/SameSite=Lax. В течение сеанса не смешивать `www` и canonical `telvora.ru`. Для контрольных заказов использовать согласованные тестовые товары/контакты; они создают обычные заказы и штатные уведомления.
7. Отдельно проверить реальную доставку письма, если почта подключена. Зафиксировать версии/backup references без паролей, токенов и персональных данных. Снять режим обслуживания.

## Откат файлов без потери новых данных

1. На время отката исключить новые оформления. Сначала откатить static release через **его точный** backup из результата SEO activation; не выбирать «последний» наугад. Из каталога движка этого release:

```bash
bash /var/www/u3609206/data/staging/EXACT_SEO_STAGE/deploy-release.sh \
  --rollback /var/www/u3609206/data/telvora-backups/EXACT_STATIC_BACKUP \
  --document-root /var/www/u3609206/data/www/telvora.ru \
  --staging-root /var/www/u3609206/data/staging/EXACT_SEO_STAGE/rollback \
  --backup-root /var/www/u3609206/data/telvora-backups
```

`EXACT_SEO_STAGE` и `EXACT_STATIC_BACKUP` заменить значениями сохранённого результата внедрения; их нельзя узнать до фактического деплоя.

2. Восстановить PHP из **точного** backend backup. Сначала проверить, что после этого release не внедрялись другие изменения. Следующие команды трогают только пять файлов собственного manifest:

```bash
DOCROOT=/var/www/u3609206/data/www/telvora.ru
BACKUP=/var/www/u3609206/data/backend-backups/EXACT_BACKEND_BACKUP
test -f "$BACKUP/api.php"
test -f "$BACKUP/manager.php"
for file in api.php manager.php customer.php customer_admin.php customer_service.php; do
  if test -f "$BACKUP/$file"; then
    cp -p -- "$BACKUP/$file" "$DOCROOT/$file.rollback-new"
    mv -f -- "$DOCROOT/$file.rollback-new" "$DOCROOT/$file"
  else
    rm -f -- "$DOCROOT/$file"
  fi
done
php -l "$DOCROOT/api.php"
php -l "$DOCROOT/manager.php"
```

3. При необходимости обновить OPcache, проверить гостевое оформление, админку и публичные страницы, снять режим обслуживания.
4. **Не откатывать базу автоматически.** Дополнительные таблицы и nullable `orders.customer_id` совместимы со старым кодом. Их оставить: это сохраняет зарегистрированных клиентов и заказы, принятые после внедрения. Старые PHP-файлы продолжат вставлять гостевые заказы с NULL. Восстановление старого SQL dump потеряло бы новые заказы, поэтому это отдельная аварийная процедура, не обычный откат файлов.

## Проверки и ограничения

- `npm.cmd run build` — PASS; `npm.cmd run typecheck` — PASS.
- `npm.cmd run build:seo` — PASS, 22 публичных маршрута, 5 опубликованных товаров на момент сборки; `/account` отдельно, noindex.
- `tests/customer_accounts_http_test.php` — PASS: настоящие PHP HTTP + MySQL, новая одноразовая БД, реальные customer/api/manager entrypoints. Регистрация, хеши, дубликаты/unique index, вход/ошибка/выход, CSRF, rate limit, доступ к чужим данным, оба checkout, сохранение только по checkbox, неизменность снимков, откат неудачного заказа, админка/пагинация, токены/TTL/смена email/отзыв сессий. База теста удаляется в finally; другие локальные базы не изменяются. Telegram в существующем изолированном HTTP test mode не отправляется.
- Playwright `tests/browser/customer_accounts.spec.ts` — 7 PASS. API подменён, внешние запросы заблокированы; проверены позднее автозаполнение и пользовательское очищение поля, значения checkbox в POST, гостевой checkout без email, регистрация/профиль/выход, отсутствие горизонтального переполнения на 320/390/1280px и отсутствие auth-секретов в localStorage.
- PHP lint новых и изменённых entrypoints, customer service/admin, migration runner — PASS.
- Регрессии availability, delivery quote, нормализация импорта поставщиков, service integration, SEO contracts/routing, контакты шапки — PASS.
- `tests/delivery_integration_contract_test.php` падает на устаревшей точной строке `Стоимость согласовывается`: в PDF уже используется `Доставка — стоимость согласовывается`. То же несоответствие подтверждено на исходном HEAD до изменений; PDF в этой задаче не менялся. Остальные проверки доставки и реальные checkout прошли.
- Остаются предупреждения сборщика о Browserslist и JS chunk больше 500 kB. Новых зависимостей нет.
- Реальная доставка email и поведение серверного OPcache/HTTPS cookie на хостинге требуют проверки при внедрении. Тесты одноразовых ссылок используют синтетические токены в отдельной БД; настоящие письма не отправлялись. Нагрузочный тест и тест на копии production-БД не выполнялись.

Локальные команды повторения:

```powershell
& 'C:\Users\ASRock\Telvora-MySQL-Test\php\php.exe' -c 'C:\Users\ASRock\Telvora-MySQL-Test\php\php.ini' tests/customer_accounts_http_test.php
npx.cmd playwright test tests/browser/customer_accounts.spec.ts
```

HTTP-тест жёстко ограничен loopback MySQL `127.0.0.1:3307`, использует локальный защищённый test-root password file и генерирует имя `telvora_customer_test_<random>`. Не запускать этот тест на хостинге.

## Изменённые и новые файлы

Сервер: `api.php`, `manager.php`, `customer.php`, `customer_service.php`, `customer_admin.php`.

Frontend: `src/App.tsx`, `src/components/Header.tsx`, `src/pages/CheckoutPage.tsx`, `src/pages/AdminPage.tsx`, `src/services/orderService.ts`, `src/pages/AccountPage.tsx`, `src/components/CustomerOrders.tsx`, `src/components/admin/CustomersAdmin.tsx`, `src/services/customerService.ts`, `src/store/customer.tsx`.

SEO: `scripts/seo-routes.mjs`, `scripts/seo-routing.mjs`, `public/robots.txt`.

БД/внедрение: `database/migrations/20260927_014_customer_accounts.sql`, `deployment/customer-accounts/apply-migration.php`, `deployment/customer-accounts/backend-manifest.txt`, этот `README.md`.

Тесты: `tests/customer_accounts_http_test.php`, `tests/browser/customer_accounts.spec.ts`.

Пересобраны локальные игнорируемые артефакты `dist/`, `.seo-build/`, `seo-artifacts/`. Секреты, production-файлы, импорт поставщиков, Telegram-бот и существующие заказы не редактировались.

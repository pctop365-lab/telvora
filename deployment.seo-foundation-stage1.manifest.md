# TELVORA technical SEO foundation — deployment manifest

Deployment не выполнен. Push не выполнен.

## Release identity

- Release: `TELVORA_SEO_FOUNDATION_STAGE1`.
- Base commit: `d890a2b7e6d38c106bd556882087c8e980733492`.
- Release HEAD: точный commit, содержащий эту версию manifest; перед deployment сверить с переданным release hash.
- Deploy только из clean working tree после повторной сверки SHA-256.

## Production artifacts

Разместить только следующие собранные файлы:

1. `dist/assets/index-CWrXU9Be.js` → `assets/index-CWrXU9Be.js`.
2. `dist/assets/index-D4ag5NG-.css` → `assets/index-D4ag5NG-.css` (hash не изменился; загрузка допустима только если файл отсутствует).
3. `dist/robots.txt` → `robots.txt`.
4. `dist/sitemap.xml` → `sitemap.xml`.
5. `dist/index.html` → `index.html` — устанавливать последним атомарной заменой.

Не загружать `src/`, `tests/`, development config, manifest, backups или secrets. БД и PHP runtime не меняются.

## SHA-256

```text
dist/index.html                              30D824C51076D049D6D979335CFCF62361A390BC632421A2FBF1A427CB0CD138
dist/robots.txt                              76F1362C71E030FD86853BA61E66035B7488E487C34DB98D97B27289ADC3A7A5
dist/sitemap.xml                             1801D346F019DDA968D88BDFF864577B0FFD0621A875A4672020F7F142FEBEB9
dist/assets/index-D4ag5NG-.css               F48E53DBFE9BA7FFE432862B213007B683B435E8980E5D4928ED526657328945
dist/assets/index-CWrXU9Be.js                B68E4C7C08BE3B0AE220A2C60ED2CF200440B8AD88AA9BD2A677DD67D15D1E37
```

## Predeploy and smoke

1. Создать timestamped backup текущих `index.html`, `robots.txt`, `sitemap.xml` и заменяемых assets; сохранить hashes и rollback metadata.
2. Сверить local/remote hashes до установки entrypoint.
3. Проверить HTTP 200, один rendered self-canonical, title/description/H1 и отсутствие console errors для `/`, `/catalog`, `/delivery`, `/services`, `/contacts`, `/requisites` и активного product URL.
4. Проверить `noindex` в rendered DOM для `/checkout`, `/order-success/*`, `/admin` и неизвестного route.
5. Проверить валидность Organization, Product, Offer и BreadcrumbList JSON-LD без rating/review markup.
6. Проверить `robots.txt` и `sitemap.xml`; sitemap содержит активный на момент подготовки URL `/catalog/oled/lg-oled77c5rla`.

## Server-level follow-ups (не входят в этот frontend deploy)

- Настроить единый host redirect `https://www.telvora.ru/*` → `https://telvora.ru/*` с HTTP 301: сейчас `www` отвечает HTTP 200.
- Подготовить безопасную server rewrite/prerender strategy для реального HTTP 404: неизвестный SPA route сейчас отвечает HTTP 200 и только после JS показывает noindex 404 UI.
- Рассмотреть selective prerender основных landing/category/product pages, поскольку raw SPA HTML не содержит route-specific H1, основного текста и Product JSON-LD.

## Rollback

Вернуть сохранённые `index.html`, `robots.txt`, `sitemap.xml` и assets одной согласованной версией. Изменения БД отсутствуют.

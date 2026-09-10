# TELVORA Brand + Telegram frontend release

Scope: frontend-only. Production deployment is not part of this commit.

## Preconditions

- Build from this release commit with `npm.cmd run build`.
- Back up current `index.html` and record its SHA-256.
- Do not change or remove existing hashed assets.

## Upload order

Upload these files first:

| Local file | Production path | SHA-256 |
| --- | --- | --- |
| `dist/assets/index-vr-yfaHg.css` | `assets/index-vr-yfaHg.css` | `bedb6a9da6c9eb3aa2852b1c62be731db560738bbebd6978c132284d32b1b3af` |
| `dist/assets/index-Ba7LoCJj.js` | `assets/index-Ba7LoCJj.js` | `5afeb7e45328488ccdd23daf678ff13a92367e8326e7efaf653cd747027faeb0` |
| `dist/telvora-logo-dark.svg` | `telvora-logo-dark.svg` | `76eda5fc9fc5b1604e2e2bf3e976a66f407a3d62ec0a34cadcfb6c7269efe1ac` |
| `dist/telvora-logo-white.svg` | `telvora-logo-white.svg` | `53bcefa6bdf61327c64997651fdaa770a321fa597c411e6cea6039dc9f414164` |
| `dist/favicon.svg` | `favicon.svg` | `574e1a94017314059d8001321dfc6740acf28fc5b6c7cf90b0c0289fd11128af` |

Publish this file last:

| Local file | Production path | SHA-256 |
| --- | --- | --- |
| `dist/index.html` | `index.html` | `3ee90cc6f3d8417f707e0043c2be444ff12559dc8c9a66ed54cba63c75b6fb2f` |

## Exclusions

Do not deploy source files, tests, PHP, API, database files, Telegram bot files, PDF files, secrets, or unrelated frontend files. Do not delete old hashed assets.

## Rollback

Restore the backed-up `index.html`. Newly uploaded hashed assets and SVG assets may remain because they are inert when not referenced.

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
| `dist/assets/index-D_xYGheo.css` | `assets/index-D_xYGheo.css` | `ed6a0c0074e30f4f1040a60c9b22167d2419b3f2f9e03877cff4906276d71652` |
| `dist/assets/index-Cy3XY7EO.js` | `assets/index-Cy3XY7EO.js` | `3081669270b65ab86c97f7c3030aa7fd531de44464fceba4afb989453d465be0` |
| `dist/telvora-logo.svg` | `telvora-logo.svg` | `73ee6591600bd6ac21d532525f3f8382553d8c4c5ba24eb98043d743dd245a1a` |
| `dist/telvora-mark.svg` | `telvora-mark.svg` | `f38dea75ac0a3c13075e9073341597bccc2a354b5527a50e35ad2da1b7c513aa` |
| `dist/favicon.svg` | `favicon.svg` | `574e1a94017314059d8001321dfc6740acf28fc5b6c7cf90b0c0289fd11128af` |

Publish this file last:

| Local file | Production path | SHA-256 |
| --- | --- | --- |
| `dist/index.html` | `index.html` | `79d9992f3ef0626365cf1446f073fdaa326121283fd16c798379b41ecc49a155` |

## Exclusions

Do not deploy source files, tests, PHP, API, database files, Telegram bot files, PDF files, secrets, or unrelated frontend files. Do not delete old hashed assets or the previous logo assets.

## Rollback

Restore the backed-up `index.html`. Newly uploaded hashed assets and SVG assets may remain because they are inert when not referenced.

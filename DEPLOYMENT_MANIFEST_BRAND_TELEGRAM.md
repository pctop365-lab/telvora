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
| `dist/assets/index-C2CnSSg0.css` | `assets/index-C2CnSSg0.css` | `f1ea5e0d4d3cf5a1978ebc058e411c2fc047cf8451746b04b2de4952e0c65cd9` |
| `dist/assets/index-B3NiVxyf.js` | `assets/index-B3NiVxyf.js` | `ac3597081068ed29d2202ffbda853f0e2693d68ff51754dc5823f75031b2b2d8` |
| `dist/telvora-logo.svg` | `telvora-logo.svg` | `73ee6591600bd6ac21d532525f3f8382553d8c4c5ba24eb98043d743dd245a1a` |
| `dist/favicon.svg` | `favicon.svg` | `574e1a94017314059d8001321dfc6740acf28fc5b6c7cf90b0c0289fd11128af` |

Publish this file last:

| Local file | Production path | SHA-256 |
| --- | --- | --- |
| `dist/index.html` | `index.html` | `6dd20e2119a5710aa89c728c7c5dab291346789596a01535a70a62214c7b456c` |

## Exclusions

Do not deploy source files, tests, PHP, API, database files, Telegram bot files, PDF files, secrets, or unrelated frontend files. Do not delete old hashed assets or the previous logo assets.

## Rollback

Restore the backed-up `index.html`. Newly uploaded hashed assets and SVG assets may remain because they are inert when not referenced.

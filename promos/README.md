# Plugin Store promo images

Marketing images for the Headdy listing on the Craft Plugin Store, rendered in the same family
theme as the plugin's marketing page at
[justinholt.com/plugins/craft-headdy](https://justinholt.com/plugins/craft-headdy).

## Building

```bash
./build.sh          # all slides
./build.sh "2 5"    # just slides 2 and 5
```

Output lands in `out/` as `headdy-promo-N.jpg`, 1920×1080 (rendered at 2× in headless Chrome, then
downsampled so the type stays crisp). **Promos are JPEG, never PNG**: Chrome can only write PNG, so
`build.sh` converts with `sips` at quality 90 and deletes the intermediate. `out/` should hold only
`.jpg` files.

`build.sh` is Chunky's, with one change: headless Chrome sometimes exits non-zero *after* writing a
perfectly good screenshot, which `set -e` turned into a silently half-built deck. The script now
judges a slide by whether its PNG exists rather than by Chrome's exit code.

## Slides

| # | Slide | Shows |
|---|-------|-------|
| 1 | Cover — name, tagline, app icon, price | — |
| 2 | One JSON request, not a form post | headless Commerce today against one `POST /carts` with a bearer key |
| 3 | Money is an object. Errors have a code. | the money object and the error envelope |
| 4 | Checkout that says what's missing | the `GET /checkout` payload |
| 5 | Payment that works headlessly | `POST /checkout/pay` and an off-site gateway's redirect returned as JSON |
| 6 | The cart mutations Commerce never shipped *(Pro)* | a GraphQL mutation and the full list of mutations and queries |
| 7 | Finds the problem before your front end does | the Overview screen: configuration check and the last 24 hours |

Every payload on slides 2–6 is taken from `docs/api.md`. If the contract changes, the slides change
with it.

## Theme

| | |
|---|---|
| Accent | `#1E2330`, the icon tile — ink |
| Ground | `#0c0f15` → `#171c27`, a step darker than the tile so the tile still reads |
| Text | `#f3efe6`, a warm off-white |
| Highlight | `#f2c46d`, amber — headline second lines, eyebrows, strings that matter |
| Before-marks | `#ef8a7a`, coral — only the crosses on slide 2 |
| Display face | Jersey 20 |
| Body face | Inter |

The accent is near-black, so it cannot be the highlight the way the other decks' accents are. Ink
is the ground instead, and contrast comes from the off-white plus one restrained highlight. Keep it
to the one: a second bright colour turns the code cards into a syntax-highlighting demo.

Both faces are self-hosted in `assets/` and inlined as base64 by `build.sh` before rendering —
Chrome's `file://` origin rules block them otherwise, and the deck silently falls back to system
sans. `fonts.css` is generated, not edited.

`assets/icon.svg` is a copy of `../src/icon.svg`; copy it again whenever the icon changes.
`assets/watermark.svg` is the glyph from `../src/icon-mask.svg` in white, **without the tile**. At
watermark scale a filled tile reads as a hard-edged grey box across every slide.

## Screenshots

`shots/` holds real captures from the plugin-testing harness (Craft 5, Commerce 5, Headdy Pro) —
not mockups. `seed-demo.php` builds what they show: Pro edition, three API keys, a webhook
endpoint, and a request log made by driving the real API over HTTP with those keys.

```bash
docker exec -w /var/www/html ddev-plugin-testing-web \
  php /var/www/craft-headdy/promos/seed-demo.php             # build
docker exec -w /var/www/html ddev-plugin-testing-web \
  php /var/www/craft-headdy/promos/seed-demo.php --traffic   # re-run only the API traffic
docker exec -w /var/www/html ddev-plugin-testing-web \
  php /var/www/craft-headdy/promos/seed-demo.php --teardown  # put everything back
```

The build records the edition it found and the highest log and order IDs in
`storage/runtime/headdy-promo-state.json`; teardown deletes the keys, the webhook, its deliveries
and queue jobs, the log rows and every cart the traffic created, then restores the edition.

Things the harness will do to you:

- **The harness is shared.** Another session running `tests/integration/checks.php` creates its own
  keys (they appear in the keys list) and empties the request log. Wait for it to finish, then
  `--traffic` and capture straight away.
- **The webhook is seeded switched off.** An enabled endpoint receives every order status change
  any suite in the harness makes — nearly 900 in a quarter of an hour, each a failed queue job.
  Switch it on only for the moment its edit screen is captured.

Capturing: `php craft users/impersonate admin` for a login URL (no password, no lockout risk), then
Playwright. Strip `#global-sidebar`, `#global-header`, `#global-footer`, `#notifications` and every
fixed or sticky element outside `#main` (sibling plugins' panels and banners), set the body to the
CP's own `#e4edf6`, zoom the page 1.8× with `#main` at 1100px (1300px for the log), and clip to
`#main`.

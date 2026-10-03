# Headdy documentation

These files are the source for the documentation published at
[justinholt.com/plugins/craft-headdy/docs](https://justinholt.com/plugins/craft-headdy/docs).
`pluginsite/docs/sync` reads this directory, so every file that should appear on the site needs
YAML front matter with `title`, `slug`, `order` and `summary`. A file without it is skipped — which
is how this README and `plan.md` stay off the site.

| File | Page |
|---|---|
| `installation.md` | Requirements, install, first key, first cart, editions |
| `configuration.md` | Settings, keys and scopes, CORS, redirects, customers, GraphQL, webhooks, permissions |
| `usage.md` | A cart, checkout and payment from a JavaScript front end |
| `api.md` | The full v1 contract — every endpoint and error code. **A shape change means `v2`.** |
| `troubleshooting.md` | What to check when a request is refused or a payment won't redirect |
| `faq.md` | The questions worth answering before installing it |

For a shorter tour, see the project [README](../README.md). For how the plugin is put together —
and the list of things that went wrong while building it — see [CLAUDE.md](../CLAUDE.md).

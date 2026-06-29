# AGENTS

## Repo at a glance

ShipStation for WooCommerce — a WordPress plugin (fork of the official WooCommerce ShipStation integration) that bridges WooCommerce orders to the ShipStation API and supports a custom REST surface, an auth/checkout flow, and live shipping rates.

Key entry points:

- `woocommerce-shipstation.php` — plugin bootstrap; defines constants and calls `Main::instance()`.
- `includes/class-main.php` — top-level wiring.
- `includes/class-wc-shipstation-integration.php` — settings + admin glue.
- `includes/class-wc-shipstation-api.php` + `includes/api/requests/` — ShipStation-bound API request handlers (export, shipnotify, etc.).
- `includes/api/rest/` — REST controllers exposed by this plugin (orders, inventory, diagnostics, auth).
- `includes/checkout/` — block + classic checkout extensions and rate shipping method.
- `includes/class-auth-controller.php` + `templates/auth-modal.php` + `assets/js/auth-display.js` — auth modal UI.

## Code intelligence: ask-self

This repo has an ask-self RAG index. Before grep-spelunking or asking the user to re-explain repo context, query ask-self first.

Query it like this:

```sh
./scripts/ask-self-query.sh "your question here"
```

When to use it:

- Session-start orientation in this repo.
- Unfamiliar subsystems (e.g. "how does the shipnotify request work?").
- Pronoun-heavy user references ("that controller", "the auth flow", "this hook").
- Cross-file behavior questions ("where do orders get exported to ShipStation?", "which hook fires when a shipment is created?").

When **not** to use it:

- Trivial single-file reads — just open the file.
- Tight edit-test loops where you already know the file.
- Questions about current uncommitted state — the index reflects the last ingest, not your working tree.

To rebuild the index after meaningful changes:

```sh
./scripts/ask-self-ingest.sh --mode all
```

### Configuration

- Embeddings: `qwen-local` (`Qwen/Qwen3-Embedding-0.6B`, dim 1024) — runs locally through `sentence-transformers`.
- Synthesis: `ollama` `qwen3:8b` at `http://localhost:11434` — make sure ollama is running and the model is pulled.
- External ask-self install: `ASK_SELF_PATH` (defaults to `/Users/noelsaw/Documents/GH Repos/ask-self`).
- Optional: `ASK_SELF_PYTHON` to pin a Python interpreter; otherwise the wrappers prefer `$ASK_SELF_PATH/.venv/bin/python`.

### Index layout

This repo ships a committed shared baseline index at `ask_self/index/shipstation-fork-shared.sqlite` — teammates can query immediately after `git pull` without having to run ingest. Per-user fresh working indexes go to `temp/rag/shipstation-fork.sqlite` (gitignored) when you re-run `./scripts/ask-self-ingest.sh`.

### Staleness note

The committed shared index reflects whichever commit it was last rebuilt against (see `## Freshness` in `ARCHITECTURE.md`) and will lag active branch work. For up-to-the-moment retrieval against your working tree, run `./scripts/ask-self-ingest.sh --mode all` and the local `temp/rag/` index will be used.

### Refreshing the shared baseline

When the shared index drifts too far from `main`, refresh it with:

```sh
./scripts/ask-self-ingest.sh --shared-index --mode all
```

Then commit the updated `ask_self/index/shipstation-fork-shared.sqlite`. Do not commit anything under `temp/rag/` — those are local working artifacts.

## House rules for agents

- Match WordPress / WooCommerce coding conventions already present in `includes/` (yoda conditions, `wp_*` escaping, `__()` for i18n, action/filter hook naming).
- Do not touch `wordpress_org_assets/` — those are marketing/store-listing artifacts.
- `*.min.js` and `*.min.css` are build outputs; edit the non-min source and rebuild.
- Treat `changelog.txt` and `readme.txt` as the WordPress.org-facing source of truth for plugin metadata and user-facing changelog.

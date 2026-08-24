# Simple Voting System

A Drupal 11 voting system: polls with image options, **one vote per user per question enforced by a
database constraint**, a browser UI, a hand-written JSON API, and interactive API docs. Everything ships
inside a single custom module — `drupal_simple_voting` (human name *Voting*) — so bringing the environment up
is enough to have something to vote on, and uninstalling takes it all away again.

Base URL of the dev site: `https://sistema-de-votacao.lndo.site`

---

## Requirements

- [Lando](https://lando.dev) 3.26 (Docker under the hood). Nothing else is installed on the host.
- The stack Lando builds: Drupal 11.4, PHP 8.4, MariaDB 11.4, Nginx, Drush 13, Dart Sass 1.103.1.

## Install

```bash
lando start      # build and start the containers
lando install    # install/repair the site (idempotent — safe to re-run)
```

`lando reinstall` drops the database and rebuilds from scratch (`FORCE_INSTALL=1`).

`scripts/install.sh` (behind `lando install`) does, in order:

1. waits for MariaDB to accept connections;
2. installs Composer dependencies and verifies the module's `.info.yml` is present (aborts with a clear
   message otherwise);
3. creates the site's files directory (`web/sites/default/files`) and makes it writable;
4. installs Drupal with the **`minimal`** profile (skips if already installed);
5. **uninstalls `node`** — the domain uses custom content entities, so core's node module is not wanted;
6. declares the `config/sync` directory in `settings.php`;
7. declares `trusted_host_patterns` in `settings.php` (the site host plus `127.0.0.1`);
8. enables `drupal_simple_voting`;
9. installs and selects **Olivero** (default theme) and **Gin** (admin theme);
10. installs the core modules the `minimal` profile leaves out —
   `field_ui views views_ui menu_ui menu_link_content block_content path config contextual help options datetime link editor ckeditor5 big_pipe automated_cron announcements_feed` —
   the `standard` profile's module set **minus `node` and `taxonomy`** (`node` because the brief forbids it,
   `taxonomy` because it depends on `node` and would drag it back in). Without them the administration has no
   Views, no field UI, no menu management and no block types. Then enables **`gin_toolbar`**;
11. rebuilds caches and prints a one-time login link (`drush user:login`).

## Database dump

A ready-to-restore dump lives at `db/simple-voting.sql.gz` (gzip). It already carries the seed polls, options
and users, so importing it is an alternative to `lando install` for anyone who prefers to evaluate against a
pre-loaded database. From the project root:

```bash
lando ssh -s appserver -c "zcat /app/db/simple-voting.sql.gz | mariadb -h database -u drupal -pdrupal drupal"
```

The unique key `voting_vote__user_question (uid, question)` is part of the dump, so the one-vote-per-user
guarantee survives a restore.

## Seed users

Created on install, removed on uninstall. **Development-only passwords** — this stack is never exposed to a
public network.

| Username | Password | Role | Purpose |
| --- | --- | --- | --- |
| `admin` | `admin` | uid 1 (superuser) | Full CMS access |
| `eleitor` | `eleitor` | voter | Demo voter |
| `andre.figueira`, `beatriz.antunes`, `lucas.moreira`, `daniela.prado`, `henrik.larsson`, `flavia.marques`, `sofia.nakamura`, `helena.castro`, `omar.haddad`, `larissa.fontes`, `mateus.aragao`, `priya.nair` | *same as the username* | voter | 12 seed voters |

The site has three roles — `anonymous`, `authenticated` and `voter`. The `voter` role is the **only** one
allowed to cast a ballot (`authenticated` has that permission revoked on install). It grants no admin access.
Registration is open, and every new account gets the `voter` role automatically, so any visitor who registers
can vote. The `/user/register` form asks for a username, an email and a password (typed twice); on submit the
account is created **active**, the visitor is **logged in immediately** and taken to the front page, and no email
is sent. Email verification is deliberately **off**: when it is on, Drupal hides the password field and emails a
one-time login link instead, and since this local stack has no mail transport those accounts ended up created but
unreachable. Registration's "welcome" notification is silenced for the same reason.

## Seeded content

8 polls · 35 image options · 80 ballots, drawn from real Drupal community surveys and forum threads. Their
creation dates are staggered a day apart, so the listing's Newest/Oldest ordering has distinct dates to sort
by. Two states are seeded on purpose so both configurations are visible to a reviewer:

- **One closed poll** — *"Which admin theme should be Drupal's default: Gin or Claro?"*. It is seeded open,
  collects its ballots and is closed afterwards, the way a real poll runs: it refuses every new vote yet still
  shows a full result.
- **Two polls hide their totals after you vote** (`show_results` off); the rest reveal them.

---

## Application pages (CMS)

| Path | What it does | Who |
| --- | --- | --- |
| `/polls` | Poll index — **this is the site front page** | Anyone |
| `/poll/{id}` | The ballot, or the result once you have voted (per the poll's policy) | Anyone can view; only the `voter` role casts |
| `/user/login?destination=/poll/{id}` | Visitor flow: an anonymous click on a poll lands here, then returns to that poll after login | Anonymous |
| `/user/register` | Open registration; the account is created active, logged in on submit and given the `voter` role, so it can vote immediately | Anonymous |

## Filtering and sorting the poll list

The poll listing — the `/polls` page and the **Poll list** block alike — carries a filter bar above the cards,
aligned to the right. It is a plain **GET form**: two selects and an **Apply** button, working with JavaScript
switched off, with the current choice living in the URL.

| Control | Options | Query arg |
| --- | --- | --- |
| **Status** | All polls · Open · Closed | `status=all\|open\|closed` |
| **Order** | Newest first · Oldest first | `sort=newest\|oldest` |

- **Reader-driven order.** Sorting is by the question's creation date and belongs to the reader alone — the list
  no longer floats open polls to the top by default, since that would override the chosen direction. To see only
  the open ones, use the **Status** select.
- **Deterministic ties.** Two polls created in the same second break the tie by `id` in the same direction, so
  the order never comes back shuffled.
- **Validated input.** Unknown values in the URL fall back to the defaults (`status=all`, `sort=newest`); both
  are checked against an allowlist in PHP.
- **The pager keeps the filter.** Page links carry `status` and `sort` along, so paging never drops the view.
- **Empty states.** When the filter matches nothing the list says *"No poll matches this filter."*; when there
  is no poll at all it still says *"There are no polls yet."*.

`PollIndex` (the shared `drupal_simple_voting.poll_index` service) applies all of this, so the page and the block
behave identically, and the bar itself is a new SDC component, `components/poll-filter/`. Two cache contexts —
`url.query_args:status` and `url.query_args:sort` — cache each filtered view on its own.

The bar is responsive through a container query on `.vt-polls` (`container-type: inline-size`): in a wide list
the three controls sit on one right-aligned row; in a narrow sidebar each takes the full width — no overflow and
no horizontal scroll either way.

## Admin pages

All served under the Gin admin theme. Every one is reachable through the interface, so none of the paths
below has to be typed by hand:

- **Gin sidebar.** The shortcuts block at the top of the sidebar carries **Polls**, next to **Blocks** and
  **Files**. Gin builds that block from a fixed list of entity types (content, blocks, files, media) and never
  reads the menu, so a menu link alone would not reach it; the module injects the item with
  `hook_preprocess_menu_region__middle()` in `drupal_simple_voting.module`, and only when the current user can
  open the poll collection.
- **Content tab.** `/admin/content` gains a **Polls** tab (local task), beside Content, Blocks and Files.
- **Listing actions.** The poll listing offers two action buttons: **+ Add poll** and **+ Voting settings**.
- **Row operations.** Each row carries the split-button **Edit**, with **Delete** and **View** in its dropdown.
- **Configuration → Workflow.** Holds **Voting settings** and **API docs**.

| Path | What it does |
| --- | --- |
| `/admin/content/polls` | Poll listing (Poll, Open, Options, Votes, Operations) |
| `/admin/content/polls/add` | Create a poll **with its options on the same screen** (title, description, image upload — **2 MB limit, resized above 1024×1024** — draggable weight, add/remove rows) |
| `/admin/content/polls/{id}/edit` | Edit a poll and its options |
| `/admin/content/polls/{id}/delete` | Delete a poll |
| `/admin/config/voting` | Global switch — turn voting on/off across the whole site (CMS and API) |
| `/docs` | Swagger UI — the menu entry **Configuration → Workflow → API docs**. A public page, but an administrator arriving from the menu sees it in the admin theme (`_admin_route`) |
| `/admin/reports/dblog` | Audit trail — filter by the `drupal_simple_voting` log channel |

## Poll list block

The poll index is also a placeable **block**, so the same listing can sit in a sidebar — or any region of any
theme — instead of only at `/polls`. In **Structure → Block layout**, pick a region and choose **Place block**,
then select **Poll list** (category **Voting**). The page and the block both read through the shared
`drupal_simple_voting.poll_index` service (`PollIndex`), so the block renders the same cards — title,
description and an Open/Closed state — and applies the same access rule from a single place.

- **Who sees it** — anyone with the `access content` permission. Exactly as on the page, an anonymous click on a
  card lands on the login screen with a `destination` back to the chosen poll.
- **Settings** — Drupal's standard **Title** / **Display title**, plus **Polls per page** (a number, 0–100,
  default 5). Polls beyond that count fall behind the block's own pager; **`0` lists every poll and hides the
  pager.**
- **Filter in place** — the filter bar appears on the block too (see *Filtering and sorting the poll list*). Its
  GET form submits to the current path (`$request->getPathInfo()`), so filtering a block in a sidebar reloads
  that same page with the block filtered, instead of sending the reader off to `/polls`.
- **Narrow regions** — the pager is restyled to fit a slim column: `scss/base/_pager.scss` shrinks core's pager
  down to a sidebar width.

Every placed instance is cleaned up on uninstall — `drupal_simple_voting_uninstall()` calls
`drupal_simple_voting_remove_placed_blocks()`, which deletes the `block` entities whose plugin is
`drupal_simple_voting_poll_list`.

---

## HTTP API

Hand-written JSON, versioned under `/api/v1`. Public identifiers are the entity **UUID**, never the serial id.
Every `/api/` response is JSON — including `403`/`404`/`405` and unknown paths.

| Method | Path | Body | Responses |
| --- | --- | --- | --- |
| `GET` | `/api/v1/polls` | — | `200` |
| `GET` | `/api/v1/polls/{uuid}` | — | `200`, `404` |
| `GET` | `/api/v1/polls/{uuid}/results` | — | `200`, `404` |
| `POST` | `/api/v1/polls/{uuid}/vote` | `{"option_id":"<uuid>"}` | `201`, `400`, `403`, `404`, `409`, `422` |

`POST /vote` response codes:

| Code | Meaning |
| --- | --- |
| `201` | Vote recorded; the payload carries the fresh results |
| `400` | Body is missing a string `option_id` |
| `403` | Poll is closed, or voting is off site-wide, or the caller lacks the `voter` role |
| `404` | No poll with that UUID |
| `409` | This user already voted in this poll (duplicate) |
| `422` | That option belongs to another poll |

**Writes require a CSRF header.** Fetch a token from `GET /session/token` and send it as `X-CSRF-Token`:

```bash
BASE=https://sistema-de-votacao.lndo.site
TOKEN=$(curl -s "$BASE/session/token")
curl -s -X POST "$BASE/api/v1/polls/<uuid>/vote" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: $TOKEN" \
  --cookie-jar cookies.txt --cookie cookies.txt \
  -d '{"option_id":"<option-uuid>"}'
```

## Interactive API docs — Swagger

- **`/docs`** — a Swagger UI page served by the module itself. The UI assets come from
  `web/libraries/swagger-ui`, copied there by a Composer script (`install-swagger-ui-library`).
- **Public page, admin-aware theme.** The route is flagged `_admin_route`: an anonymous consumer reads it in
  the public theme, while an administrator who opens it from **Configuration → Workflow → API docs** gets the
  same page in the admin theme, instead of the public header rendered inside the admin frame.
- **`/docs/openapi.json`** — an **OpenAPI 3.1** document generated in PHP from the module's real routes. It is
  not a hand-maintained YAML that drifts out of sync.
- Both pages are provided by the module, so they **return `404` once the module is uninstalled**.
- In *Try it out*, the read endpoints work as-is; a write (`POST /vote`) needs the `X-CSRF-Token` header from
  `/session/token` (see above).

## Postman

- Collection: `postman/simple-voting.postman_collection.json`
- Environment: `postman/simple-voting.postman_environment.json`

The Lando certificate is self-signed. In Postman, turn TLS verification **off** (Settings → General → SSL
certificate verification). With Newman: `newman run postman/simple-voting.postman_collection.json -e
postman/simple-voting.postman_environment.json --insecure`.

---

## Architecture — final decisions

1. **One vote per user per question, under concurrency.** A unique key `voting_vote__user_question (uid,
   question)` is the only arbiter. `BallotBox::cast()` does not read-then-write — it inserts and lets the
   database refuse the second ballot, turning the violation into a `DuplicateVoteException` (the API returns
   `409`). There is no check-then-act window to lose.
2. **Custom content entities, no `node`.** `voting_question`, `voting_option`, `voting_vote`, declared with the
   `#[ContentEntityType]` PHP attribute, no bundles.
3. **Hand-written API, without core `rest` or `jsonapi`.** Plain routed controllers returning `JsonResponse`.
   Writes are protected by core's CSRF header check (`_csrf_request_header_token`).
4. **Every `/api/` response is JSON, errors included.** `EventSubscriber/ApiFormatSubscriber` marks the
   request format before routing runs, so error pages come back as JSON, not HTML.
5. **Observability at the write path.** The `drupal_simple_voting` log channel (`BallotAudit`) records a stored
   vote, a refused duplicate, a ballot on a closed poll, and a storage failure — called from inside
   `BallotBox`, the single point both the CMS and the API pass through.
6. **One rule, one place.** The API reimplements nothing; it uses the same `drupal_simple_voting.policy` and
   `drupal_simple_voting.ballot_box` services the browser UI uses.
7. **Zero theme dependency.** The module ships its own CSS and defines its own button (the `.vt-action`
   class). Because core stamps `.button`/`.button--primary` onto every submit and the active theme paints
   those with equal specificity, the ballot form drops those theme hooks via `#pre_render`, so the button
   keeps the module's styling under any theme — no specificity war, no `!important`. Focus gets a visible
   ring (`:focus-visible`).
8. **Two switches.** `drupal_simple_voting.settings: enabled` turns voting off for the whole site (CMS and
   API); each poll's `show_results` decides whether its totals appear after a vote. Openness is the poll's
   `status` (open/closed). All of it is evaluated by the policy service, never in a template.

### Content entities

| Entity | Key fields |
| --- | --- |
| `voting_question` | `title`, `description`, `show_results`, `status` (open/closed), `created`, `changed` |
| `voting_option` | `question` (ref), `title`, `description`, `image`, `weight` |
| `voting_vote` | `question`, `option`, `uid`, `created` — unique key on `(uid, question)`, tally index on `(question, option)` |

## Presentation — Single Directory Components

The UI is built entirely from SDC, under `components/`: `poll-card`, `ballot`, `ballot-option`,
`vote-status`, `poll-filter`. Each folder holds its own `.component.yml` (prop schema), `.twig`, `.scss` and compiled `.css`
— Drupal attaches each component's CSS on its own. There is no `templates/` directory and no `hook_theme()`.

Design tokens are literals in `scss/tokens/primitives/` (`_color`, `_space`, `_shape`, `_typography`,
`_motion`). They are emitted as `--vt-*` CSS custom properties in a single `:root` block
(`scss/base/_token-emit.scss`); components consume only `--vt-*` — no raw hex anywhere in a component.

Compile the stylesheets with `lando sass` (one-shot) or `lando sass:watch`.

## Uninstall — two steps, on purpose

Drupal core refuses to uninstall a module that still owns content. Rather than override that guard, the module
respects it:

1. **Delete the content first** — the links Drupal offers at `/admin/modules/uninstall`.
2. **Uninstall `drupal_simple_voting`.**

On uninstall the module reverts everything it set up: it restores the previous front page and the previous
registration settings — registration mode, email verification and the "welcome" notification, all saved to state
at install — removes the `voter` role, deletes the demo account and the seed voters, and removes every placed
*Poll list* block. After that, `/polls`, `/docs`, `/docs/openapi.json` and `/api/v1/polls` all return `404`.

---

## Dependencies

Composer manages everything under `web/`.

- **Module dependencies:** core `image` and core `toolbar`. `toolbar` is required because the module ships
  menu links, an action link and local tasks; without core's toolbar there is no menu bar to display them and
  each admin page could only be reached by typing its URL.
- **Contrib:** `drupal/gin` (^5.0) and `drupal/gin_toolbar` (^3.0) — the admin theme.
- **Front-end library**, vendored into `web/libraries/` by Composer scripts (`post-install-cmd` /
  `post-update-cmd`):
  - `swagger-api/swagger-ui` (^5.32) → `web/libraries/swagger-ui/dist`
- **Dart Sass** is installed into the appserver by `.lando/scripts/install-dart-sass.sh`. There is no Node in
  the stack.
- **Deliberately not used:** core `rest`, core `jsonapi` (the API is hand-written), and `node`.

## Project layout

```
.lando.yml                                   Environment: recipe, services, proxy, tooling
.lando/                                      Nginx vhost, php.ini, the Dart Sass install script
scripts/install.sh                           Idempotent installer (behind `lando install`)
composer.json / composer.lock                Dependencies; scripts vendor Swagger UI
config/sync/                                 Configuration export target
postman/                                     Postman collection and environment
web/modules/custom/drupal_simple_voting/     The module: domain, CMS, API, /docs, SDC presentation
```

## Command reference

| Command | What it does |
| --- | --- |
| `lando start` | Build and start the containers |
| `lando install` | Install or repair the site (idempotent) |
| `lando reinstall` | Drop the database and install from scratch |
| `lando drush <cmd>` | Run the project Drush (e.g. `lando drush user:login`) |
| `lando composer <cmd>` | Run Composer in the appserver |
| `lando sass` | Compile every stylesheet the module ships |
| `lando sass:watch` | Recompile stylesheets on every change |

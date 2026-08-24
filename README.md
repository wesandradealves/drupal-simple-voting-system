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
2. installs Composer dependencies and verifies the vendored Bootstrap/Swagger assets and the module's
   `.info.yml` are present (aborts with a clear message otherwise);
3. installs Drupal with the **`minimal`** profile (skips if already installed);
4. **uninstalls `node`** — the domain uses custom content entities, so core's node module is not wanted;
5. declares the `config/sync` directory in `settings.php`;
6. enables `drupal_simple_voting`;
7. installs **Olivero** (default theme) and **Gin** + `gin_toolbar` (admin theme);
8. rebuilds caches and prints a one-time login link (`drush user:login`).

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
| `admin` | `admin` | administrator | Full CMS access |
| `eleitor` | `eleitor` | voter | Demo voter |
| `andre.figueira`, `beatriz.antunes`, `lucas.moreira`, `daniela.prado`, `henrik.larsson`, `flavia.marques`, `sofia.nakamura`, `helena.castro`, `omar.haddad`, `larissa.fontes`, `mateus.aragao`, `priya.nair` | *same as the username* | voter | 12 seed voters |

The `voter` role is the **only** role allowed to cast a ballot (`authenticated` has that permission revoked on
install). It grants no admin access. Registration is open, and every new account gets the `voter` role
automatically, so any visitor who registers can vote.

## Seeded content

8 polls · 35 image options · 80 ballots, drawn from real Drupal community surveys and forum threads. Two
states are seeded on purpose so both configurations are visible to a reviewer:

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
| `/user/register` | Open registration; the new account receives the `voter` role and can vote immediately | Anonymous |

## Admin pages

All under the Gin admin theme. Reachable by the administrator only.

| Path | What it does |
| --- | --- |
| `/admin/content/polls` | Poll listing (Poll, Open, Options, Votes, Operations) |
| `/admin/content/polls/add` | Create a poll **with its options on the same screen** (title, description, image upload, draggable weight, add/remove rows) |
| `/admin/content/polls/{id}/edit` | Edit a poll and its options |
| `/admin/content/polls/{id}/delete` | Delete a poll |
| `/admin/config/voting` | Global switch — turn voting on/off across the whole site (CMS and API) |
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
7. **Zero theme dependency.** The module attaches its own Bootstrap and its own CSS, so it works under any
   theme.
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
`vote-status`. Each folder holds its own `.component.yml` (prop schema), `.twig`, `.scss` and compiled `.css`
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

On uninstall the module reverts everything it set up: it restores the previous front page and registration
setting, removes the `voter` role, deletes the demo account and the seed voters, and removes every placed
*Poll list* block. After that, `/polls`, `/docs`, `/docs/openapi.json` and `/api/v1/polls` all return `404`.

---

## Dependencies

Composer manages everything under `web/`.

- **Module dependency:** core `image` only.
- **Contrib:** `drupal/gin` (^5.0) and `drupal/gin_toolbar` (^3.0) — the admin theme.
- **Front-end libraries**, vendored into `web/libraries/` by Composer scripts (`post-install-cmd` /
  `post-update-cmd`):
  - `twbs/bootstrap` (^5.3) → `web/libraries/bootstrap/dist`
  - `swagger-api/swagger-ui` (^5.32) → `web/libraries/swagger-ui/dist`
- **Dart Sass** is installed into the appserver by `.lando/scripts/install-dart-sass.sh`. There is no Node in
  the stack.
- **Deliberately not used:** core `rest`, core `jsonapi` (the API is hand-written), and `node`.

> Cleanup pending: `drupal/bootstrap_barrio` is still declared in `composer.json` from an earlier theming
> approach and is no longer used.

## Project layout

```
.lando.yml                                   Environment: recipe, services, proxy, tooling
.lando/                                      Nginx vhost, php.ini, the Dart Sass install script
scripts/install.sh                           Idempotent installer (behind `lando install`)
composer.json / composer.lock                Dependencies; scripts vendor Bootstrap and Swagger UI
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

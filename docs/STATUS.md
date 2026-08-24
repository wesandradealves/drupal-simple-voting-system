# Project Status

Operational source of truth: what is done, what is next, and how to pick the work back up.
Architectural reasoning lives in [`architecture-decisions.md`](architecture-decisions.md); how to run
the environment lives in [`../README.md`](../README.md).

Last updated: 2026-08-24.

---

## Resume here

```bash
cd /media/wesley/Novo\ volume7/www/sistema-de-votacao
lando start
lando drush status                                  # expect: Drupal 11.4.5, profile minimal, default theme olivero
lando drush pm:list --status=enabled | grep drupal_simple_voting
curl -s https://sistema-de-votacao.lndo.site/api/v1/polls   # JSON list of polls
```

The `drupal_simple_voting` module is built, and the Postman collection and the database dump are shipped. What
is left is configuration export and the design questions still open in the architecture decisions.

---

## Task just completed

**`README.md` rewritten from scratch to match the current single-module system.**

- Rewrote the README against the running code (verified by execution), not the previous draft: the earlier
  two-module + custom-theme description was replaced by the single `drupal_simple_voting` module on stock
  Olivero + Gin.
- Gave the API surface the prominence the delivery needs: a CMS-routes table, an admin-routes table, a Swagger
  section (`/docs` UI + `/docs/openapi.json` OpenAPI 3.1 generated in PHP from the real routes), and a
  four-endpoint API table with request bodies and every response code (`201/400/403/404/409/422` on `/vote`,
  each confirmed in `VoteResource`).
- Documented the seed users and passwords, the seeded content (8 polls, 35 options, ~70 ballots; one closed
  poll, two that hide totals after voting), the two-step uninstall, the SDC/`--vt-*` token pipeline, the
  Postman assets, and the `bootstrap_barrio` cleanup pendency.

## Environment state

Full-stack verification by execution on 2026-08-23; code and documentation changes since are file-level.

| | |
| --- | --- |
| Site | <https://sistema-de-votacao.lndo.site> — 200 over both HTTP and HTTPS |
| Mail inbox | <http://mail.sistema-de-votacao.lndo.site> — 200 |
| Lando | 3.26.0, four services up: `appserver`, `appserver_nginx`, `database`, `mailpit` |
| Drupal | 11.4.5, profile `minimal`, stock `olivero` default theme, `gin` admin theme |
| PHP / MariaDB / Nginx | 8.4.15 / 11.4.7 / 1.29.1 |
| Drush | 13.7.6.0, database connected, bootstrap successful |
| `core:requirements --severity=2` | empty — no error-level requirements |
| Front-controller allow-list | `/index.php` 200; `/autoload.php`, `/vendor/autoload.php`, `/core/scripts/db-tools.php` all 404 |
| Trusted hosts | `Host: evil.example.com` against the published Nginx port → 400 |

## Test state

No tests exist and none are expected to. This is a Drupal project: tests are written locally for
verification during development and removed before committing, never versioned. Verification here is by
execution — `curl`, `lando drush status`, `core:requirements`.

## Key files

| Path | What it is |
| --- | --- |
| `.lando.yml` | The whole environment: recipe, services, proxy, tooling (`sass` points at the module) |
| `.lando/nginx/default.conf.tpl` | Nginx vhost carrying the PHP front-controller allow-list |
| `scripts/install.sh` | Idempotent install: minimal profile, uninstalls `node`, enables the module, selects Olivero and Gin |
| `web/modules/custom/drupal_simple_voting/` | The one module: domain, CMS pages, JSON API, `/docs` |
| `.../src/BallotBox.php` | The single write path; enforces one vote per user per question |
| `.../src/BallotAudit.php` | The log-channel audit trail, called from `BallotBox::cast()` |
| `.../src/Controller/VoteResource.php` | `POST /vote`; maps domain exceptions to `409`/`403`/`422`/`400`/`404` |
| `.../src/Controller/DocsPage.php` | `/docs` Swagger UI and the OpenAPI 3.1 document from the real routes |
| `.../src/Entity/` | `voting_question`, `voting_option`, `voting_vote` |
| `.../drupal_simple_voting.routing.yml` | HTML pages, the JSON API (`_format: json`), `/docs`, `/admin/config/voting` |
| `.../drupal_simple_voting.services.yml` | Ballot box, tally, policy, audit, serializer, log channel, API-format subscriber |
| `.../components/` | SDC: `poll-card`, `ballot`, `ballot-option`, `vote-status` |
| `.../scss/` `.../css/` | Module SCSS source (tokens → `--vt-*`) and the compiled CSS |
| `postman/` | `simple-voting.postman_collection.json` + `simple-voting.postman_environment.json` |
| `db/simple-voting.sql.gz` | Restorable dump with the seed content and users (restore proven) |
| `docs/architecture-decisions.md` | AD-01 … AD-54 |

`.scratch/` is working material, not a deliverable, and is not committed.

---

## Next steps, in order

1. **Export configuration to `config/sync`** (AD-24) — the directory is declared but still holds only
   `.gitkeep`; the installed extension list, entity definitions, permissions and
   `drupal_simple_voting.settings` should be readable from the repository, not only the database.
2. **Settle the open design questions** below before they harden by accident.

## Open items to settle

- **No `post-start` event in `.lando.yml`.** `lando start` does not install Drupal; `lando install` is a
  separate step, and the README says so. Wiring the event would make the quick start a single command at the
  cost of a slower first start.
- **Design questions carried in `architecture-decisions.md`:** result freshness versus write amplification,
  anonymous read access, the live-refresh interval, and Inter versus the system font stack.

_Resolved since the last status:_ a restorable database dump is shipped at `db/simple-voting.sql.gz` (restore
proven — exit 0, 8 questions / 35 options / 70 votes / 15 users, with the `voting_vote__user_question` unique
key intact); the `sass`/`sass:watch` tooling in `.lando.yml` now points at
`web/modules/custom/drupal_simple_voting` (it previously referenced the deleted theme); the Postman collection
and environment are shipped under `postman/`.

## Uncommitted work

Nothing has been committed in this session. `git status` on `master` shows the old delivery approach removed
(the Compose and `docker/` files, `Makefile`, `.env.example` and the whole `web/themes/custom/voting_theme/`
tree deleted); `.gitignore`, `composer.json`, `composer.lock`, `scripts/install.sh` and `README.md` modified;
and `.lando.yml`, `.lando/`, `db/`, `docs/`, `postman/` and `web/modules/` untracked.

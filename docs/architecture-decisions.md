# Architecture Decisions

Running record of every architectural decision taken on this project, with the reasoning behind it.
Decisions marked **Accepted** are implemented and verified. Decisions marked **Proposed** are agreed
but not yet built.

The README's "Architecture and design decisions" section carries the summary an evaluator needs;
this file carries the full reasoning, including the options that were rejected and why.

---

## Environment

### AD-01 — Nginx + PHP-FPM + MariaDB rather than DDEV or the official Drupal image
**Accepted.**
The stack mirrors a conventional LAMP/VPS deployment, so what runs locally resembles what would run in
production. The official `drupal` image bundles Apache, ships no Drush and hides the PHP configuration.
DDEV would have forced the evaluator to install a tool beyond Docker.
*Superseded in part by AD-29:* Lando's `drupal11` recipe now provides exactly this stack — Nginx,
PHP-FPM and MariaDB — so the choice stands but the hand-written Dockerfile that used to implement it
is gone.

### AD-02 — Every version is pinned, `composer.lock` is committed
**Accepted.**
Drupal core is constrained to `^11.4` and every Composer dependency is locked. Drupal 12 ships in December
2026, and an unpinned scaffold would silently drift across the major boundary.
*Revised by AD-29:* the service versions are now the recipe's to choose. `.lando.yml` pins the series
(`php: '8.4'`, `database: mariadb:11.4`) and Lando resolves the patch — 8.4.15 and 11.4.7 today. Dart Sass is
still pinned exactly, to 1.103.1, because the project fetches it itself.
*Note:* the effective core floor is Gin, which requires `^11.2`. No Gin release spans 11.0/11.1.

### AD-03 — Only authored files are versioned
**Accepted.**
`vendor/`, `web/core`, contrib modules and themes, `web/libraries` and scaffold output are gitignored and
rebuilt by `composer install`. Every versioned file is written by hand; the whole authored tree is listed in
the README's "Project structure".
*Consequence:* the evaluator needs network access to `repo.packagist.org` and `packages.drupal.org` on first
install. Accepted because it is the Drupal community standard and keeps the diff readable.

### AD-04 — Installation is a script, not a wizard
**Accepted.**
`scripts/install.sh` is driven by `.env` and is idempotent: it waits for the database, guards the destructive
`site:install` behind a bootstrap check plus `FORCE_INSTALL`, and matches on literal lines before appending to
`settings.php`.
*Why literal lines:* `default.settings.php` documents the same setting keys in comments, so a keyword match
finds the documentation and silently skips the real write. This was a real bug, caught by execution.

### AD-05 — The container user matches the host user
**Accepted.**
Every command that touches project files — Composer, Drush, the installer, the Sass watcher — runs as a user
whose uid and gid match the host's, so the mount never accumulates root-owned files and no generated file
needs `sudo` to edit or delete.
*Revised by AD-29:* this used to be build args on a hand-written Dockerfile. Lando does it: `lando ssh -c id`
reports `uid=1000(www-data) gid=1000(www-data)`, matching the host. Verified: zero root-owned files under
`web/` after a full install.

### AD-06 — Front-controller allow-list in Nginx
**Accepted.**
Only `index.php`, `update.php` and the three core scripts are forwarded to PHP; every other `.php` returns 404.
Drupal's `.htaccess` covers this on Apache and has no effect under Nginx.
*Verified:* `/autoload.php`, `/vendor/autoload.php` and `/core/scripts/db-tools.php` all return 404, while
`/index.php` returns 200.
*Carried into Lando by AD-29:* the `drupal11` recipe does not ship this rule, so the vhost is overridden.

### AD-07 — Both Lando and Docker Compose
**Superseded by AD-29.**
The original plan was to ship both environments against the same docroot, database and installer. Docker
Compose has since been removed; Lando is the only environment.

---

### AD-29 — Lando is the only environment; Docker Compose is removed
**Accepted.**
The brief requires the environment to be configured with Lando, so Lando is the entire environment. `Makefile`,
`compose.yaml` and `docker/` were deleted rather than kept alongside: an evaluator installs Lando, runs
`lando start` and then `lando install`, and there is exactly one description of the stack to trust. Two
environments over one docroot drift, and the one the evaluator happens to run is the one that has to work.
*What the move had to re-establish, because the recipe does not provide it:*

| Concern | Recipe default | What `.lando.yml` does instead |
| --- | --- | --- |
| PHP front controller | `location ~ '\.php$\|^/update.php'` — hands every `.php` to FPM | `config.config.vhosts` → `.lando/nginx/default.conf.tpl`, carrying the AD-06 allow-list |
| PHP runtime | recipe `php.ini` | `config.config.php` → `.lando/php/php.ini` |
| Dart Sass | absent | `build_as_root` → `.lando/scripts/install-dart-sass.sh` (AD-13) |
| Installer paths | — | `PROJECT_ROOT="${LANDO_MOUNT:-/app}"`, `DB_HOST=database` |

*One deliberate omission:* `.lando/php/php.ini` sets no `sendmail_path`. The Mailpit plugin writes its own
`conf.d` fragment pointing at `sendmailpit:1025`, and that fragment has to win.
*Consequence:* no `events:` hook is wired, so `lando start` alone does not install Drupal. `lando install` is a
second, explicit step. Whether to add a `post-start` event is an open item.
*Also:* this build ships no `lando xdebug` command. The toggle is `config.xdebug: true` plus `lando rebuild`,
which Lando expands into `XDEBUG_MODE`, read by `xdebug.mode = ${XDEBUG_MODE}` in `.lando/php/php.ini`.
*And:* the `mailpit` service pins no version, because the plugin bundled with Lando 3.26 refuses
`version: 1.31.0`. It runs the plugin's own build, 1.27.

## Assets and security

### AD-08 — No third-party asset is ever loaded from an external origin
**Accepted.**
When a third-party library is needed, it is downloaded into the application and exposed through a Drupal
asset library. Bootstrap 5.3.8 is installed by Composer and copied to `web/libraries/bootstrap/dist` by a
Composer script wired to `post-install-cmd` and `post-update-cmd`.
*Rationale:* supply-chain compromise, visitor-IP leakage to third parties, availability coupling, and a wider
CSP surface. A locally served asset removes all four.
*How to verify:* load any page with devtools open — no request leaves the origin.

### AD-09 — `voting_theme` declares the Bootstrap library itself
**Superseded by AD-52.**
*The custom theme was removed; the `drupal_simple_voting` module now attaches Bootstrap through its own
library. The reasoning below is kept as the record of why a theme-level declaration was chosen while the
theme existed.*
The theme attaches Bootstrap through its own `voting_theme.libraries.yml` rather than Bootstrap Barrio's
`bootstrap_barrio_source` theme setting.
*Rationale, and it is a real trap:* Barrio only attaches Bootstrap when the `bootstrap_library` module is
installed, and that module has no Drupal 11 release. The fallback branch reads `bootstrap_barrio_source`,
which Barrio ships with no default value. On Drupal 11 the result is a site with no Bootstrap at all — no
error, no log entry. Declaring the library in our own theme sidesteps the mechanism entirely.
*Also:* Barrio's CDN option is hard-pinned to Bootstrap 5.2.0 from 2022; the local copy is 5.3.8.

### AD-10 — Trusted host patterns are enforced
**Accepted.**
`settings.php` receives `trusted_host_patterns` derived from `DRUPAL_BASE_URL`. Verified: a request carrying
an unlisted `Host` header is rejected with 400.

---

## Theming

### AD-11 — Bootstrap Barrio as base theme, `voting_theme` as its child
**Superseded by AD-51.**
*The custom theme was deleted. The site runs the stock Olivero theme and all presentation moved into the
`drupal_simple_voting` module as SDC components. The reasoning below is kept as the record of the base/child
choice while the theme existed.*
The child theme carries Barrio's full region map and its theme settings, because Drupal inherits neither from
the base theme chain: `ThemeExtensionList` defaults to a single `content` region, and theme settings are read
from exactly two config objects with no walk up the chain.
*Known risk:* Barrio 5.5.20 was released in June 2025 and predates Drupal 11.2, 11.3 and 11.4. It declares
`^10.3 || ^11.0` and installs cleanly, and the stack was smoke-tested, but it has never been tested against
the core in use.

### AD-12 — Gin as the admin theme
**Accepted.**
Gin 5.0.15 with `gin_toolbar` 3.0.3, the only contrib module the site installs.
*Revised by AD-30:* the `minimal` profile installs neither `navigation` nor the legacy `toolbar`, and no
Olivero. The installed themes are `stark`, `bootstrap_barrio`, `voting_theme` (default), `claro` and `gin`
(admin); `claro` is the fallback, and it stays installed because uninstalling a theme permanently deletes the
config it owns.

### AD-13 — Dart Sass standalone binary, no Node
**Accepted.**
The pinned Dart Sass 1.103.1 native binary is installed into the appserver by
`.lando/scripts/install-dart-sass.sh`, which `.lando.yml` runs under `build_as_root`. It is not `node-sass` and
not the npm `sass` package: the tarball contains a shell wrapper, a Dart VM, a snapshot and a licence — no
JavaScript.
*Why the whole tree is extracted to `/opt/dart-sass`:* the `sass` entry point is a shell wrapper that execs
`"$path/src/dart"`, so copying only the wrapper produces a binary that dies looking for its own runtime. The
script is idempotent — it exits early when the installed binary already reports `SASS_VERSION`.
Compiled CSS is committed, so production serves a static file and PHP is never involved in compilation.
*Rejected:* `drupal/scss_compiler`, which does support Drupal 11, because it ships `cache: false` and therefore
recompiles on every request until an admin checkbox is ticked, and because it pins `scssphp ^1.0` — a Sass
dialect with no `@use`, no `@forward` and no `math.div`, frozen by upstream policy.
*Also verified:* the build gates on the exit code and passes `--no-error-css`, because a failed Sass build
otherwise writes a valid-looking stylesheet containing only an error comment.

### AD-14 — Components follow Drupal's SDC method, and the `sdc` module is never enabled
**Proposed.**
Single Directory Components are the current Drupal way to build reusable UI, and using them supports the
brief's "best practices" criterion.
*Important:* in Drupal 11.4 `core/modules/sdc/` contains a single file, `sdc.info.yml`, declaring
`lifecycle: obsolete` and `hidden: true`. Discovery, rendering and validation live in
`Drupal\Core\Theme\ComponentPluginManager`. Enabling that module is a defect, not a dependency — much
published tutorial material still instructs otherwise.
*The theme sets* `enforce_prop_schemas: true`, which binds only this theme's components and makes prop
schemas mandatory rather than optional.

### AD-23 — Bootstrap Barrio's CDN libraries are neutralised structurally
**Proposed.**
Barrio ships 39 asset libraries carrying `type: external` assets pointing at `cdn.jsdelivr.net`. None is
attached by the current configuration, but a single theme setting could start loading them.
`voting_theme.info.yml` will override them so the policy in AD-08 becomes impossible to break by
configuration, not merely switched off.

### AD-24 — Configuration is exported to `config/sync`
**Proposed.**
`config/sync/` currently holds only placeholder files, so the installed module list and every theme setting
exist only in the database. Until configuration is exported, the claim "this site contacts no third-party
origin" cannot be verified from the repository alone, and an admin could undo it without leaving a trace in
git.

### AD-25 — The ballot is a Drupal Form API form
**Proposed.**
The voting form uses core's Form API rather than a hand-built HTML form posting to the JSON endpoint. The
standing instruction is to stay as native as possible: core renders the radio inputs, provides the form token,
and validates the submitted value against `#options` — a forged POST naming an option that does not exist is
rejected before any handler runs.
*Corrected by AD-38:* this decision used to end "and supplies the AJAX plumbing". The ballot carries no
`#ajax`; the vote is an ordinary POST.
*Consequence:* the option markup (image, title, description) is produced by theming core's radio elements
rather than by inventing an input, which keeps keyboard and screen-reader behaviour correct for free.

### AD-26 — Results update live
**Proposed.**
Vote counts refresh without a page reload. Drupal core has no native polling mechanism, so the honest cost is
a small amount of first-party JavaScript attached to a component, calling our own results endpoint. No
third-party library is involved, so AD-08 holds.
*Consequence:* the page's caching strategy must isolate the live fragment so a constantly changing count does
not make the whole page uncacheable.

### AD-28 — Theme JavaScript is layered ES6 classes, one responsibility per file
**Accepted.**
Front-end behaviour is written as ES6 classes on a single namespace, one class per file, wired up through
`Drupal.behaviors` and core's `once()`. Three layers, each ignorant of the others' concerns:

| Layer | File | Knows about | Never touches |
| --- | --- | --- | --- |
| Transport | `js/api/VotingApiClient.js` | HTTP, endpoints, errors | the DOM |
| Policy | `js/live/ResultsPoller.js` | intervals, backoff, tab visibility | HTTP or the DOM |
| Presentation | `js/ui/ResultsView.js` | the DOM, announcements | HTTP |

**Every `fetch` in the project lives in `VotingApiClient`.** Changing transport, adding retries, altering
authentication or stubbing the network in a test touches exactly one file.

`once()` prevents a Form API AJAX rebuild from instantiating a second poller on the same ballot.

*Rejected: ES modules.* `attributes:` is supported in `*.libraries.yml`
(`LibraryDiscoveryParser.php:240-244`), so `{ attributes: { type: module } }` would work — but
`AssetResolver.php:392` forces `preprocess` to FALSE whenever attributes are present, so every module file is
excluded from asset aggregation. Core itself uses no ES modules anywhere. Paying an aggregation penalty for a
pattern core does not use is the wrong trade in a project whose brief evaluates performance.

*Rejected: one large file.* Readable at four classes, unmaintainable at twenty.

*Note:* jQuery ships in core (4.0.0) but only loads when a library depends on it. This theme's JavaScript
depends on none of it.

### AD-27 — No brand or typography direction; the layout derives from studied references
**Proposed.**
There is no imposed typeface or brand. The visual design is drawn from a study of real voting and polling
products, so the layout is decided before implementation rather than improvised during it.
*Consequence:* no font file needs vendoring, and a system font stack keeps AD-08 trivially satisfied.
*Settled by AD-36:* the reference is Opinion Stage's `list` layout with a thumbnail, and it is copied value for
value rather than approximated. It specifies Inter, which reopens the font question this AD had closed.

---

### AD-34 — SCSS: two token layers, one Bootstrap bridge, `@use`/`@forward` only
**Proposed.**
*The constraint that decides everything else:* Bootstrap's SCSS source does not exist in this project.
`find web/libraries/bootstrap/ -name '*.scss' | wc -l` returns **0**, because the Composer script copies only
`vendor/twbs/bootstrap/dist/`. Recompiling Bootstrap from source is not a choice available without vendoring
third-party source into the repository, which AD-03 rules out. The theme therefore consumes the built
`bootstrap.min.css` and re-assigns Bootstrap's own custom properties.
*Two token layers, and they do different jobs:*

| Layer | Form | Resolved | Names |
| --- | --- | --- | --- |
| Primitives | Sass variables, `$` | at build time | the raw value — `$color-brand-500` |
| Semantics | CSS custom properties, `--vt-` prefix | at runtime | the role — `--vt-color-surface` |

Primitives can do what custom properties cannot: participate in Sass colour maths and in media-query
conditions. Custom properties can do what Sass cannot: change after the stylesheet is served, which is what
makes a runtime theme switch a single attribute rather than a second request. The `--vt-` prefix exists so the
theme's names can never collide with Bootstrap's `--bs-` or Barrio's `--color-*`.
*The bridge is exactly one file.* Every `--bs-*` re-assignment lives there, so the surface that depends on
Bootstrap's public custom-property names is one file wide and stays readable against a Bootstrap changelog.
*Module syntax only.* `@import` has been deprecated since Dart Sass 1.80 and is scheduled for removal in
3.0.0; the build runs 1.103.1 and emits a deprecation warning for every `@import`. So: `@use` to consume,
`@forward` to re-export, explicit namespaces (`@use "tokens" as t`), no `as *`, and no global functions —
`map.get`, `color.adjust` and `math.div` through their modules, never `map-get`, `darken` or `/`.

### AD-35 — Bootstrap is attached as `css.base`, never `css.component`
**Accepted.**
`voting_theme.libraries.yml` used to declare the `bootstrap` library under `css.component`. That is an ordering
defect, not a matter of taste.
*The mechanism, read in core:* `LibraryDiscoveryParser::buildByExtension()` adds the SMACSS category constant
to every file's weight — `LibraryDiscoveryParser.php:136`, `$options['weight'] += constant($category_weight);`
— and `web/core/includes/common.inc` defines `CSS_BASE = -200`, `CSS_COMPONENT = 0` and
`CSS_AGGREGATE_THEME = 100`. Twenty-six lines later, at `:162`, the same method puts *every* stylesheet
belonging to a theme, and every SDC stylesheet, into the same `CSS_AGGREGATE_THEME` group.
So under `component` the vendor framework carried weight 0 — the identical weight our own component CSS
carries — inside the identical aggregate group. The framework tied with the code whose whole job is to
override it, and the tie was resolved by discovery order rather than by intent. Under `base` it carries -200
and is unambiguously first.
*Measured with aggregation disabled* (`drush theme:dev on`): Bootstrap is the 6th stylesheet on the page,
Barrio's 37 sheets follow at positions 7 to 42, and `voting_theme/css/style.css` is 43rd.

---

### AD-36 — Opinion Stage is the approved visual reference, and it is copied value for value
**Proposed.**
AD-27 said the layout would be drawn from a study of real voting products rather than improvised. Real products
were studied and one was chosen: **Opinion Stage**, `list` layout with the `thumbnail` option kind. This settles
the open question "Which ballot layout ships" — a single column, the image beside the row.
*The decision is to copy, not to approximate.* Approximation is how a studied reference degrades into an
improvised one. The numbers below are read out of the widget's own stylesheet
(`assets.opinionstage.com/assets/widgets/poll-*.css`, 90,561 bytes) and transcribed with the full DOM structure
and interaction states in `.scratch/opinionstage-reference.md`.

| Element | Value |
| --- | --- |
| Thumbnail (`.w-option__media`) | 80×80, `border-radius: 12px`, `overflow: hidden` |
| Option row (`.w-option__content`) | `border-radius: 8px`, `padding: 16px 24px`, `line-height: 24px`, `gap: 16px` |
| Option (`.w-option_kind_thumbnail`) | flex row, `gap: 16px` |
| Option list (`.w-options_layout_list`) | `display: grid`, `gap: 12px` |
| Card (`.w-card`) | `display: grid`, `gap: 24px`, `padding: 40px 24px` |
| Result bar (`.w-option__chart-bar`) | absolute, `left/top: 0`, `height: 100%`, `z-index: 0` |

*The structural point that is easiest to get wrong:* **the result bar fills the row, not the thumbnail.** It is
absolutely positioned inside `.w-option__content`, and the thumbnail is that element's sibling, outside its
containing block. The image stays intact while the bar grows behind the label and the percentage. Running the
bar across the whole option would be a different design, not a rounding error.
*Colour is expressed as bare RGB triples* — `--primary-color: 67, 151, 247`, consumed as
`rgba(var(--primary-color), 0.1)` — so one custom property drives the default, hover, selected and chart-bar
fills at four different alphas. That is the technique to copy, not just the hex value.
*The single deliberate divergence:* the brief requires a short description per option and Opinion Stage has
none. It goes inside `.w-option__answer`, below the label, and changes none of the measurements above.
*Consequence for AD-34:* these are primitives, so they belong in the Sass token layer and never at a use site.
*Consequence still open:* the reference specifies Inter. AD-08 forbids external origins, so using Inter means
vendoring the font files. Whether that happens or the system stack of AD-27 stands in is not decided here.

### AD-37 — Bootstrap Barrio puts `.form-control` on every input, radios included
**Proposed.** *(The defect is verified on disk; the fix is not yet written.)*
`web/themes/contrib/bootstrap_barrio/templates/form/input.html.twig` is, in its entirety:
```twig
<input{{ attributes.addClass("form-control").removeClass("form-text") }} />{{ children }}
```
There is no type check. Every input receives `.form-control`, and in Bootstrap 5 `.form-control` is a
full-width text box. Barrio survives its own override only because its `form-element.html.twig` never prints
`{{ children }}` for radio and checkbox: it discards core's input and emits its own `<input{{ input_attributes }}>`.
The ballot's option template **does** print `{{ children }}` — it wraps core's native radio rather than
reinventing it, per AD-25 — so the trap springs the moment that template exists, and it springs visually, in
front of an audience.
*Fix:* `web/themes/custom/voting_theme/templates/form/input--radio.html.twig` carrying core's markup,
`<input{{ attributes }} />{{ children }}`. `Radio::getInfo()` already declares `'#theme' => 'input__radio'`
(`web/core/lib/Drupal/Core/Render/Element/Radio.php:33`), so the suggestion takes effect as soon as the file
exists. One line, no hook, no alter.
*Collateral security gain, and it is the better reason to own the template:* Barrio's `form-element.html.twig`
renders `{{ input_title | raw }}` at lines 106 and 116 — an unescaped path through a variable derived from
field content. Owning the ballot option's template means that path never executes on the ballot.

### AD-38 — The vote submits as an ordinary POST; the ballot carries no `#ajax`
**Proposed.**
The submit button is a plain Form API submit. No `#ajax` callback, no `AjaxResponse`, no wrapper replacement:
the vote posts, the domain service records it, and the response carries the result.
*The measured cost that decided it.* Attaching `#ajax` to any element makes
`RenderElementBase::preRenderAjaxForm()` attach `core/internal.jquery.form` and `core/drupal.ajax`
(`web/core/lib/Drupal/Core/Render/Element/RenderElementBase.php:351`), and those pull `core/jquery`. Measured
with `stat` and `gzip` in this checkout:

| File | Raw | gzip |
| --- | --- | --- |
| `core/assets/vendor/jquery/jquery.min.js` | 78,748 B | 27,443 B |
| `core/misc/jquery.form.js` | 41,767 B | 13,162 B |
| `core/misc/ajax.js` | 66,212 B | 16,822 B |
| **Total** | **186,727 B (~182 KiB)** | **57,427 B (~56 KiB)** |

Nothing else on this page asks for jQuery. `core/drupal` depends only on `core/drupalSettings`, and Barrio's
`global-styling` depends only on `core/drupal` with a plain IIFE in `js/barrio.js`. So jQuery here would be a
purchase, not a baseline cost of Drupal 11 — and what it buys is an in-place transition that was explicitly said
not to be needed.
*What it costs:* one page load per vote.
*Rejected for the same reason it was tempting:* `#ajax` on the `radios` element itself, voting on selection with
no button at all. Core propagates the parent's `#ajax` to each child radio and the default event for a radio is
`change`, so it works. It is also an accessibility defect: arrowing through a radio group fires `change` on every
option passed, and an irreversible act would be recorded on the first option a keyboard user touches. An
irreversible act needs an explicit confirmation.
*Corrects AD-25.* That decision used to end "and supplies the AJAX plumbing". Form API still renders the radios,
still issues the form token and still validates the submitted value against `#options`; it supplies no AJAX here.
*Leaves AD-26 untouched:* how the counts refresh afterwards is a separate decision and does not depend on the
form's transport.

## Domain and backend

### AD-15 — Domain logic lives in a custom module
**Proposed.**
A custom module owns the entities, the API, the admin forms and the voting service. The theme renders; it does
not decide.

### AD-16 — Custom content entities, never `node`
**Proposed.**
The test brief forbids using nodes. Questions, answer options and votes are custom content entity types with
their own storage, base fields, access handlers and administrative routes.

### AD-17 — The API is written by hand, without `rest` or `jsonapi`
**Proposed.**
Routes are declared in the module's `routing.yml` and served by controllers. Neither core module is installed.

### AD-18 — One identity: the Drupal user base
**Proposed.**
The external API authenticates against Drupal's own user storage and authentication, exactly as the CMS does.
There is no second identity system, no per-application key carrying a client-supplied user id, and no
identification by cookie or IP.
*Consequence, and this is the point:* the voter's identity is the Drupal `uid` on both paths, so a single
database constraint enforces "one vote per user per question" for the CMS and the API alike.

### AD-19 — Vote uniqueness is enforced by the database, not by application code
**Proposed.**
A unique index on `(uid, question)` is the arbiter, and the integrity-violation exception is translated into an
HTTP 409.
*Rationale:* the obvious implementation — check whether the user already voted, then insert — contains a race
condition. Two concurrent requests both pass the check and both insert. Only a database constraint has no
window between the check and the write. The brief explicitly evaluates behaviour under a high volume of
concurrent votes.
*Detailed by AD-43* — where the constraint is declared, and why the vote row carries `question` at all — *and by
AD-44*, the write path built on it.

### AD-20 — Observability is part of the deliverable
**Accepted.**
The module logs through its own channel with correct PSR-3 levels and enough context to diagnose a problem:
vote accepted, duplicate rejected, voting disabled, integrity error. The brief lists effective observability
as an evaluation criterion.
*Realised by AD-53:* the `BallotAudit` service on the `drupal_simple_voting` log channel, called from
`BallotBox::cast()`.

---

### AD-30 — The `minimal` install profile, and `node` is uninstalled
**Accepted.**
AD-16 forbids nodes. The `standard` profile does not merely install `node`, it installs the apparatus around it
— content types, the node form display, `field_ui`, `taxonomy`, `views`, the frontpage view — and every one of
those would have to be justified to an evaluator reading the module list. The site is installed with `minimal`
instead, and `node` was uninstalled.
*Enabled modules, in full:* `block`, `dblog`, `dynamic_page_cache`, `field`, `filter`, `mysql`, `page_cache`,
`path_alias`, `system`, `text`, `update`, `user`, `gin_toolbar`.
*Enabled themes:* `stark`, `bootstrap_barrio`, `voting_theme` (default), `claro`, `gin` (admin).
*Consequence, and it is the point:* every module the domain needs from here on is named deliberately and can
be defended one at a time. There is no `node`, no `taxonomy`, no `field_ui` and no `views`.
*Known leftover to fix before delivery:* `scripts/install.sh` still runs
`config:set --input-format=yaml node.settings use_admin_theme true`, a line inherited from the `standard`
era. With `node` uninstalled that config object does not exist — `drush config:get node.settings` answers
"Config node.settings does not exist" — and Drush 13 auto-confirms creating a new one under `-y`
(`ConfigCommands.php:160`), so a clean install would leave an orphan `node.settings` behind. The line has to
go.

### AD-31 — Paragraphs rejected, on the data model rather than on maintenance
**Accepted.**
*Stated honestly first, because the opposite claim is checkable and false:* `paragraphs` 8.x-1.23 declares
`core_version_requirement: ^10.3 || ^11` and the project is flagged **Actively maintained** on drupal.org.
Rejecting it as abandoned or as incompatible would be untrue. The reason is architectural.
*Reason one — it inverts the reference.* The Paragraphs widget declares
`field_types: ['entity_reference_revisions']`, so the reference must live on the **parent**: the question
holds a multi-value field pointing at its options. This project's model puts the reference on the **child** —
each answer option references its question. That direction is what makes a result count a single query on the
option table, and what keeps adding an option from being a write to the question entity. Adopting Paragraphs
means adopting its direction.
*Reason two — it pays for revisions nobody asked for.* A Paragraph is revisionable and translatable by
construction: four storage tables (data, revision, field data, field revision) against one for a plain custom
content entity. The option table is read on every ballot render and on every result refresh.
*Consequence:* answer options are a custom content entity with an ordinary entity reference back to the
question.

### AD-32 — `taxonomy` rejected, because it would reinstall `node`
**Accepted.**
`web/core/modules/taxonomy/taxonomy.info.yml` declares `dependencies: - drupal:node` and `- drupal:text`.
`ModuleInstaller::install()` walks `requires` and installs whatever is missing
(`ModuleInstaller.php:162`), so enabling taxonomy to categorise questions would silently reinstall `node` —
exactly what AD-16 and AD-30 exclude.
*If categorising questions ever becomes a requirement:* a `list_string` base field on the question entity, with
the allowed values declared in code. *Honest caveat:* there is no `list_string` base field anywhere in core
11.4.5 to copy from, so it would be written against the field-type plugin's own definition rather than adapted
from a core example.

### AD-33 — `block_content`, Layout Builder, `field_ui` and Views all rejected
**Accepted.**
- **`block_content`** — a second content-entity system, with its own bundles and its own admin surface, for
  editorial blocks this site does not have. `block` itself stays: it is how regions get populated.
- **Layout Builder** — and it must be described correctly: `layout_builder.info.yml` carries **no `lifecycle`
  key**, so core treats it as **stable**. Calling it experimental would be wrong. It is rejected because it
  pulls in `layout_discovery`, `contextual` and `block`, and because per-entity layout overrides are a
  content-editor feature this system has no use for. The ballot's layout is the theme's job.
- **`field_ui`** — fields created through the UI live in configuration the evaluator cannot read from the
  repository. Base fields declared in the entity class are code, and show up in a diff.
- **Views** — the two listings this system needs, the question index and the results, are entity queries in a
  controller. Views would add a configuration surface and a query builder to avoid roughly twenty lines of
  code, and would put the listing logic somewhere a reviewer has to boot a site to read.

---

## The data model

> The classes named in AD-39 to AD-48 exist under `web/modules/custom/drupal_simple_voting/`, but the module has not been
> installed and no vote has been cast. By this file's own convention that keeps every one of them **Proposed**:
> the schema argument is read from core's source, not from a running database.

### AD-39 — Three content entity types, and each one is exactly one table
**Proposed.**
`voting_question`, `voting_option` and `voting_vote` are custom `ContentEntityType`s with **no bundles, no
`data_table` and no `revision_table`** — not translatable, not revisionable, one class each.
*The consequence is the reason for the shape.* Every field is a base field of cardinality 1, so every one of
them qualifies for shared-table storage: `DefaultTableMapping::allowsSharedTableStorage()` returns TRUE only
when the definition `isBaseField()` and is not `isMultiple()`
(`web/core/lib/Drupal/Core/Entity/Sql/DefaultTableMapping.php:513`). No dedicated field table is ever created,
so each entity type is one table and a load is one SELECT.
*Precedent in core:* `File` declares `base_table: 'file_managed'` and nothing else — no data table, no revision
table (`web/core/modules/file/src/Entity/File.php:57`).

| Entity | Base fields |
| --- | --- |
| `voting_question` | `id` (serial), `uuid`, `title` (string), `description` (string_long), `show_results` (boolean, default FALSE), `status` (boolean, default TRUE), `created`, `changed` |
| `voting_option` | `id`, `uuid`, `question` (entity_reference → `voting_question`, required, NOT NULL), `title` (string), `description` (string_long), `image` (image), `weight` (integer) |
| `voting_vote` | `id`, `uuid`, `question` (entity_reference, NOT NULL), `option` (entity_reference, NOT NULL), `uid` (entity_reference → `user`, NOT NULL), `created` |

*The field the question does not have.* There is no reference to options anywhere on `voting_question`. That
absence is deliberate and it is AD-41; the whole model turns on it.
*This is also the storage reason behind AD-33's rejection of `field_ui`.* A field created through the UI is not
a base field, so `allowsSharedTableStorage()` refuses it and it lands in its own table. The composite unique key
of AD-43 exists only on the shared table. AD-33 rejected `field_ui` on reviewability; this is the harder half of
the argument.

### AD-40 — The public identifier is the entity UUID, and the database enforces its uniqueness
**Proposed.**
The brief requires a public identifier that is not the sequential row id. It is the entity `uuid`, on all three
types, and it is what the API exposes and what appears in a URL.
*Why it costs nothing to enforce:* `UuidItem::schema()` adds
`$schema['unique keys']['value'] = ['value'];`
(`web/core/lib/Drupal/Core/Field/Plugin/Field/FieldType/UuidItem.php:56-59`), so uniqueness is a database
constraint on the shared table — not a convention the application has to remember to keep.
*The numeric `id` stays internal.* Foreign keys and the composite unique key of AD-43 are built on it, because
an integer is what an index wants to compare.
*Closes the open question "Question identifier".*

### AD-41 — The reference lives on the child
**Proposed.**
`voting_option.question` points at its question. `voting_question` holds nothing pointing back at its options.
*Precedent in core:* `Comment` puts the pointer to the parent on the child —
`$fields['entity_id'] = BaseFieldDefinition::create('entity_reference')`
(`web/core/modules/comment/src/Entity/Comment.php:257`).
*What the direction buys:*
- **An index for free.** `EntityReferenceItem::schema()` returns `'indexes' => ['target_id' => ['target_id']]`,
  so rendering a ballot is one indexed SELECT — `WHERE question = ?` — regardless of how many options it has.
- **No write to the question.** Adding, editing or reordering an option writes the option row. Casting a vote
  writes the vote row. The question row is read-mostly by construction, which is exactly what a row read on
  every ballot render should be.
*Honest caveat about the precedent:* `CommentStorageSchema` deletes that automatic `target_id` index, because a
better composite index already covers the same column. `Comment` is cited here to establish the pattern and the
generated index name, not as an instruction to keep the index.
*This is the same argument AD-31 used to reject Paragraphs.* Its widget declares
`field_types: ['entity_reference_revisions']`, which forces the multi-value reference onto the parent. Adopting
Paragraphs means adopting the opposite direction and giving up everything listed above.

### AD-42 — The option image is an `image` base field, never a raw `managed_file`
**Proposed.**
`ImageItem` already declares an index on `target_id`, a real foreign key to `file_managed`, and a `list_class`
of `FileFieldItemList`, whose `postSave()` registers the file with the `file.usage` service. A raw
`managed_file` form element gives none of that: the foreign key, the index and the usage bookkeeping would all
be hand-written, and cron would delete the temporary file out from under the option.
*Consequence, and it is a small dependency list on purpose:* the module declares exactly one core dependency,
`drupal:image`, which pulls `file`, which pulls `field`. `drupal:text` is **not** declared, because
`string_long` is core (`Drupal\Core\Field\Plugin\Field\FieldType\StringLongItem`), and `drupal:options` is not
declared either.
*Physical columns on `voting_option`:* `id`, `uuid`, `question`, `title`, `description`, `weight`,
`image__target_id`, `image__alt`, `image__title`, `image__width`, `image__height`.

### AD-43 — `question` is denormalised onto the vote row so that `UNIQUE (uid, question)` can exist
**Proposed.**
`voting_vote` stores both `option` and `question`, even though the question is derivable by joining through the
option. **This is denormalisation on purpose, and it is stated rather than hidden.** Without a `question` column
on the vote row there is no column pair to constrain, and "one vote per user per question" would fall back to
application code — which AD-19 rules out and AD-44 explains why.
*Where it is declared:* `handlers['storage_schema'] => VotingVoteStorageSchema::class`, the same wiring `Comment`
uses, with `getEntitySchema()` adding two things to the base table:
```php
$schema['voting_vote']['unique keys'] += ['voting_vote__user_question' => ['uid', 'question']];
$schema['voting_vote']['indexes']     += ['voting_vote__tally' => ['question', 'option']];
```
*Precedents for a composite unique key in core, and the wrong ones matter:* `UserStorageSchema.php:21-23`
declares `'user__name' => ['name', 'langcode']`, and `ContentModerationStateStorageSchema.php:21-33` declares a
five-column key under a comment that reads, verbatim, "Creates unique keys to guarantee the integrity of the
entity". Both write to the data table; `voting_vote` is not translatable, so the target is
`$this->storage->getBaseTable()`. **`PathAlias` and `Comment` are not precedents here** — they add indexes, not
unique keys, and citing them would be an error an evaluator can check in seconds.
*The failure mode that has to be closed by hand.* `not null` is **not** applied automatically to the columns of a
composite unique key. The helper that would do it, `addSharedTableFieldUniqueKey()`, takes a single column name,
and core's docblock warns that "many databases do not reliably support unique keys on null columns". If `uid` or
`question` can be NULL the constraint silently stops enforcing anything, and nothing reveals it until a load
test. `getSharedTableFieldSchema()` therefore marks `uid`, `question` and `option` `not null` explicitly.
*The other thing `entity_reference` does not do:* it declares no foreign keys at all — unlike `ImageItem`, which
does. They are declared by hand with `addSharedTableFieldForeignKey()`, following `CommentStorageSchema`, and
AD-48 is what keeps them from becoming a lie.

### AD-44 — Recording a vote inserts and lets the database refuse
**Proposed.**
This is the answer to the brief's concurrency criterion, and it is one paragraph long. The write path contains
no "check whether this user already voted, then insert". It inserts:
```php
try {
  $vote->save();
}
catch (IntegrityConstraintViolationException $violation) {
  throw new DuplicateVoteException(previous: $violation);
}
```
*Why a preceding SELECT loses the race.* Between the check and the insert there is a window. Two concurrent
requests both read "no vote yet", both pass the check, and both insert. Wrapping the pair in a transaction does
not close it either: at the default isolation level neither transaction sees the other's uncommitted row. The
unique key has no window at all — one insert commits, every other one raises, deterministically, however many
arrive at once. **The constraint is the only arbiter, and the application never asks the question it answers.**
*Consequence for error handling:* a duplicate is not a fault to be logged as one. It is a domain outcome,
`DuplicateVoteException`, which the CMS renders as "you have already voted" and the API returns as HTTP 409.
`VotingClosedException` is its sibling for the kill switches of AD-47. Both are thrown by `BallotBox::cast()`,
which also refuses an option that does not belong to the question it was submitted against.
*Unverified, and it is the risk worth naming:* which exception the MySQL/MariaDB driver raises on the violation,
and whether it propagates out of `$vote->save()` in catchable form, has not been proven by execution. Until two
genuinely concurrent requests have been run against this code, "the constraint settles it" is a claim about the
schema, not about the application.

### AD-45 — Counting is one aggregate query; the denormalised counter is rejected
**Proposed.**
`SELECT option, COUNT(*) FROM voting_vote WHERE question = ? GROUP BY option`, served by the
`voting_vote__tally` index on `(question, option)` declared in AD-43. One query per result read, whatever the
number of options.
*Rejected: a counter column on `voting_option`, incremented on each vote.* It reads faster and writes far worse.
An `UPDATE` against a counter is a hot row: every vote for the same option queues behind the same row lock, which
serialises precisely the scenario the brief evaluates. Inserts into distinct rows do not block one another, and
the unique key of AD-43 has already removed the read the counter would have been protecting. Choosing the
counter would optimise the cheap half of the workload by damaging the expensive half.
*Closes the open question "Result counting".*
*What is not settled here:* invalidating a cache tag on every vote drops the cached result on every vote, which
under load is effectively no cache on the read path; the alternative is a short `max-age` and counts that lag by
at most one refresh interval. That is a caching decision, not a storage one, and it stays open below.

### AD-46 — Three domain services, named after the domain, and the form never persists
**Proposed.**

| Service id | Class | Responsibility |
| --- | --- | --- |
| `voting.ballot_box` | `BallotBox` | records a vote; turns the integrity-constraint violation into `DuplicateVoteException` |
| `voting.tally` | `VoteTally` | counts votes — the single aggregate query of AD-45 |
| `voting.policy` | `VotingPolicy` | decides whether this user may vote, and whether results are shown |

*The names are domain nouns, and that is a project rule rather than a preference.* This project's conventions ban
`Manager`, `Helper`, `Handler` and `Processor` as class suffixes and require names taken from the domain's
ubiquitous language. `VotingManager` would name the framework's habit; `BallotBox` names the thing that holds
votes, and a reader knows what belongs in it without opening the file.
*The rule that gives the split its point:* **the form does not know how to persist.** `submitForm()` calls
`voting.ballot_box` and does nothing else with storage; the API controller calls the same service. "One vote per
user per question" therefore exists in exactly one place, and the API does not reimplement it — which is what
makes AD-18's single identity worth having. Two entry points, one rule, one place to change it.

### AD-47 — Two kill switches at two different scopes, both decided in the domain layer
**Proposed.**
- **`voting.settings:enabled` — global.** Simple config, one boolean, edited through a `ConfigFormBase`. FALSE
  closes voting across the whole site.
- **`show_results` — per question.** A base field on `voting_question`, deciding whether that question's totals
  are visible after a vote.

They are different switches at different scopes and neither is the other's fallback. Both are evaluated by
`voting.policy` and by the access handler — **never in a Twig template.** A template that decides whether voting
is open is a business rule the API cannot reuse, which would undo AD-46 at the last step.
*Cacheability:* `voting.settings` is added as a cacheable dependency wherever it is read, so flipping the global
switch invalidates what depends on it and nothing else.
*Naming, and it matters in front of a Drupal audience:* this has nothing to do with core's
`page_cache_kill_switch`, a response-policy service that makes one response uncacheable. Call the business switch
`voting.settings:enabled` and the ambiguity never arises.

### AD-48 — Deletion cascades in `postDelete()`
**Proposed.**
`VotingQuestion::postDelete()` deletes the question's options and its votes. `VotingOption::postDelete()` deletes
the votes cast for that option.
*Why it is not optional.* `entity_reference` generates no foreign keys, so AD-43 declares them by hand — and a
declared foreign key with no cascade is worse than no key at all: deleting a question either strands orphan rows
that the declared constraints now misdescribe, or starts failing at runtime against a rule the application never
honours. Two measures, not one: declare the keys **and** cascade the deletes.
*Precedent in core:* `Comment::postDelete()` loads the replies and calls `$comment_storage->delete($comments)`
(`web/core/modules/comment/src/Entity/Comment.php:197-208`). `Vocabulary` and `Term` do the same.

## Deliverables

### AD-21 — A self-hosted `/docs` page with the full API specification
**Proposed.**
Swagger UI is served from the application with its assets vendored locally, consistent with AD-08. It must
allow every field of every endpoint to be exercised. A Postman collection is delivered alongside it, as the
brief requires.

### AD-22 — A database dump ships with the repository
**Proposed.**
Required by the brief. The installer gains an import path so the evaluator can restore the delivered state.

---

## Consolidation — one module, no custom theme

*A pass on 2026-08-24 after the decisions above: the API module was folded into the domain module, the
module was renamed, and the custom theme was removed. The domain — the entity types, the uniqueness rule,
the tally and the policy — is unchanged. These six decisions are all implemented.*

### AD-49 — One module, not two: the API lives beside the domain
**Accepted.**
The plan carried a separate `voting_api` module. It was folded into the domain module, because a JSON
surface over the same entities, `BallotBox`, `VoteTally` and `VotingPolicy` is not a second application and
gains nothing from a boundary it would only reach back across. The HTML controllers and the API resource
controllers now sit side by side under `src/Controller/` — `VotingController` for the pages, `PollResource`,
`ResultResource` and `VoteResource` for JSON, `DocsPage` for `/docs` — wired in one
`drupal_simple_voting.routing.yml` and served by one set of services.
*What this protects:* the write path stays single-sourced. The Form API ballot and the API vote both call
`BallotBox::cast()`, so the uniqueness rule (AD-19) and the audit trail (AD-53) hold identically for both,
and the hand-written API (AD-17) shares the domain rather than reimplementing it.

### AD-50 — The module's machine name is `drupal_simple_voting`
**Accepted.**
The module was renamed from `voting` to `drupal_simple_voting`. A Drupal machine name cannot contain a
hyphen, so the GitHub repository — which can — is `drupal-simple-voting`, and the module is the underscored
form of that name. The rename moved the directory to `web/modules/custom/drupal_simple_voting/`, the PHP
namespace to `Drupal\drupal_simple_voting`, every route to the `drupal_simple_voting.*` prefix (`polls`,
`settings`, `api_polls`, `api_poll`, `api_results`, `api_vote`, `docs`, `openapi`) and every service to
`drupal_simple_voting.*` (`ballot_box`, `tally`, `policy`, `audit`, `serializer`).
*What did not change:* the entity type IDs `voting_question`, `voting_option` and `voting_vote`. Those name
the domain, not the module. Renaming them would rename database tables and invalidate stored data for no gain.

### AD-51 — SDC is the presentation layer; the custom theme is deleted and the site runs stock Olivero
**Accepted.** *Supersedes AD-11.*
The `voting_theme` sub-theme is deleted. The site runs Drupal's stock Olivero, and Gin stays the admin theme
(AD-12). Everything the interface needs lives in the module as Single Directory Components — AD-14's method,
now the entire presentation layer: `components/poll-card`, `components/ballot`, `components/ballot-option` and
`components/vote-status`, each a `.component.yml` with its Twig and compiled CSS. The SCSS behind them — the
two token layers and the Bootstrap bridge of AD-34 — moved to the module's `scss/` and compiles to
`css/voting.css`.
*Why no custom theme:* a theme offered only a region map the module does not use and a settings surface it
does not need. A module that owns its own components and assets renders on any active theme, which is one
fewer thing for an evaluator to reproduce.

### AD-52 — The module attaches Bootstrap through its own library
**Accepted.** *Supersedes AD-09.*
Bootstrap is attached by the module, not by a theme. `drupal_simple_voting.libraries.yml` declares the
`drupal_simple_voting` library, which pulls the locally vendored `/libraries/bootstrap/dist` (AD-08, still
copied there by Composer) under `css.base` — never `css.component`, for the ordering reason in AD-35 — with
the module's own `css/voting.css` layered on top and the Bootstrap `bundle` JavaScript. A second library,
`swagger`, does the same for the `/docs` page.
*Consequence:* the Drupal 11 trap AD-09 was written to avoid no longer applies, because no theme sits in the
attachment path at all. The assets are attached by Drupal's ordinary library system wherever the module
renders, and served locally — no CDN, Bootstrap 5.3.8.

### AD-53 — Observability is one log channel and the `BallotAudit` service
**Accepted.** *Realises AD-20.*
The `logger.channel.drupal_simple_voting` channel and a `BallotAudit` service
(`Drupal\drupal_simple_voting\BallotAudit`) record every ballot outcome at the right PSR-3 level: a recorded
vote at **info**, a duplicate refused by the unique key at **warning**, a ballot refused because the question
is closed at **warning**, and a storage failure at **error**. Each line carries the question ID and the user
ID, and a cast vote also carries the ballot UUID and the chosen option.
*Why it cannot be bypassed:* `BallotAudit` is a constructor dependency of `BallotBox` and is called from
`BallotBox::cast()` — the one write path both the CMS ballot and the API vote go through (AD-49) — so the
trail is identical for both surfaces and cannot be sidestepped by entering through one instead of the other.

### AD-54 — Every `/api` response is JSON, errors included, set before routing
**Accepted.**
`ApiFormatSubscriber` marks the request format as `json` for any path under `/api/`, on
`KernelEvents::REQUEST` at priority 100 — before routing runs. Route-level `_format: 'json'` only reaches a
request that already matched a route, so an unknown `/api` path (404) or the wrong method (405) would
otherwise fall through to Drupal's themed HTML error page. Setting the format this early hands those cases to
core's JSON exception subscriber instead, so a client always receives a body it can parse: 403 on a closed
poll or a failed permission or CSRF check, 404 on a missing one, 405 on the wrong verb. The API routes still
carry `_format: 'json'` as well; the HTML routes — `polls`, the canonical `/poll/{voting_question}`,
`settings` and `docs` — set neither and render as normal pages.

---

## Open questions

*Closed since the last revision:* "Question identifier" by AD-40 (the entity UUID), "Result counting" by
AD-45 (one aggregate query, no denormalised counter), and "Which ballot layout ships" by AD-36 (Opinion
Stage's single-column list with the image beside the row).

- **Result freshness versus write amplification.** AD-45 settled how results are counted but not how they are
  cached. Invalidating a cache tag on every vote gives exact counts and drops the cached result on every
  vote — under load, effectively no cache on the read path. A short `max-age` with no tag caps recomputation
  regardless of vote rate, at the price of counts that lag by at most one refresh interval, which is already
  the granularity a reader perceives. This has to be decided deliberately, not discovered.
- **Inter, or the system stack.** AD-36's reference specifies Inter and AD-08 forbids external origins, so
  using it means vendoring the font files and accepting the weight. AD-27's system stack costs nothing and
  will not match the reference exactly.
- **Anonymous read access.** Should unauthenticated visitors be able to list questions and see results, with
  only voting requiring a login? Or is the whole system closed to unauthenticated access? This changes the
  access requirements on the read routes and decides whether the question page can be page-cached at all.
- **Live refresh interval.** How often the results fragment polls, balancing perceived liveness against
  server load under the concurrent-voting scenario the brief evaluates.
- **A `post-start` event in `.lando.yml`.** Wiring `lando install` to run after `lando start` would make the
  quick start a single command, at the cost of a slow and occasionally surprising first `lando start`.
- **Removing `.env` and `.env.example`.** Both are orphans of the removed Compose setup — Lando does not load
  them and nothing else reads them. Deleting them is the obvious move; the only argument for keeping
  `.env.example` is as documentation of the installer's inputs, which `.lando.yml` already carries.

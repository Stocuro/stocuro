# Stocuro

Stock management for teams tracking physical inventory across multiple
locations — cabinets, a mobile cart, a central storage/replenishment
point. Originally built for a hospital interventional radiology
department, but the location model is generic enough for any workshop,
lab, or team storeroom that scans stock in and out of a handful of fixed
spots.

The UI supports English and Dutch. Language is an **account setting**
(`users.taal`, migration 009), not a per-browser toggle: once logged in,
every screen renders in the language stored on that user's account, and
each user can change their own via the selector next to Sign out (which
updates the account, not just the current browser). The one exception is
the login screen itself — before authentication there's no account to
read a preference from, so `login.php` keeps its own small, separate,
browser-local language choice; that's a deliberate, unavoidable exception,
not an oversight. Everything runs on plain PHP and vanilla JavaScript —
no build step, no framework lock-in.

This deployment guide targets **One.com shared hosting**, all on the
**main `stocuro.com` domain** — no subdomain. The static marketing page
(`stocuro-landing/index.html`) and this app (`login.php`, `app.php`,
`api/`, ...) live side by side in the same web root, so the app is
reachable at the clean, expected URL `stocuro.com/login.php`.

**Why the flat folder structure, and why `app.php` instead of
`index.php`:** the app's dashboard page used to be named `index.php`.
That collides with the landing page's `index.html` — a web server can
only auto-load one "index" file per folder, and if both exist in the same
place, whichever one the server prefers (commonly `index.php`) would
silently hijack `stocuro.com/` instead of the landing page. Renaming it
to `app.php` removes the collision entirely: `index.html` (landing) is
now the only "index" file in the folder, so it always loads at `/`, and
the app lives at its own explicit URLs (`/login.php`, `/app.php`).

Separately, this also fixed a real, unrelated bug: an earlier draft of
this plan assumed a `public/` subfolder as the web root, with `config/`,
`includes/`, `api/`, `sql/`, and `migratie/` as protected siblings above
it. But the frontend calls `api/...` with a relative URL, which only
resolves correctly when `api/` sits *next to* the page that's calling it
— under that `public/`-as-web-root plan, `api/` (one level above
`public/`) would never have been reachable at all. That was never caught
because this had never been run against a live server. The fix: `api/`
now sits flat, as a direct sibling of `login.php`/`app.php`, matching
what the frontend actually expects. `config/`, `includes/`, `sql/`, and
`migratie/` are also flat siblings now, each protected by an `.htaccess`
file that blocks all direct web access (`Require all denied`).

> **Status:** live. v1.0.0 is the first official release (see "Status of
> this repository" and the top of the Features changelog below).
> Self-hosted distribution + licensing has been deployed and verified end
> to end on `stocuro.com` — WooCommerce checkout, the webhook,
> activation-code e-mail delivery, and a clean install run have all been
> tested against the real production setup and confirmed working; see
> "Licensing & self-hosted distribution" below for how updates are
> shipped.

## Features

- **v1.0.9 — index.php entry point for standalone installs
  (Docker/Synology/own server).** Visiting the bare domain or IP on a
  fresh standalone install used to hit a blank page or a 404, since the
  distributed ZIP had no `index.php`/`index.html` at all — you had to
  already know `/install.php` was the URL to type. The new `index.php`
  redirects a bare visit to `install.php` before the app is set up, or to
  `login.php` afterward (which itself already redirects to `app.php` if
  there's a valid session). **Not** included in the combined
  stocuro.com/One.com deployment (where the landing page's own
  `index.html` should load at `/`) — delete `index.php` there, see step 9
  of the installation instructions below. Docker/Synology instructions
  updated to reflect the simpler bare-URL flow.
- **v1.0.8 — Live progress during "building the database" step of the
  installer.** Previously, if `install.php` had more than a couple of
  pending migrations to run, the browser just showed a blank page until
  every migration finished — with 40+ migration files on a fresh install,
  that could be an uncomfortably long wait with no feedback. Now, if
  there's actually work to do, the installer shows a live-updating log
  (each line appears the moment that migration runs, no JavaScript
  required — plain `flush()`) with a "N migrations to go" counter, and a
  manual "Continue →" link once it's done. Deliberately not an
  auto-refresh: if a migration genuinely fails, auto-continuing would just
  retry the same broken step forever. If there's nothing pending — the
  normal case on every page load after the first — behavior is unchanged:
  silent and instant.
- **v1.0.7 — Bugfix: 3 migrations (032, 035, 040) never recorded as
  applied, causing migration 040 to fail permanently on any install with
  more than one setup request.** Every migration file registers itself
  in `schema_migrations` at the end of the file, once it has run — that
  final `INSERT` line was missing from three files. 032 and 035 use
  `CREATE TABLE IF NOT EXISTS` so re-running them silently did nothing
  harmful, but 040 does a plain `ALTER TABLE ... ADD COLUMN`, which is
  not safe to re-run: since `install.php`'s "run pending migrations"
  step re-executes on every page load until the first account is
  created, any install that spanned more than one request while
  migrations were pending (a page reload after fixing something,
  interrupted connection, ...) would re-attempt 040, fail with
  `Duplicate column name 'transactielog_id'`, and get permanently stuck
  — 040 would fail identically forever after that, since it can never
  reach the point where it records itself as done. All three files now
  end with the missing `INSERT INTO schema_migrations` line, matching
  every other migration. **If you're upgrading an existing self-hosted
  installation** (including a One.com one) that has already run
  migration 040 successfully at some point, its `schema_migrations`
  table is very likely missing rows for `032_eenmalige_bestelling_items.sql`,
  `035_gs1_correcties.sql`, and
  `040_eenmalige_bestelling_item_transactielog_koppeling.sql` even
  though the underlying tables/columns already exist — upload this
  version's files, then run this once against your database (via
  phpMyAdmin or similar) *before* opening `install.php`, so it doesn't
  try to re-apply them:
  ```sql
  INSERT IGNORE INTO schema_migrations (migratie) VALUES
    ('032_eenmalige_bestelling_items.sql'),
    ('035_gs1_correcties.sql'),
    ('040_eenmalige_bestelling_item_transactielog_koppeling.sql');
  ```
  (`INSERT IGNORE` makes this safe to run even if one of the three
  happens to already be recorded.)

- **v1.0.6 — Synology permissions fix confirmed and documented.**
  `chown` alone wasn't enough on a real Synology install (a DS1525+): if
  the extracted release's directories aren't marked executable,
  `www-data` still can't traverse into them even after taking ownership,
  and Apache fails the whole site with
  `pcfg_openfile: unable to check htaccess file, ensure ... '/var/www/html/'
  is executable`. `DOCKER.md`'s Synology section now documents the
  confirmed fix (`chown -R 33:33` **and** `chmod -R u+rwX,g+rX,o+rX`
  together), how to check what the container actually sees
  (`docker exec ... ls -ld`), and the Windows-ACL fallback if permissions
  look correct but access still fails. Documentation only.

- **v1.0.5 — Bugfix: `db` container falsely marked unhealthy on first
  Docker boot.** MariaDB's first-run initialization (creating the data
  directory, system tables, and the `stocuro` database/user) can take
  longer than the healthcheck's default timing window allowed for,
  especially on slower storage (NAS RAID, spinning disks) — Compose
  would then refuse to start the `app` container at all
  (`dependency failed to start: container ... db-1 is unhealthy`) even
  though MariaDB was still legitimately starting up, not actually
  broken. `docker-compose.yml`'s `db` healthcheck now has a 90-second
  `start_period` grace window before failed checks count against it,
  plus a troubleshooting entry in `DOCKER.md` for anyone who still hits
  it on unusually slow storage.

- **v1.0.4 — Synology NAS installation guide.** `DOCKER.md` now has a
  dedicated Synology section for anyone running Container Manager
  (DSM 7.2+): importing the included `docker-compose.yml` as a Project
  from the GUI, the one-time `chown` needed for `install.php` to be able
  to write `config/database.local.php` into a Synology shared folder,
  and using DSM's built-in reverse proxy + Let's Encrypt for a real
  domain name/HTTPS instead of a manually configured one. No app or
  Docker-setup changes — this is documentation only.

- **v1.0.3 — Docker as an alternative deployment option.** For anyone
  running Stocuro on their own Linux server instead of One.com shared
  hosting, a `Dockerfile` + `docker-compose.yml` are now included that
  run PHP/Apache and MariaDB in containers. This doesn't change the app
  itself or the install/licensing flow at all — same `install.php`
  wizard, same update path — it just changes how the PHP/database
  runtime gets started. See `DOCKER.md` for the full walkthrough. The
  One.com/FTP path in this README is unaffected and remains the primary,
  supported route for anyone on shared hosting.

- **v1.0.2 — deleting a linked transaction now also un-links the
  one-off order item.** Recent Transactions lets you edit or delete a
  transaction to correct a scanning mistake; the stock total was always
  correctly recalculated (it's derived live from the transaction log), but
  if the deleted transaction was the Usage/Return scan that had
  auto-matched a one-off order item (marking it "used"/"returned" and
  possibly closing the whole order), that item stayed marked as done even
  though the transaction behind it was gone. Deleting that transaction now
  puts the order item back to "received" and reopens the order if it had
  been auto-closed. (Editing a transaction never affects this link — the
  EAN/lot that the match is based on isn't editable.)

- **v1.0.1 — mandatory Terms of Service acceptance at install.** The
  first-account step of `install.php` now requires a checkbox confirming
  acceptance of the Terms of Service, Privacy Policy, and warranty
  disclaimer before the installation can be completed; enforced
  server-side as well, not just in the browser.

- **v1.0.0 — first official release.** This is the first version of
  Stocuro released for real, everyday use. It's the starting point for
  this changelog: earlier build/iteration history has been retired from
  this document, so from here on this section only tracks what changes
  *on top of* v1.0.0, not how the app was originally built.

  **What's in this release:**
  - Multi-location physical inventory (cabinets, mobile cart, central
    storage/replenishment point), scanned in and out via barcode/GS1:
    receive, use, transfer, return, stock-count, expiry write-off.
  - Consolidated dashboards: Stock, Refill, Orders, Reporting
    (usage/trends/write-offs), Tasks, Recent Transactions, Contacts,
    Products, and Team & News.
  - Role-based access: admin/regular/specialist accounts, screen-level
    permission groups, and a read-only guest/demo mode.
  - User accounts with editable name/e-mail/photo/password, and a
    per-account language setting (English/Dutch).
  - Manufacturer & contact directory, min/max stock advice, cross-location
    move suggestions, and audit-logged transaction history.
  - Self-hosted licensing: one free account per install, plus paid
    extra-user activation codes (WooCommerce-integrated), verified at
    login and periodically during use.
  - Browser-based install wizard (`install.php`) with a built-in
    migration runner for future updates.

  Version numbers from here on mark small, incremental updates on top of
  this release.

## Requirements on One.com

- **PHP 8.1+** (uses `match`, `str_contains`/`str_starts_with`, typed
  properties) — check this in the One.com control panel before
  deploying, older plans may default to an earlier version.
- **MariaDB 10.2+** (for the JSON column type in `artikelen.specs`). If
  not available, that column would need to move to a separate key-value
  table instead.
- PDO MySQL extension (standard on One.com PHP hosting).
- cURL extension (for fetching product photos from external links).

No Node/Composer/build tooling is required to run the app itself.

## Running on your own Linux server (Docker)

Everything below this point (and the "Installation" section further
down) targets One.com shared hosting specifically. If instead you have
your own Linux server — a VPS, a home server, a One.com plan with root
access — a `Dockerfile` and `docker-compose.yml` are included that run
PHP/Apache and MariaDB in containers, so you don't need to install PHP
or MySQL/MariaDB on the host yourself. This doesn't change anything
about the app, the install wizard, or the licensing model — it's purely
a different way to run the same code. See **`DOCKER.md`** for the full
walkthrough (first run, updating, backups).

## Licensing & self-hosted distribution

Stocuro v2.0+ ships as a fully self-hosted package: a customer downloads a
single zip, uploads it to their own server, and never needs an internet
connection to install or run it (relevant for patient-data environments).

### Installing or updating

1. Upload the zip contents to the web root and extract.
2. Visit `install.php` in the browser.
3. Step 1: enter database host/name/user/password. The wizard tests the
   connection before continuing.
4. Step 2: the wizard runs all pending migrations automatically, tracked in
   a `schema_migrations` table so nothing runs twice.
5. Step 3: create the first user (this account is always free — see below).
6. The wizard writes `config/database.local.php` and locks itself
   (`install.lock`). It cannot be run again as a fresh install.

For a later version, upload the new files over the old ones and visit
`install.php` again — the lock file makes it fall back to "run any new
migrations" only, gated behind an admin login. This is the same mechanism
for install and update, so there's only one code path to maintain.

No step contacts the internet. There is no license-activation server.

### License model

- The first user created during install is free forever: no expiry, no
  code, no restriction on features.
- Every additional user needs an activation code. One code = one extra
  user for one year, purchased via the Stocuro webshop (WooCommerce).
- A code is a cryptographically signed token (Ed25519), not a database
  flag. It's verified against a public key baked into the app — offline,
  every time, including at every login and periodically during active
  use. This means the check can't be bypassed by deleting a line of code
  or inserting a row directly in the database; the signature itself is
  re-checked, not just a stored date.
- A beheerder links a code to a user under Beheer → Gebruikers →
  "Code koppelen".
- When a code expires, that specific user is blocked (falls back to the
  existing read-only guest view) — never deleted. Entering a new valid
  code restores full access immediately.
- What this deliberately does NOT do: encrypt/obfuscate the PHP source,
  offer a self-service customer portal (a purchase just triggers an
  automatic email with the code), or technically prevent a customer's own
  developer from bypassing the check. The license terms are the backstop
  for that, not the code.

### Vendor-only tooling

The `vendor-tools/` directory (private signing key, code generator,
WooCommerce webhook receiver) is **not part of the customer package**. It
lives and runs only on the vendor's own infrastructure, outside this
repository.

## Installation

1. **Create the database** in the One.com hosting panel, plus a database
   user with access to it.

2. **Configure the connection**: copy
   `config/database.local.example.php` to `config/database.local.php` and
   fill in host/database/username/password. This file is not meant to be
   committed to version control.

3. **Upload the whole project as-is** into the web root of `stocuro.com`,
   *alongside* the landing page's `index.html`. `login.php`, `app.php`,
   `assets/`, `api/`, `config/`, `includes/`, `sql/`, and `migratie/`
   should all end up as siblings directly in that root, next to
   `index.html`. Don't nest them inside an extra folder.

4. **Run the migrations**:
   ```
   php sql/migrate.php
   ```
   This runs `sql/migrations/001_init.sql` through
   `013_eenmalige_bestelling_retourneren.sql` and tracks which ones have already run
   (table `schema_migrations`). New migrations later just need a new file
   in `sql/migrations/`. If you're loading migration files by hand via
   phpMyAdmin instead of `migrate.php` (as done for this demo), make sure
   you don't skip any — `008_taken_tellen_vervallen.sql` adds the
   transaction types and columns the Tasks screen depends on,
   `009_gebruiker_taal.sql` adds the `taal` column every login now expects
   to exist, `010_gebruiker_tracking.sql` adds
   `transactielog.aangemaakt_door`, the `audit_log` table, and
   `specialisten.type` that the Leaderboard screen and Master Data now
   depend on, `011_minmax_advies.sql` adds `orders.laatst_ontvangen_op`
   that the Min/Max Advies screen's lead-time calculation depends on, and
   `012_producenten.sql` adds the `producenten`/`producent_contacten`
   tables the Manufacturers screen depends on, and
   `013_eenmalige_bestelling_retourneren.sql` adds the `Retourneren`
   status the Open Orders screen's one-off order status dropdown depends
   on; without any of these, things will 500 with a "table/column doesn't
   exist" error the moment they're touched.

5. **Load data**, via phpMyAdmin (or a similar tool in the One.com panel),
   after step 4. Pick one — don't run more than one, to avoid mixing
   fictional and real products in the same view. There are two ways to
   run the fictional-data scripts, depending on what access you have to
   the server:

   **If you have SSH/command-line access** to the server, run the PHP
   scripts directly (see the doc comment at the top of each for all
   options):
   - `php migratie/genereer_testdata.php --ja` — twenty made-up products
     and a full simulated year of scans/orders/stocktakes/write-offs, so
     every dashboard (Overview, Leaderboard, Min/Max Advies, Trends, Open
     Orders, Write-offs, expiry screens, ...) already has something
     meaningful to show instead of being empty. Can take a couple of
     minutes depending on the connection to the database server. No demo
     user accounts are created — it logs actions under fictional name
     strings (consistent with how this app already snapshots "who did
     this" as plain text, not a foreign key — see `aangemaakt_door` in the
     Features section above), so just keep using your own account.
   - `php migratie/leegmaken.php --ja` — empties all data (run this first
     if the database already has data in it, to avoid EAN collisions;
     also useful later to clear everything out before a real go-live).
   - Both scripts also work from a browser (`?ja=1` instead of `--ja` —
     see their doc comments) if SSH is unavailable but you can still
     place PHP files on the server; delete the file afterward if you use
     that route, since the URL alone would otherwise be able to wipe or
     reseed the database.

   **If you only have a database admin tool (phpMyAdmin or similar), no
   SSH and no way to place/run PHP files on the server** — use the plain
   SQL equivalents instead, both importable exactly like
   `voorbeelddata.sql` below:
   - `migratie/leegmaken.sql` — paste into phpMyAdmin's SQL tab to empty
     all data (equivalent of `leegmaken.php --ja`).
   - `migratie/testdata_gegenereerd.sql` — a ready-made, pre-generated
     year of the same fictional test data, import via phpMyAdmin's
     Import tab (~1.1 MB). No PHP/Python needed to use it. **The four
     location names are baked into this file as plain text** ("Room A",
     "Room B", "Cart", "Storage" — the migration 005 defaults); if you'd
     already renamed the four fixed locations in Admin → Locations before
     importing this, the generated stock would land under names that
     don't match anything and the stock-level screens would show
     nothing. Check the top of the file — it lists which names it used —
     and either temporarily rename your locations back to match, or
     regenerate the file with your own names (next bullet). (An earlier
     version of this file resolved the names dynamically via SQL session
     variables instead of baking them in as text, which seemed more
     flexible — except phpMyAdmin's Import tab can split a file this size
     into several requests, and those variables don't survive across
     that split, so every row silently got a blank location. Baked-in
     text avoids that failure mode entirely.)
   - To regenerate with your own location names, a different length of
     history, or to avoid reusing the same fixed EANs/random seed —
     `python3 migratie/genereer_testdata.py --locaties "Interventiekamer,Hybride OK,Kar,Berging" --dagen 180 --seed 99`
     (order is always ik,hybok,kar,berging; omit `--locaties` to keep the
     defaults) — then import the resulting file the same way. No server
     access needed to run this, it just writes a `.sql` file next to it.

   **Fictional demo data (products only, no history)**:
   `migratie/voorbeelddata.sql` — ten made-up example products, safe to
   show people without exposing anyone's real inventory. A lighter
   alternative to the full-year generators above if you just want a few
   products to click around, not richly populated dashboards.

   **Real data**: `migratie/artikelen_import.sql` — already generated
   from your Excel export (170 products), ready to run as-is. If you
   already loaded demo data first and want to switch, clear it out first
   — `migratie/leegmaken.sql` (or `leegmaken.php --ja`), or for just the
   ten `voorbeelddata.sql` products,
   `DELETE FROM artikelen WHERE ean LIKE '00000000000%';` (only matches
   the fake demo EANs, not real ones) — before running this file. To
   regenerate this file later from an updated Excel export:
   ```
   pip install openpyxl --break-system-packages
   python3 migratie/export_from_excel.py "your-products.xlsx" migratie/artikelen_import.sql
   ```

6. **Create the first admin account** (there is deliberately no
   self-registration screen — see the migration plan for the reasoning).
   Generate a password hash locally:
   ```
   php -r "echo password_hash('choose-a-password', PASSWORD_DEFAULT), PHP_EOL;"
   ```
   then insert it directly via phpMyAdmin:
   ```sql
   INSERT INTO users (naam, email, wachtwoord_hash, rol, is_beheerder)
   VALUES ('Harm', 'you@example.com', '<hash-from-previous-step>', 'specialist', 1);
   ```
   `rol` must be one of `specialist`/`laborant`/`bekijker` (migration
   019) — `is_beheerder` is the separate flag that actually makes this an
   admin account, and is what unlocks the "Users" screen and every other
   Admin-only screen. Further users can be added from the "Users" screen
   in the app itself (visible to any account with `is_beheerder = 1`).
   Note that visitors don't actually need an account to browse `app.php`
   at all — see the guest/demo mode bullet under Features above — so only
   create accounts for people who should be able to save changes.

7. **Set up the locations** (Admin → Locations) to give the four fixed
   slots demo-friendly names — e.g. "Room A" / "Room B" / "Cart" /
   "Storage", then add specialists/lab technicians as user accounts under
   Admin → Users and procedure types under Admin → Master Data, so the
   scan forms have something to pick from.

8. **Verify `config/`, `includes/`, `sql/`, and `migratie/` are actually
   blocked**: visit e.g. `https://stocuro.com/config/database.local.php`
   in a browser — it should give a 403 Forbidden (from the `.htaccess`
   files already included in each of those folders), not a blank page or
   a download. If it doesn't 403, stop and fix that before going further
   — it means the credentials file is exposed.

9. **Delete `index.php`** from the uploaded app files before going live,
   if you haven't already. This file exists in the distributed ZIP purely
   for standalone installs (Docker/Synology/your own server, see below)
   that have nothing else at their web root — there it auto-redirects a
   bare domain/IP visit to `install.php` or `login.php` as appropriate. On
   the combined `stocuro.com` setup it has no job to do (the landing
   page's own `index.html` is what should load at `/`) and, worse, having
   both an `index.html` and an `index.php` in the same folder recreates
   exactly the ambiguous "which index file does the server pick" problem
   that `app.php` (instead of `index.php`) was originally introduced to
   avoid — so it must not be present here.

10. **Check `stocuro.com/` loads the landing page**, not the app. If it
    loads something else, check that there's no leftover `index.php` sitting
    in the same folder as `index.html` (see previous step).

11. **Enable HTTPS** (usually on by default at One.com, verify).

12. **Do an initial stock count for the demo data**: the database starts
    with an empty transaction log, so every location shows zero stock
    until you scan each demo item in via "Receive Stock" for a starting
    quantity. Do this once so the dashboards have something to show.

The landing page's "Try the live demo" buttons already point at
`/login.php` (relative, same domain) — no further changes needed there
once both are uploaded to the same web root.

## Project structure

```
index.html   landing page (marketing/explainer — from stocuro-landing/, not part of this app;
             that folder also has dashboards/ and forms/ subfolders — one SEO explainer
             page per dashboard and per form — plus assets/site.css, assets/site.js,
             robots.txt, and sitemap.xml, all of which upload into this same web root
             alongside index.html. See stocuro-landing/SITE-MAINTENANCE.md for how to keep
             dashboards/ and forms/ in sync whenever a screen changes.)
login.php    login page
app.php      app entry point (requires login) — named app.php, not index.php, to avoid
             colliding with the landing page's index.html as "the" homepage
index.php    router for STANDALONE installs only (Docker/Synology/own server) — sends a
             bare domain/IP visit to install.php or login.php as appropriate. Delete this
             file on the combined stocuro.com/One.com setup (step 9 above) — it would
             otherwise sit right next to index.html and reintroduce the exact naming
             collision app.php was created to avoid.
assets/      css/js, served directly
api/         thin JSON controllers, one per endpoint — called by assets/js/app.js
config/      configuration (database, constants) — blocked from the web via .htaccess
includes/    service layer (business logic, one file per concern) — blocked via .htaccess
migratie/    one-off import script + fictional demo data — blocked via .htaccess
sql/         schema migrations + migration runner — blocked via .htaccess
uploads/     user-uploaded profile photos (migration 015) — web-readable (photos need to be
             viewable), but PHP execution is disabled there via its own .htaccess. Created
             automatically on first upload if missing; needs to be writable by the web
             server's user.
```

Everything above lives flat, as siblings, in the same web root of
`stocuro.com` — see "Why the flat folder structure..." above for the
reasoning.

## Known limitations

These are deliberate scope boundaries, not oversights — flagged here so
they're not a surprise:

- **Not yet run against a live server.** Verified by static syntax
  checking (`node --check` on the JS, manual review of the PHP) but not
  by actually executing it against PHP/MariaDB. One real bug was already
  found this way (see below) — treat step 9 of the installation
  instructions (verifying the `.htaccess` blocks work) as mandatory, not
  optional, and generally expect to find and fix small things on first
  real deployment.
- **`.htaccess`-based protection assumes Apache** (standard on One.com).
  If this ever moves to an Nginx host, the `.htaccess` files in `config/`,
  `includes/`, `sql/`, and `migratie/` do nothing — Nginx needs equivalent
  `location` blocks in the server config instead.
- **Browser caching of `assets/js/app.js` and `assets/css/style.css`**:
  `app.php`/`login.php` now append `?v=<filemtime>` to both, so the
  browser automatically fetches a fresh copy whenever either file changes
  on the server (its own last-modified time becomes the version string —
  no manual bumping needed). Before this was added, updating `app.js` on
  the server did not reliably reach browsers that had already cached the
  old version, even after a normal refresh.
- **The actual cause of "every dashboard button stays on 'Loading...'
  forever"** (found during first real testing, not before) was a real bug
  in the `google.script.run`-compatibility shim in `assets/js/app.js`
  (now `StocuroRun`): `withSuccessHandler()`/`withFailureHandler()`
  returned the shim's plain, un-proxied internal object instead of the
  actual `Proxy` wrapper. That meant the chained call
  `.withSuccessHandler(cb).getXyz()` bypassed the `Proxy`'s trap entirely
  and hit a plain object where `getXyz` doesn't exist — an immediate,
  synchronous `TypeError` thrown before any network request was even
  made. Since that error fired inside the button's click handler, right
  after the screen had already been set to "Loading...", the browser
  aborted the handler there and the screen was stuck — this affected
  every single dashboard, not a specific one. Renaming the shim to
  `StocuroRun` (originally done to rule out a suspected Google Translate
  conflict) and adding cache-busting were both independently reasonable
  fixes, but neither was the actual root cause — this Proxy bug was. Now
  fixed: `withSuccessHandler`/`withFailureHandler` correctly return the
  `Proxy` instance.
- **Folder layout changed twice from the original migration plan**:
  first from a `public/`-as-web-root design (which relied on pointing a
  host's document root at an arbitrary subfolder — not how One.com
  actually works, and which also silently broke `api/`), then from an
  `index.php`/subdomain design to the current flat, single-domain layout
  with `app.php` instead of `index.php` (see "Why the flat folder
  structure..." above). Neither of the earlier layouts had been deployed
  for real, so neither issue was caught until working through the actual
  hosting constraints.
- **Status labels are internal codes**, not yet translated even when the
  UI language is set to Dutch (e.g. badge text like `LEEG`/`VERLOPEN`).
  These codes are shared between the PHP backend and the JS frontend for
  styling/matching logic; translating them needs a coordinated change on
  both sides. Translation coverage was previously limited to the header
  and main menu only (every individual dashboard/form/admin screen had
  hardcoded English regardless of the account's language setting) — this
  has since been fixed: all ~38 render functions now go through
  `t()`/`tf()`, so the account-level language preference actually applies
  everywhere, not just the menu.
- **Product categories and their spec fields** (Stent, Guidewire, Sheath,
  etc.) are now admin-editable (migration 028, see Features above), but
  only the seven original categories have translated (EN/NL) labels —
  any category an admin adds shows under its literal Dutch name in both
  languages until a translation is added in code.
- **The Code128 barcode generator for the internal logistics code is a
  small, self-contained implementation** (no external library — this app
  has no build step or CDN dependency), using the standard ISO/IEC 15417
  width-pattern table. It's internally consistent (every symbol's widths
  add up correctly) but has not been verified by scanning a physical
  printout with real hardware. Test-scan a printed label with your actual
  barcode scanner before relying on this for daily use. The Storage
  Restock List (📦) now prints this same internal-code barcode instead of
  the GS1 EAN, so a product without a logistics code set simply shows no
  barcode there — set one (Product edit screen) if you want a scannable
  shelf label for it; the GS1 EAN is still shown/scanned everywhere else
  in the app (Receive Stock, Transfer, Scan Usage, etc.) unaffected by
  this.
- **Four fixed location slots with a behavioral role** (two general, one
  mobile, one central/replenishment) cannot be added to or removed —
  their count and roles are hardcoded. What you *can* now do is add
  unlimited additional locations alongside them (see Features above);
  those always behave like a general location and cannot take on the
  mobile-cart or central-replenishment behavior.
- **"Add Product" only sets min/max for the four fixed slots at creation
  time.** Min/max for additional locations must be set afterward via the
  product's Edit screen (Admin → Products → Edit), not at creation. This
  was a deliberate scope cut, not an oversight.
- **Recent Transactions edit/delete uses a hard delete/update**, not a
  soft-delete flag, so a corrected or removed row disappears from stock
  calculations immediately and cleanly. The audit table preserves what
  was there before, but there's no built-in "undo the undo" — restoring a
  wrongly-deleted transaction means re-entering it as a new scan.
- **Tasks are derived, not a real to-do list.** There's no way to add a
  free-text task unrelated to stock counts or expiry — that was a
  deliberate scope decision, not an oversight (see the sparring
  conversation this was designed from). If a different kind of recurring
  task comes up later, this would need a real tasks table instead of
  computed state.
- **A stock count assumes you scanned everything physically present at
  that location.** Any product the system expected but that wasn't in the
  scan gets treated as "0 present" and booked down accordingly — there's
  no way to do a partial count of just a few products through this
  screen. For a partial correction, use Recent Transactions or a normal
  Scan Usage/Receive entry instead.
- **The 3-month stock-count interval (`TELLING_INTERVAL_MAANDEN`) is a
  single global setting**, not configurable per location from the UI —
  changing it means editing `config/constants.php`.
- **No "edit existing user" screen.** Admin → Users can list users and
  create new ones (including setting their starting language), but
  cannot change an existing user's role, language, or password — that
  already had no UI before language became an account setting, so this
  isn't a new gap. A user can always change their own language via the
  selector next to Sign out; everything else still needs direct database
  access.
- **No per-specialist visibility restriction** on usage reports yet —
  any logged-in user can currently see usage broken down by every
  specialist, not just their own. Documented as a future option
  (matching the free-text `specialist` field against `users.naam`).
- **Admin → Groups' landing-screen dropdown still lists the old,
  pre-migration-031 screen names** (e.g. `overzicht`, `bestellers`,
  `taken-beheerder`), not the new consolidated page names — it's built
  from the `SCHERMEN` registry (`includes/schermen.php`), which wasn't
  touched this round since the new pages deliberately reuse existing
  screen keys rather than registering new ones (see Features above).
  Picking an old value there still works correctly (`ROUTE_DISPATCH`
  redirects it to the right new page), it just looks stale in the
  dropdown itself. Cleaning this up would mean adding the 4 new page
  names as their own registry entries and updating the dropdown to
  prefer them.
- **License:** not yet finalized/added to this repository. Do not treat
  the current absence of a `LICENSE` file as permission to redistribute;
  check back for an update before relying on this for anything beyond
  personal evaluation.

## Status of this repository

Live and definitive as of v1.0.0, the first official release (see
Features above). `stocuro.com` runs the demo/marketing
site plus the real login/app; `stocuro.com/shop/` is a WooCommerce store
selling extra-user activation codes, wired to this codebase's licensing
system via `vendor-tools/woocommerce_webhook.php` (kept out of the
customer-facing ZIP). Not published as open-source/on a public code host
— distribution to a new customer is still a manually handed-over ZIP
(`stocuro-v1.0.0-self-hosted.zip`) built from this repository, not a public
download link.

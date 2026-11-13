# Running Stocuro with Docker

This is an **alternative** to the One.com/FTP deployment described in
`README.md`, for anyone who has their own Linux server (a VPS, a home
server, a One.com VPS plan with root access, ...) instead of shared PHP
hosting. Everything else about Stocuro — the install wizard, the
licensing model, the update process — works exactly the same either way;
Docker only changes *how PHP and MariaDB get run*, not the app itself.

## Requirements

- A Linux server with **Docker** and the **Docker Compose plugin**
  installed (`docker compose version` should print something, not an
  error). Any reasonably recent Docker install already includes this.
- Nothing else — no separately installed PHP, no separately installed
  MySQL/MariaDB. Both run inside containers.

## First-time setup

1. **Unzip the Stocuro release** on the server, anywhere you like, e.g.
   `/opt/stocuro/`. This directory needs to contain `Dockerfile`,
   `docker-compose.yml`, and `.env.example` alongside the usual
   `app.php`, `install.php`, `config/`, `includes/`, etc.

2. **Create your `.env` file**:
   ```
   cp .env.example .env
   ```
   Open `.env` and fill in `STOCURO_DB_PASSWORD` and
   `STOCURO_DB_ROOT_PASSWORD` with your own strong passwords. Change
   `STOCURO_PORT` too if 8080 is already used by something else on that
   server. `docker compose` refuses to start until the two password
   fields are filled in — that's intentional, so nobody accidentally
   ships this with an empty database password.

3. **Build and start both containers**:
   ```
   docker compose up -d --build
   ```
   First run pulls the PHP and MariaDB base images and builds the app
   image — give it a minute. `docker compose logs -f` shows progress if
   you want to watch.

4. **Open the app**: `http://your-server:8080/` (or whatever port you
   set) — `index.php` automatically sends a fresh install straight to
   the install wizard, so you don't need to type `/install.php`
   yourself (though that URL still works directly too, and once
   installed the bare URL redirects to the login page instead). This is
   the exact same three-step wizard described in `README.md` under
   "Installing or updating":
   - **Step 1 — database connection.** Use:
     - Host: `db` (the Compose service name — containers resolve each
       other by service name, not `localhost`)
     - Database / Username / Password: whatever you put in `.env` for
       `STOCURO_DB_NAME` / `STOCURO_DB_USER` / `STOCURO_DB_PASSWORD`
   - **Step 2 — migrations** run automatically.
   - **Step 3 — create the first (free) account.**

   The wizard writes `config/database.local.php` and `install.lock` back
   onto the server's disk (not just inside the container — see "Why
   bind-mount instead of baking the code into the image" below), so they
   survive restarts and rebuilds exactly like everything else in that
   folder.

5. **Delete or rename `install.php`** once done, same recommendation as
   the regular install — the wizard locks itself after the first account
   is created, but removing the file entirely is one less thing exposed.

That's it — Stocuro is now reachable at `http://your-server:8080/login.php`.
Put a reverse proxy (Caddy, nginx, Traefik) in front of it for a real
domain name and HTTPS; that's standard for any self-hosted app and not
specific to Stocuro, so it isn't covered here.

## Updating to a new version

Identical in spirit to the FTP update path:

1. Unzip the new release **over the existing directory** (or copy the
   new files in some other way) — this overwrites `app.php`, `includes/`,
   etc., but leaves `config/database.local.php`, `config/instance.local.php`,
   `install.lock`, and `uploads/` alone, since those aren't part of the
   release zip.
2. If the `Dockerfile` itself changed (rare — only if a new PHP
   extension is needed), rebuild: `docker compose up -d --build`.
   Otherwise a plain `docker compose up -d` (or nothing at all — PHP is
   interpreted, changes to `.php` files take effect on the next request)
   is enough.
3. Visit `install.php` again while logged in as a beheerder/admin — it
   detects `install.lock` and offers a single "Run new migrations"
   button instead of the full wizard.

## Backups

Two things need backing up: the database, and the `uploads/` +
`config/` files (which live directly on the host, since the whole
project directory is bind-mounted).

**Database:**
```
docker compose exec db mariadb-dump -u root -p"$STOCURO_DB_ROOT_PASSWORD" stocuro > backup.sql
```
(replace `stocuro` if you changed `STOCURO_DB_NAME`). Restore with:
```
docker compose exec -T db mariadb -u root -p"$STOCURO_DB_ROOT_PASSWORD" stocuro < backup.sql
```

**Files:** since the project directory is a normal folder on the host
(not hidden inside a container), a regular file-level backup tool
(rsync, tar, your hosting provider's snapshot feature, ...) covers
`config/database.local.php`, `config/instance.local.php`, `install.lock`,
and `uploads/` the same way it would for any other server directory —
nothing container-specific needed there.

## Stopping / restarting

```
docker compose stop        # stop both containers, keep everything on disk
docker compose up -d       # start them again
docker compose down        # stop and remove the containers (data in the
                            # named volume + the bind-mounted project
                            # directory are both untouched)
```

## Running on a Synology NAS

Synology's **Container Manager** (the successor to the old Docker
package, standard on DSM 7.2+) runs plain Docker and Docker Compose
underneath, so everything above still applies — this section only
covers the handful of steps that go through Synology's GUI instead of a
terminal, plus one NAS-specific permissions gotcha. Requires an x86_64
model with Container Manager available in Package Center (the DS1525+
and similar Plus-series models qualify).

1. **Create a shared folder** for Stocuro, e.g. via *Control Panel →
   Shared Folder → Create* (name it `stocuro`, or anything you like).
   Unzip the release into it — either over SMB from your computer, or
   via *File Station*'s built-in extract.

2. **Fix folder ownership AND permissions before first run.** The
   `php:8.2-apache` image runs as the `www-data` user internally (uid
   33), and it needs to be able to both read every file (`.htaccess`
   included) and traverse ("execute") every directory under the shared
   folder you just created -- and it needs to be able to write
   `config/database.local.php`, `config/instance.local.php`,
   `install.lock`, and `uploads/`. DSM's own permission system doesn't
   map cleanly onto that uid by default, so the reliable fix is two SSH
   commands together (enable SSH under *Control Panel → Terminal &
   SNMP* first if it's off):
   ```
   sudo chown -R 33:33 /volume1/stocuro
   sudo chmod -R u+rwX,g+rX,o+rX /volume1/stocuro
   ```
   (adjust the path/volume number to match where you created the shared
   folder). **Both commands matter, not just the first one:** `chown`
   alone fixes *who* owns the files but not *what* they're allowed to
   do -- if the folder was extracted with directories that aren't
   marked executable, `www-data` still can't traverse into them even
   though it now owns them, and Apache fails with exactly this error
   for the whole site (confirmed against a live DS1525+ install):
   ```
   [core:crit] (13)Permission denied: .../.htaccess pcfg_openfile:
   unable to check htaccess file, ensure it is readable and that
   '/var/www/html/' is executable
   ```
   The `chmod` above is the fix: `u+rwX` / `g+rX` / `o+rX` add
   read/write plus "execute, but only on things that are already a
   directory or already have an execute bit" (capital `X`) -- exactly
   what a web root needs, without making every `.php` file spuriously
   "executable" the way a blanket `chmod -R 755` would. Skipping either
   command is the single most common cause of `install.php`/the whole
   site failing with a permissions error on a Synology.

   To confirm what's actually going on before/after, check what the
   *container* sees (not what DSM's File Station shows, which can differ
   if Windows ACL is layered on top -- see below):
   ```
   sudo docker exec stocuro-app-1 ls -ld /var/www/html
   sudo docker exec stocuro-app-1 ls -la /var/www/html/config/
   ```
   If ownership/mode there already look correct (`www-data www-data`,
   `x` on directories) and it's *still* failing, the shared folder
   almost certainly has Windows ACL enabled, which can override plain
   Unix permissions regardless of `chown`/`chmod`. Fix via *File
   Station → right-click the folder → Properties → Permissions tab* --
   either turn Windows ACL off for that folder, or explicitly grant
   "Everyone" read + execute and make sure "apply to this folder,
   subfolders and files" is checked.

3. **Set up `.env`.** Copy `.env.example` to `.env` inside that folder
   (File Station can duplicate/rename, or do it over SSH) and fill in
   the two password fields, same as the generic instructions above.

4. **Open Container Manager → Project → Create.** Point "Path" at the
   shared folder from step 1 — Container Manager detects the
   `docker-compose.yml` already there and offers to use it directly, no
   need to paste or re-upload it. Build and start the project from
   there; the Project view shows both containers (`app`, `db`) and their
   logs, same information `docker compose logs` would show on the
   command line.

5. **Pick a port.** If `STOCURO_PORT` (default 8080) is already used by
   another package on your NAS, change it in `.env` before starting the
   project — Container Manager will otherwise just fail to bind that
   port.

6. **Open `http://<nas-ip>:<port>/`** — it redirects straight to the
   install wizard on a fresh install (or to the login page once
   installed). Continue exactly as described above (host `db`,
   credentials from `.env`).

**Optional — a real domain name and HTTPS**: unlike a bare Linux server,
DSM already has a reverse proxy and Let's Encrypt integration built in.
Under *Control Panel → Login Portal → Advanced → Reverse Proxy*, create
a rule forwarding your chosen hostname (HTTPS, port 443) to
`localhost:<STOCURO_PORT>` (HTTP) — then request/attach a certificate
for that hostname under *Control Panel → Security → Certificate*. This
replaces the generic "put a reverse proxy in front of it" note in the
main setup section above with something Synology already ships.

## Running on Ubuntu Desktop

Everything in the sections above still applies on Ubuntu Desktop — this
section only covers what's actually different from a VPS/NAS: Ubuntu
Desktop doesn't come with Docker preinstalled, running `docker` commands
without `sudo` needs a one-time user/group change, and — the part that
trips people up most — the bind-mounted project folder needs its
ownership and permissions set correctly for the container to be able to
read and write it at all. Useful if you're trying Stocuro on your own
desktop or laptop rather than a dedicated server (e.g. a small, informal
pilot with one or two colleagues on the same network).

1. **Install Docker Engine + the Compose plugin from Docker's own apt
   repository** — not Ubuntu's own `docker.io` package (older, and
   sometimes missing the Compose v2 plugin) and not the Docker snap
   (snap's sandboxing can block the container from seeing your home
   directory at all, which shows up as a confusing "no such file" for
   files that are clearly there):
   ```
   sudo apt-get update
   sudo apt-get install -y ca-certificates curl
   sudo install -m 0755 -d /etc/apt/keyrings
   sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
   sudo chmod a+r /etc/apt/keyrings/docker.asc
   echo \
     "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu \
     $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
   sudo apt-get update
   sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
   ```
   Confirm with `docker compose version` — should print a version, not
   an error.

2. **User and group rights, part 1 — run `docker` without typing `sudo`
   every time.** Fresh after install, Docker's daemon socket is owned by
   `root:docker`, so only root (or `sudo`) can talk to it. Add your own
   account to that group instead of prefixing every command with `sudo`:
   ```
   sudo usermod -aG docker $USER
   ```
   This only takes effect in a **new** login session — either log all
   the way out and back in, or for just your current terminal window,
   run `newgrp docker`. Confirm with `docker run hello-world` (no
   `sudo`) — it should pull a tiny image and print a success message.
   One security note worth knowing: anyone in the `docker` group can
   trivially get root on the host (a container can mount `/` from the
   host and write to it), so this is fine for your own single-user
   pilot machine but isn't something to hand out on a shared one.

3. **Unzip the Stocuro release** somewhere in your home directory, e.g.
   `~/stocuro/` — same file layout as the generic instructions above
   (`Dockerfile`, `docker-compose.yml`, `.env.example` alongside
   `app.php`, `install.php`, `config/`, `includes/`, ...).

4. **User and group rights, part 2 — fix ownership of the project
   folder itself, before first run.** This is the step that's easy to
   skip and the most common cause of a blank page or permission errors
   right after `docker compose up`. Unlike Docker Desktop on macOS/
   Windows (which transparently remaps file ownership between the
   container and the host), Docker Engine on native Linux runs
   containers as **real host user IDs** against a bind-mounted folder —
   there's no translation layer. The `php:8.2-apache` image's Apache
   process runs as `www-data`, **uid 33, gid 33**, inside the container.
   Against your bind-mounted `~/stocuro/`, that means literally uid 33
   on your Ubuntu filesystem — almost certainly not a real, named
   account on a normal Ubuntu Desktop install, but the numeric ID is
   what Linux actually checks, not a name. It needs to read every file
   and traverse every directory, **and** write
   `config/database.local.php`, `config/instance.local.php`,
   `install.lock`, and `uploads/` (the install wizard and photo
   uploads). Fix both ownership and permissions together (same two
   commands already used for Synology above, since it's the exact same
   underlying uid/gid):
   ```
   sudo chown -R 33:33 ~/stocuro
   sudo chmod -R u+rwX,g+rX,o+rX ~/stocuro
   ```
   (adjust the path if you unzipped somewhere other than `~/stocuro`).
   `chmod`'s capital `X` adds execute/traverse only to things that are
   already a directory or already executable — exactly what's needed
   for a web root, without making every `.php` file spuriously
   "executable" the way a blanket `chmod -R 755` would.

   **Side effect worth knowing**: after this, your own login user can no
   longer edit files in that folder directly (in a file manager or
   editor) without `sudo`, since they're now owned by a uid that doesn't
   match your account. That's expected and fine here — day-to-day use of
   the app goes through the container (which runs as uid 33), not your
   desktop session. The only time you'll touch the folder as yourself
   again is unzipping a **new release** on top of it later; do that as
   root (or `sudo`), or temporarily `sudo chown -R $USER:$USER ~/stocuro`
   before unzipping and re-run both commands above afterward.

5. **Set up `.env`** — identical to the generic instructions: `cp
   .env.example .env`, then fill in `STOCURO_DB_PASSWORD` and
   `STOCURO_DB_ROOT_PASSWORD`.

6. **Build and start**: `docker compose up -d --build` — no `sudo`
   needed now that your user is in the `docker` group.

7. **Open the app.** On the same machine: `http://localhost:8080/`. For
   a colleague on the same network to reach it from their own device,
   use this machine's LAN IP instead of `localhost` (`hostname -I` shows
   it) — and if Ubuntu's firewall is active, open the port first:
   ```
   sudo ufw status
   sudo ufw allow 8080/tcp
   ```
   (skip the `allow` line if `ufw status` prints `inactive` — nothing is
   blocking it). From there the install wizard works exactly as
   described in the main setup section above (host `db`, credentials
   from `.env`).

8. **Autostart after a reboot.** The apt install already enables
   Docker's systemd service, which starts at boot independently of
   whether anyone logs into the desktop session — so containers with
   `restart: unless-stopped` (already set in `docker-compose.yml`) come
   back up on their own after a restart, with no extra setup. Suspending
   the machine (closing a laptop lid) isn't a reboot at all, so nothing
   special happens there either — everything just resumes.

**Troubleshooting specific to this setup:**

- **Blank page, 500 error, or `install.php` can't write its config
  right after first `docker compose up`.** Almost always step 4
  (ownership) was skipped, or was done *before* unzipping a newer
  release's files on top (which resets ownership on the new files to
  your own user again). Re-run both commands from step 4.
- **Installed Docker via `sudo snap install docker` instead of step 1**:
  snap's sandboxing restricts which host paths the snap can see, which
  can make the bind mount fail or come up empty even though the path is
  clearly there in a terminal. Remove it (`sudo snap remove docker`) and
  install via step 1 instead.
- **`docker compose` (no hyphen) says "command not found", but
  `docker-compose` (with a hyphen) works**: that's the old, standalone
  Compose v1 binary instead of the v2 plugin from step 1. Either keep
  using the hyphenated form, or install `docker-compose-plugin` to get
  the same `docker compose` command used throughout this guide.

## Why bind-mount instead of baking the code into the image

The more common Docker pattern is `COPY . /var/www/html` in the
Dockerfile, treating the image as the single source of truth and the
database as the only persistent state. Stocuro doesn't fit that cleanly:
`install.php` writes `config/database.local.php`,
`config/instance.local.php`, and `install.lock` directly next to the
application code the first time it runs, and `uploads/` (user/contact
profile photos) also lives on disk, not in the database. Baking the code
into the image would mean those three items need their own separate
volumes, and every update would require rebuilding the image just to get
new `.php` files in.

Bind-mounting the whole project directory (`./:/var/www/html` in
`docker-compose.yml`) sidesteps all of that: the container just points
Apache/PHP at the same folder you unzipped the release into, so
everything the app writes lands on the host automatically, and updating
means replacing files in that folder — identical to the One.com/FTP
flow, just running through a container instead of shared hosting. This
is also an officially documented pattern for the `php:*-apache` image
(`docker run -v "$PWD":/var/www/html php:8.2-apache`), not a workaround.

## Troubleshooting

**"dependency failed to start: container ... db-1 is unhealthy" on the
very first `docker compose up`.** This is MariaDB still doing its
first-boot initialization (creating the data directory, system tables,
and the `stocuro` database/user) when the healthcheck's timing window
runs out and Compose gives up waiting -- more likely on a NAS or other
storage that's slower than a typical VPS SSD. It's not a real failure,
just an overly impatient default. `docker-compose.yml` already gives the
database a 90-second head start before failed healthchecks start
counting (`start_period`); if it still happens on especially slow
storage:
1. Check what MariaDB itself is actually doing:
   `docker compose logs db` (or the container's log tab in Container
   Manager on Synology) -- if it's still printing initialization output
   and not an actual error, it just needs more time.
2. Run `docker compose up -d` again once `docker compose logs db` shows
   `ready for connections` -- the `app` container only failed to *start*,
   nothing about the database itself is broken, and the data already
   written to the `stocuro_db_data` volume is untouched.
3. If it keeps happening, increase `start_period` (and/or `retries`)
   under the `db` service's `healthcheck` in `docker-compose.yml` a bit
   further and retry.

## What's *not* covered here

- Reverse proxy / HTTPS setup (use whatever you already run for other
  services on this server).
- Docker Swarm / Kubernetes — this is a single-server `docker compose`
  setup, matching Stocuro's single-tenant, self-hosted design elsewhere
  (no multi-server scaling story exists for this app, on Docker or off
  it).
- Automated backups/monitoring — see "Backups" above for the manual
  commands; wire them into your own cron/backup tooling as you would for
  any other self-hosted service.

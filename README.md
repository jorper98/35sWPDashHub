# 35sDashHub — WordPress Sites Dashboard Hub Manager by 35sites.com

Self-hosted **vanilla PHP** dashboard to monitor and update multiple **WordPress** sites using **Application Passwords** and the **REST API**. Manage your entire WordPress fleet from a single interface — sync site status, deploy plugin/theme/core updates, fleet-manage plugins, capture update snapshots, and send owner email reports.

**Latest:** v0.0.21 — fixes stale plugin active/inactive status when activating an already-active plugin. See [CHANGELOG.md](CHANGELOG.md) for full release history.

**Changelog:** [CHANGELOG.md](CHANGELOG.md) (project-wide). Companion-only WordPress notes: [plugin/s35-wp-hub/readme.txt](plugin/s35-wp-hub/readme.txt) → *Changelog*.

## Requirements

- PHP **8.1+** with **curl**, **openssl**, and the **PDO SQLite** driver (`extension=pdo_sqlite` in `php.ini` on Windows/Linux; restart PHP/your server after enabling)
- Web server pointing document root at `public/` (or equivalent)
- **HTTPS** for the dashboard (Application Passwords use HTTP Basic; TLS is mandatory in production)
- WordPress **6.0+** on managed sites, with Application Passwords enabled for an admin user

## Quick start

1. Clone the repository.

2. Install Composer autoload (no third-party packages required):

   ```bash
   composer install
   ```

3. Copy configuration and set a **32-byte** encryption key (Base64):

   ```bash
   cp config/config.example.php config/config.php
   ```

   Generate a key:

   ```bash
   php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
   ```

   Put that value in `config/config.php` as `encryption_key`. Set `base_url` to the URL of your `public` folder (no trailing slash), e.g. `https://dashboard.example.com/public`. Optional: `plugin_package_max_bytes` caps uploaded plugin zip size (default 20 MB).

4. Set **dashboard login** in `config/config.php`: array `dashboard_users` with `username` and `password_hash` per operator. Generate a hash:

   ```bash
   php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
   ```

   The example in `config.example.php` uses password `changeme` for user `admin` — replace before production.

   On first run, those users are **copied into** the `dashboard_users` SQLite table. While that table has any rows, sign-in uses it (not `config.php`). Use **Account** in the nav to change your password.

5. Ensure `storage/` is writable by the web server (SQLite file will be created there).

6. Open the dashboard in the browser, sign in, then add a site using its **HTTPS** URL, **admin username**, and **Application Password**. On **each** WordPress site, install the **Companion plugin** and (if you use hub-offered companion updates and/or **Plugin packages** fleet deploy) add the `wp-config.php` defines described in **[Companion plugin](#companion-plugin)** below.

## Owners & email reports

1. **Owners** — In the nav, open **Owners** → **Add owner** (first name, last name, email, renewal date). Edit a site and choose an **Owner** to assign it.
2. **Email reports** — Set `report_mail_from` (and optional `report_mail_from_name`) in `config/config.php` to a valid address your host can send as. Then use **Email report** on an owner or **Email all owners** (only owners with at least one site).
3. Optional **`report_mail_signature`** in `config.php` — multi-line closing text (support team, help link, referrals, etc.) appended to every owner report.
4. Each report is plain text: friendly **site health** / **software** lines, **last checked** time, **30-day update history** with bullet details from the activity log.

Reports use PHP's **`mail()`** function; your server must be configured to deliver mail (sendmail, SMTP relay, or host panel mail).

## SSL certificate error when syncing ("unable to get local issuer certificate")

PHP on Windows often has **no CA certificate bundle**, so HTTPS calls to your WordPress sites fail even when the sites use valid certificates.

**Recommended (dashboard `config.php`):**

1. Download [cacert.pem](https://curl.se/ca/cacert.pem) (Mozilla CA bundle).
2. Save it somewhere permanent, e.g. `/path/to/cacert.pem` (Windows: `C:/PHP/extras/ssl/cacert.pem`).
3. In `config/config.php` set:

   ```php
   'curl_ca_bundle' => '/path/to/cacert.pem',
   ```

   Use forward slashes or escaped backslashes in the path.

**Alternative (global `php.ini`):** point both settings at the same file:

```ini
curl.cainfo = "/path/to/cacert.pem"
openssl.cafile = "/path/to/cacert.pem"
```

Restart PHP / your web server after editing `php.ini`.

**Not recommended except local testing:** in `config.php` you can set `'http_verify_ssl' => false` to skip certificate verification. Do **not** use that for production or over the public internet.

## WordPress: Application Password

On each site: **Users → Profile → Application Passwords**. Create a password for the admin user that the dashboard will use. Spaces in the password are ignored (same as WordPress).

## Companion plugin

Accurate update counts and remote **Update** / **Update all sites** from the dashboard require the companion plugin on each WordPress site. If **Sync** shows a yellow hint about the companion plugin, WordPress does not have it active yet—use the steps below.

1. Zip the folder `plugin/s35-wp-hub/` (the inner folder should be `s35-wp-hub` with `s35-wp-hub.php` inside).

2. In WordPress: **Plugins → Add New → Upload Plugin** and activate.

3. **Connect this site to your hub (`wp-config.php`)** — Add these **before** `wp-settings.php` (and before the *"That's all, stop editing!"* line). If you cannot edit `wp-config.php`, see **[If you cannot edit wp-config.php](#if-you-cannot-edit-wp-configphp)** (must-use plugin) below. The hub's **Settings** screen shows copy-paste `define` lines built from your `base_url`.

   | Constant | Purpose |
   |----------|---------|
   | `S35_WP_HUB_UPDATE_MANIFEST_URL` | Full **HTTPS** URL to your hub's `plugin-update/manifest.php` — WordPress can then offer **one-click updates** for the companion from your hub. See [Updating the companion plugin from your hub](#updating-the-companion-plugin-from-your-hub-optional). |
   | `S35_WP_HUB_ALLOWED_PACKAGE_HOSTS` | **Hostname only** (e.g. `wphub.example.com`, **not** `https://…`) — required for **Plugin packages** / fleet deploy **unless** the manifest URL is already on the **same host** as your hub's `base_url`. See [Plugin packages (fleet deploy)](#plugin-packages-fleet-deploy). |

   Example (adjust host and path to match your hub):

   ```php
   define( 'S35_WP_HUB_UPDATE_MANIFEST_URL', 'https://wphub.example.com/plugin-update/manifest.php' );
   define( 'S35_WP_HUB_ALLOWED_PACKAGE_HOSTS', 'wphub.example.com' ); // optional if manifest is on same host
   ```

4. Optional hardening: in `wp-config.php`, restrict REST access by IP:

   ```php
   define('S35_WP_HUB_ALLOWED_IPS', '203.0.113.10');
   ```

If you used an older companion plugin with a different REST namespace, **deactivate and delete** it, then install **`plugin/s35-wp-hub`** so WordPress exposes `/wp-json/s35-wp-hub/v1/...`. Update `wp-config.php` IP allowlist constants to match the new plugin if you used them before.

After **Sync**, the sites table shows **which plugins/themes/core** the hub thinks are pending (from the same internal queue WordPress uses when the companion plugin is current). If that count ever disagrees with what you see under **Dashboard → Updates**, compare the **named list** here with the expandable rows on the Updates screen — multilingual packs, bundled dependencies, or "active vs installed" filters can make the screens look different even though the underlying queue matches.

### Fleet Plugins tab (deactivate / delete)

On **Sites → Plugins**, the dashboard lists installed plugins aggregated from each site's last sync (`installed_plugins` from the companion summary). Each row can show a **WordPress.org** link (folder slug heuristic, like wp-admin "View details" for directory plugins) and an **Author** link when the companion **0.0.22+** sync includes `author` / `author_uri` from plugin headers (same links appear in the site list modal). You can **Deactivate** or **Remove** (delete files) for **all sites** that carry that plugin, or open the site list and act on **one site**. The companion exposes:

- `POST /wp-json/s35-wp-hub/v1/plugins/deactivate` — body `{"plugin_file":"slug/plugin.php"}` (`activate_plugins`). Cannot deactivate the companion itself.
- `POST /wp-json/s35-wp-hub/v1/plugins/delete` — same body (`delete_plugins`). Cannot delete the companion itself.

Rebuild and deploy the companion zip so each site meets `S35WpHub\Version::COMPANION_PLUGIN_EXPECTED` in [`src/Version.php`](src/Version.php). After fleet actions, the hub runs **Sync** on affected sites and logs **`plugin_deactivate` / `plugin_delete`** (and failures) in the activity log.

## If you cannot edit wp-config.php

Some hosts block direct edits to `wp-config.php`. If you can add files under **`wp-content/mu-plugins/`** (FTP, SFTP, host file manager, or deploy pipeline), create that folder if it does not exist and add a single file, e.g. `s35-wp-hub-config.php`:

```php
<?php
/**
 * s35-wp-hub: same defines you would put in wp-config.php (loaded before regular plugins).
 */
if ( ! defined( 'S35_WP_HUB_UPDATE_MANIFEST_URL' ) ) {
    define( 'S35_WP_HUB_UPDATE_MANIFEST_URL', 'https://YOUR-HUB-HOST/plugin-update/manifest.php' );
}
if ( ! defined( 'S35_WP_HUB_ALLOWED_PACKAGE_HOSTS' ) ) {
    define( 'S35_WP_HUB_ALLOWED_PACKAGE_HOSTS', 'YOUR-HUB-HOSTNAME' );
}
```

Replace `YOUR-HUB-HOST` / `YOUR-HUB-HOSTNAME` with your real hub hostname (hostname for package hosts must be **without** `https://` or paths). Omit the second `define` if the manifest URL is already on the same host as your hub's `base_url`. Optional IP restriction:

```php
if ( ! defined( 'S35_WP_HUB_ALLOWED_IPS' ) ) {
    define( 'S35_WP_HUB_ALLOWED_IPS', '203.0.113.10' );
}
```

Must-use plugins cannot be deactivated from the Plugins screen; remove or edit the file to change values.

## Updating the companion plugin from your hub (optional)

WordPress can offer a normal **Plugins → update** flow for the **35sDashHub Companion** (`plugin/s35-wp-hub`) when each site knows where to read release metadata. The **version** is read from your repo's `plugin/s35-wp-hub/s35-wp-hub.php` (`S35_WP_HUB_VERSION`); the **package** URL is built from `base_url` in `config/config.php`.

### Recommended: dynamic manifest (`manifest.php`)

1. Build the zip served to WordPress (same layout as a manual install) into `public/plugin-update/s35-wp-hub.zip`:

   ```bash
   composer plugin-zip
   ```

   Or use **Plugin packages → Build companion zip** in the dashboard (requires PHP **zip** on the server).

2. On **each WordPress site** (in `wp-config.php`, **before** `wp-settings.php`):

   ```php
   define('S35_WP_HUB_UPDATE_MANIFEST_URL', 'https://your-hub-host/public/plugin-update/manifest.php');
   ```

   The URL must be **HTTPS**. WordPress fetches JSON from that script; it always reflects the current `S35_WP_HUB_VERSION` in the source tree. **Rebuild the zip** after bumping the version so the downloaded archive matches the advertised version.

### Alternative: static `manifest.json`

Copy [`public/plugin-update/manifest.example.json`](public/plugin-update/manifest.example.json) to `public/plugin-update/manifest.json` (gitignored), edit **`version`** (must be greater than the installed plugin) and **`package`** (HTTPS URL to the zip). Point `S35_WP_HUB_UPDATE_MANIFEST_URL` at that JSON file.

You can also use the filter `s35_wp_hub_update_manifest_url` from a small mu-plugin.

WordPress checks on its usual schedule (and when you open **Plugins** / **Updates**). The manifest response is cached for several hours on the site. **Only HTTPS** manifest and **HTTPS** `.zip` URLs are accepted.

## Plugin packages (fleet deploy)

The dashboard **Plugin packages** page lets you upload valid WordPress plugin zips (one top-level folder, standard plugin headers). Files are stored under `public/plugin-packages/{id}.zip` and are reachable at `{base_url}/plugin-packages/{id}.zip`.

1. On each WordPress site, allow your hub's hostname for package downloads. Packages are fetched from `{base_url}/plugin-packages/…` on the hub. If `S35_WP_HUB_UPDATE_MANIFEST_URL` is **not** set (or points at another hostname), you **must** set `S35_WP_HUB_ALLOWED_PACKAGE_HOSTS` to the **hostname from your hub `base_url`** (no `https://`, no path). If the manifest URL is set on the **same** host as `base_url`, that host is allowlisted automatically. Companion **0.0.7+** treats `www` and bare domain as the same. Example:

   ```php
   define('S35_WP_HUB_ALLOWED_PACKAGE_HOSTS', 'your-hub.example.com');
   ```

   Only **HTTPS** URLs ending in **`.zip`** are accepted. The REST user must be able to **install** and **update** plugins (`install_plugins` and `update_plugins`).

2. In the dashboard, upload a package, select sites, and **Deploy to selected sites**. The companion calls `POST /wp-json/s35-wp-hub/v1/plugins/install-package` with the package URL; WordPress downloads the zip from your hub and installs or upgrades the plugin.

Activity log actions: `plugin_deploy` / `plugin_deploy_failed`. **Settings** shows copy-paste lines for `S35_WP_HUB_UPDATE_MANIFEST_URL` and `S35_WP_HUB_ALLOWED_PACKAGE_HOSTS` based on `base_url`.

After **Sync**, the **Sites** table shows a **Hub settings** line (companion **0.0.8+**) comparing this hub's `base_url` host to the site's manifest URL and package allowlist, so you can see if fleet deploy and manifest alignment look correct without opening each `wp-config.php`.

## Security notes

- Application passwords are stored **encrypted at rest** (AES-256-GCM). If the encryption key leaks, rotate all Application Passwords and generate a new key.
- Run the dashboard only over **HTTPS**.
- The dashboard includes **session login** (`dashboard_users` / SQLite). For extra hardening on public hosts, add **HTTPS**, strong passwords, and optionally web-server **Basic Auth** or network restrictions.
- Fleet deploy only accepts package URLs on **allowlisted hosts** (`S35_WP_HUB_ALLOWED_PACKAGE_HOSTS` or the host from `S35_WP_HUB_UPDATE_MANIFEST_URL`). Uploaded zips are validated (structure + size limit via `plugin_package_max_bytes`).

## Companion plugin zip (for uploads / hub updates)

After changing files under `plugin/s35-wp-hub/`, build a WordPress-ready zip (folder `s35-wp-hub/` inside the archive) into `public/plugin-update/s35-wp-hub.zip`:

```bash
composer plugin-zip
```

The same logic runs from the dashboard (**Plugin packages → Build companion zip**) via [`CompanionZipBuilder`](src/Service/CompanionZipBuilder.php).

If `s35-wp-hub.zip` already exists, it is renamed to `s35-wp-hub-{version}.zip` first, where `{version}` is the **previous** package version recorded from the last run (see `public/plugin-update/.last-packaged-version`, gitignored).

- **`composer plugin-zip`** — requires PHP CLI with the **zip** extension (`extension=zip` in `php.ini`).
- **`composer plugin-zip:ps`** — Windows only: same result using PowerShell `Compress-Archive` if PHP zip is not enabled.

### Dashboard code zip (backup / deploy)

- **`composer dashboard-zip`** — writes `dist/s35-wp-hub-dashboard-v{VERSION}.zip` (folder inside: `s35-wp-hub-dashboard/`). Includes `vendor/` when present so you can upload and run without Composer on the host. Omits `config/config.php`, SQLite databases, and `.git`.
- **`composer dashboard-zip -- --no-vendor`** — smaller archive; run `composer install --no-dev` on the server after unzip.
- Requires PHP CLI with the **zip** extension (`extension=zip`). On Windows: same `php.ini` as your CLI `php --ini`.

## Project layout

| Path | Purpose |
|------|---------|
| `public/` | Web root (`index.php`, assets) |
| `public/plugin-packages/` | Uploaded fleet-deploy plugin zips (`{id}.zip`) |
| `public/plugin-update/manifest.php` | Dynamic companion update manifest (version from `plugin/s35-wp-hub`) |
| `src/` | Application code (namespace `S35WpHub`) |
| `config/` | `config.php` (you create; gitignored) |
| `storage/` | SQLite database (gitignored); includes `dashboard_users` for sign-in |
| `plugin/s35-wp-hub/` | WordPress companion plugin |
| Owners (SQLite) | `owners` table; `sites.owner_id` assignment |
| `plugin_packages` (SQLite) | Uploaded package metadata |
| `scripts/build-plugin-zip.php` | CLI wrapper; builds zip via `CompanionZipBuilder` |
| `scripts/build-dashboard-zip.php` | Builds `dist/s35-wp-hub-dashboard-v*.zip` |

## Roadmap

Future releases may add PDF reports (Dompdf), one-click SSO via the companion plugin, and richer logging.

---

## 💎 Editions & Pricing

This project follows an **Open Core** model. The engine is free and open-source, while advanced enterprise features require a license.

---

## 🤝 Contributing

Contributions make the open-source community thrive!

1. **Fork** the Project.
2. **Create** your Feature Branch (`git checkout -b feature/AmazingFeature`).
3. **Commit** your Changes (`git commit -m 'Add AmazingFeature'`).
4. **Push** to the Branch (`git push origin feature/AmazingFeature`).
5. **Open** a Pull Request.

*By contributing to this project, you agree that your contributions may be used in both the Community and Pro editions.*

---

## ⚖️ License

The **Community Edition** is distributed under the **MIT License**. See `LICENSE` for more information.

**Ready to scale? [View Pricing & Launch on 35sites Cloud](https://35sites.com/applications/35s-WPHubDashboard)**

---

## 📫 Contact

**Jorge Pereira** - [@jorper98](https://twitter.com/jorper98)  
**Company:** [35sites.com](https://35sites.com)

**Project Link:** [https://github.com/jorper98/35sWPDashHub](https://github.com/jorper98/35sWPDashHub)

---

## 🤖 Artificial Intelligence Use Disclaimer

Portions of this project were developed with the assistance of AI models (including code generation, documentation, and structural planning). All AI-generated output has been reviewed, tested, and integrated by the author to ensure quality, security, and functionality.

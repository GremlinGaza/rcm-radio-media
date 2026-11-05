# RCM Radio Media — PHP / MySQL starter

A responsive radio/media website built with HTML, CSS and vanilla JavaScript on the frontend, PHP 8+ on the backend, and MySQL/MariaDB for persistent content.

## Features included
- Responsive header, navigation and footer; branded RCM look.
- Live audio player with play/pause/mute and visible setup/error states.
- Responsive show/podcast cards with optional thumbnail and media URLs, including supplied poster artwork for RCM Breakfast, Vibes Mtaani and Taarab Taratibu.
- Newsroom with a featured latest story, category filters, search, publisher/source attribution and external source links.
- News roundup that collects short, attributed headlines from Daily Nation and The Standard in Kenya and Al Jazeera internationally; links to original articles, with a manual refresh and per-source health status.
- Shows/programs page with category filters and search; listings are loaded from published database records.
- Partner promotion showcase with supplied campaign artwork, offer details, direct partner contact links and an advertising enquiry path.
- Admin-managed links to official social channels and a searchable homepage content hub for published live replays, recorded shows, clips and news updates shared on YouTube, TikTok and Facebook.
- Daily server-side sync for the latest YouTube channel videos, Facebook Page posts and TikTok videos, with a manual admin sync action and per-platform health status.
- Admin-only privacy and data monitor for platform account configuration, TikTok encrypted-token verification, sync health, and collected social/news item counts without exposing credentials.
- Listener opt-in email digests for new shows and news headlines, with double confirmation, category preferences, RCM site links, and a one-click-to-confirm unsubscribe flow sent through Gmail SMTP.
- TikTok, Facebook, Instagram and YouTube links configurable in SQL.
- Four requested partners: Aura & Co Ltd, RCM Online College, Tric Gaming Hub, RCM Welfare Association.
- Contact form saves messages to MySQL.
- Admin login, password hashing, CSRF checks, admin forms for publishing news and shows, and live-stream settings.
- Admin workflow dashboard for programme scheduling, partner management and private campaign uploads, listener inbox/comments, subscriber preferences, and activity history.
- Social/RSS moderation queues: newly collected or changed items remain unpublished until an admin approves them; rejected items can be removed.
- Admin monitors for database schema, daily sync/digest runs, Gmail configuration presence, and keyed credential-change fingerprints without displaying secret values.
- JSON programs endpoint at `api/programs.php`.

## Requirements

- PHP 7.4 or newer with `PDO`, `pdo_mysql`, `curl`, `openssl`, `SimpleXML`, and `fileinfo` enabled. `mbstring` is recommended for correct Unicode headline lengths. For partner uploads, set PHP `upload_max_filesize` and `post_max_size` to at least 8 MB (a slightly higher `post_max_size` allows multipart overhead). No Composer or third-party PHP packages are required. For public hosting, use a maintained PHP version (8.2 or newer recommended).
- MySQL 5.7+ or MariaDB 10.3+.
- Apache with PHP enabled (XAMPP includes Apache, PHP, and MariaDB).

PHP 7.4.2 has been syntax-checked against the current project in the local XAMPP installation. PHP 7.4 is end-of-life; for an internet-facing deployment, use a maintained PHP release and a current XAMPP build.

## Local setup (XAMPP)

1. Copy the project folder to `C:\xampp\htdocs\rcm-radio-media` (or extract it there).
2. Open the XAMPP Control Panel and start **Apache** and **MySQL**. If Apache cannot start, resolve its port conflict before continuing.
3. Open `http://localhost/phpmyadmin/`, create/select the `rcm_radio_media` database, and import `database.sql` once.
4. Copy `config.example.php` to `config.php`. For a default local XAMPP install, use `127.0.0.1`, database `rcm_radio_media`, user `root`, and an empty password; set the same values in phpMyAdmin if your MySQL root account has a password. Replace `setup_token` with a long random one-time secret.
5. From Command Prompt, run the schema migrations from the project directory:

   ```bat
   cd /d C:\xampp\htdocs\rcm-radio-media
   C:\xampp\php\php.exe scripts\migrate-social-sync.php
   C:\xampp\php\php.exe scripts\migrate-news-feeds.php
   C:\xampp\php\php.exe scripts\migrate-email-notifications.php
   C:\xampp\php\php.exe scripts\migrate-admin-workflow.php
   ```

   These migrations are safe to rerun. They create sync/news/email/admin workflow tables when missing and add scheduled visibility fields to programs; the base site tables and starter programs/news come from `database.sql`.
6. If the `admins` table is empty, create the first admin at `http://localhost/rcm-radio-media/setup_admin.php?token=YOUR_SECRET_TOKEN` using a password of at least 12 characters. **Delete `setup_admin.php` immediately afterwards.** Do not run first-admin setup if an admin account already exists.
7. Open `http://localhost/rcm-radio-media/`. Sign in at `http://localhost/rcm-radio-media/admin/login.php`; the workflow dashboard at `/admin/dashboard.php` provides review queues, program/partner controls, the listener inbox, subscription management and system monitors.

If a page reports a database connection error, check that MySQL is running and that `config.php` matches its host, database name, username, and password. If PHP reports a missing driver or function, enable the named extension in `C:\xampp\php\php.ini`, restart Apache, and confirm the extension appears in `C:\xampp\php\php.exe -m`.

On this workstation another Apache and MySQL server already own ports 80/443 and 3306. XAMPP Apache is configured to use port 8080 and 4443; XAMPP MariaDB uses local-only port 3307. The local site URL is `http://localhost:8080/rcm-radio-media/`; the app's local `config.php` must use `db_port` 3307. Other XAMPP installations can use the default MySQL port 3306.

For project testing on another device connected to the same Wi-Fi, open `http://HOST-LAN-IP:8080/rcm-radio-media/`, replacing `HOST-LAN-IP` with the Wi-Fi IPv4 address shown by `ipconfig` on the XAMPP computer. The local Windows firewall is limited to TCP 8080 from `LocalSubnet`; MariaDB remains bound to `127.0.0.1:3307` and is not shared. This setup is for same-network testing only, not public internet hosting. College/public Wi-Fi may block device-to-device traffic (client isolation), and changing Wi-Fi networks can change the host IP.

On this workstation, Apache and MariaDB use separate ports from the other local stack. After a computer restart, start MariaDB and Apache before testing (PowerShell commands):

```powershell
Start-Process 'C:\xampp\mysql\bin\mysqld.exe' -ArgumentList '--defaults-file=C:\xampp\mysql\bin\my.ini --port=3307 --bind-address=127.0.0.1 --pid-file=C:\xampp\mysql\data\mysql-3307.pid --console'
Start-Process 'C:\xampp\apache\bin\httpd.exe' -ArgumentList '-f C:\xampp\apache\conf\httpd.conf'
```

Keep the computer awake and connected to Wi-Fi while testing; the separate local feed-refresh task runs daily.

The concise listener/admin workflow is in [RCM-User-Guide.pdf](./RCM-User-Guide.pdf).

## Public deployment (cPanel)
The XAMPP instance is for local development and must not be exposed directly to the internet. Deploy the site to a supported cPanel host instead. Use PHP 8.2 or newer with `pdo_mysql`, `curl`, `openssl`, `SimpleXML`, `fileinfo` and `mbstring`; enable HTTPS/AutoSSL before opening the site to visitors. No Composer packages are needed.

1. In cPanel, create a MySQL database and a dedicated database user; grant that user only the privileges for this site's database. Note the complete prefixed database name/user and the database host shown by cPanel.
2. Select the new database in phpMyAdmin and import `database.sql`. It creates the tables and starter content within the currently selected database; it does not attempt to create a database or user.
3. Upload the production ZIP to the domain's document root (commonly `public_html`) and extract it there. Do not upload `config.php`, `setup_admin.php`, local backups, or any secret file. Keep `.htaccess` in the document root.
4. Import the included `database-content.sql` into the same selected database to copy the current public programs, stories, social links and posts. Delete this SQL file from `public_html` after import; `.htaccess` blocks it while present.
5. Create `config.php` **outside** the document root (for example `/home/CPANELUSER/rcm-private/config.php`) using `config.example.php` as a template. Fill in the cPanel database credentials and set a new random `setup_token`. Configure the server environment values `RCM_CONFIG_FILE=/home/CPANELUSER/rcm-private/config.php` and `RCM_PUBLIC_SITE_URL=https://rcmradiomedia.co.ke` in the cPanel PHP/application environment. If the host requires Apache directives instead, ask support whether `SetEnv` is supported for PHP and use `SetEnv RCM_CONFIG_FILE /home/CPANELUSER/rcm-private/config.php` and `SetEnv RCM_PUBLIC_SITE_URL https://rcmradiomedia.co.ke` in `.htaccess`. Do not put database credentials in a public form or commit them.
6. In cPanel Terminal, run the four schema migrations from the site's root: `php scripts/migrate-social-sync.php`, `php scripts/migrate-news-feeds.php`, `php scripts/migrate-email-notifications.php`, and `php scripts/migrate-admin-workflow.php`. These are safe to rerun.
7. Create an admin over the cPanel Terminal without putting the password in the command history. In Bash, run `read -s -p "New admin password: " RCM_ADMIN_PASSWORD; echo; export RCM_ADMIN_PASSWORD; php scripts/create-admin.php "Mike Mwashuke"; unset RCM_ADMIN_PASSWORD`. Use a new, unique password (not the password previously used on local XAMPP). The script is CLI-only, stores a password hash, and refuses to replace an existing account.
8. Enable AutoSSL for `rcmradiomedia.co.ke` and `www.rcmradiomedia.co.ke`, verify DNS points to the hosting account, and test `https://rcmradiomedia.co.ke/` and `https://rcmradiomedia.co.ke/admin/login.php`. Enforce HTTPS through the hosting provider after the certificate is active.
9. Configure only the private server credentials needed for optional services (social APIs and Gmail notifications) in cPanel's supported environment-variable settings. The public website and admin monitor work without Facebook/TikTok API credentials; those platform sync rows will correctly report not configured until credentials are added. Never copy secrets from the local XAMPP configuration.
10. Create a private, writable upload folder outside `public_html` (the default is `/home/CPANELUSER/rcm-private/partner-media`; set `RCM_PRIVATE_UPLOAD_DIR` if your host uses a different path). The admin accepts JPEG, PNG, WebP and PDF files up to 8 MB, verifies MIME types, and keeps uploads private until an admin approves them.
11. Add `RCM_MONITOR_FINGERPRINT_KEY` as a private random server environment variable to enable credential-change detection. Generate a long random value and keep it stable; the monitor stores keyed fingerprints only and never displays credential values. Without it, the dashboard reports configuration presence but cannot verify value changes.
12. Configure cPanel Cron to run `php /home/CPANELUSER/public_html/scripts/sync-social-platforms.php` daily, using the PHP binary and full paths specified by your host. It syncs social/RSS content into admin review queues and sends digests only for approved, published content to confirmed subscribers. The admin dashboard records the last run but cannot create or verify the cPanel Cron entry itself.

The bundle's `database-content.sql` contains public site content (programs, public news/headlines, social links and public post links) exported from the local database. It excludes admin accounts, contact messages, email subscribers, database credentials, and encrypted API tokens. Import it after `database.sql` if you want to carry the current local content to the live site. Treat any later database export as private because it may contain subscriber or contact information.

The local admin password was disclosed during setup; it must not be reused on the public host. Before going live, change/rotate any reused passwords and keep cPanel, database and email secrets private.

For PHP's built-in server, from this directory run `php -S 127.0.0.1:8000`, then visit `http://127.0.0.1:8000`. This is for local development only, not production.

## Before launch — replace placeholders
- **Live broadcast:** add the direct public stream URL (MP3/AAC/compatible HLS) in Admin → Station settings. A webpage URL to a player is not necessarily an audio stream. Test on HTTPS.
- **Social accounts:** update each row in the `socials` table with the official platform profile URL.
- **Partner links:** update `website_url` in `partners` with each verified official site.
- **Shows/podcasts:** the program directory is seeded with the supplied five weekday and three weekend shows, including presenter names and broadcast times. Artwork for RCM Breakfast, Vibes Mtaani and Taarab Taratibu is stored in `assets/images/programs/`; cards show the latest supplied RCM schedule separately because poster details can differ from that lineup.
- **Kenya news:** RCM journalists can write original stories in the admin. Collected publisher items are limited to attributed RSS headlines and brief excerpts linking to the original source; an admin must review each item before it appears publicly. Do not copy or republish full articles without explicit permission or a licence.
- **Collected headlines:** the news panel collects up to 20 recent RSS headlines per publisher from Daily Nation, The Standard and Al Jazeera, then sorts them by published time. Descriptions are trimmed to brief snippets and each item enters the admin review queue before publication. This is a latest-headlines roundup, not a popularity-based trending ranking; source availability and feed coverage may vary.
- **Homepage sequence:** the homepage introduces the station and live player, then highlights shows, news, partners and social channels. The newsroom and show directory each provide responsive search and category filters over the published database records.
- **Partner promotions:** supplied campaign artwork is stored in `assets/images/partners/` and presented on `partnerships.php`. Prices, event dates and offers are transcribed from the submitted posters and should be confirmed with the partner before publication or purchase. Advertising formats and rates are discussed through the contact form and are not represented as a published rate card.
- **Contact and comments:** contact messages are stored in the database and read/marked handled from the authenticated admin inbox; replies are sent using your normal email account. Listener comments require admin approval before they are shown publicly.
- **Logo:** the approved RCM Radio logo artwork is used in the shared header, footer and live player from `assets/images/rcm-radio-logo.png`.
- **Social feed sync:** the homepage feed combines approved manually added items with approved YouTube, Facebook and TikTok posts. Instagram sync is not enabled. The site reads and links to public content; it does not publish to social platforms. New or changed synced items stay unpublished until reviewed. The first sync imports the latest 15 YouTube feed entries, up to 50 Facebook Page posts and up to 20 TikTok videos; later runs update/deduplicate those items.
- **API accounts:** YouTube channel ID and TikTok handle are derived from Admin → Social channel links. Facebook's Page ID can be set as `facebook_page_id` in the private `config.php`, supplied through `RCM_FACEBOOK_PAGE_ID`, or derived from the Page URL; update public channel URLs in Admin → Social channels if they change. YouTube uses the channel's public RSS feed. Facebook also requires a Page access token with permission to read the Page feed. TikTok requires an approved Display API app, `video.list` scope, user access and refresh tokens. Instagram was explicitly left out of this sync.
- **Keep credentials private:** in the Facebook/Meta developer console, create an app with permission to read the RCM Page feed and obtain a Page access token. In TikTok for Developers, enable the Display API with the `video.list` scope and authorize the RCM account to obtain its client key, client secret, access token and refresh token. YouTube requires no API secret; its channel ID is already in the official channel URL. Configure `RCM_FACEBOOK_PAGE_ACCESS_TOKEN`, `RCM_TIKTOK_CLIENT_KEY`, `RCM_TIKTOK_CLIENT_SECRET`, `RCM_TIKTOK_ACCESS_TOKEN`, `RCM_TIKTOK_REFRESH_TOKEN`, and `RCM_SOCIAL_CREDENTIAL_KEY` as private server environment variables; `RCM_FACEBOOK_PAGE_ID` is optional when `facebook_page_id` is set in `config.php` or the Page ID can be derived from its public link. Never commit credentials or enter them into a public page. Generate the random encryption key with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. On Windows/XAMPP, add the names and values in Windows System Properties → Environment Variables, then restart Apache so it inherits them. TikTok refreshed credentials are encrypted in the database with AES-256-GCM using `RCM_SOCIAL_CREDENTIAL_KEY`.
- **Privacy monitor:** Admin → Privacy & data monitor shows whether required environment variables are present, whether the saved TikTok token record decrypts with its AES-256-GCM key, account/feed health, and post/news counts. It never displays token values or ciphertext. Public profile URLs, imported post/news titles and summaries are not encrypted by the app because the public site must display them; this panel labels that distinction. Protect database files, backups, host environment variables and admin access using the production host's security controls.
- **Listener email notifications:** the homepage and footer link to `notifications.php`, where listeners choose program announcements, news headlines or both. Double opt-in confirms the email address before any digest is sent; unsubscribe is confirmed by the listener and included in every digest. Daily email contains newly published, approved program/news items with links to the matching sections on the RCM site. Subscriber email addresses are stored in the database to deliver mail; they are not encrypted by the app. Restrict database and backup access. Only authenticated admins can see addresses in the subscriber management panel; the privacy monitor shows aggregate counts.
- **Gmail delivery setup:** secure the RCM Gmail account with 2-Step Verification and create a Google App Password for this site (do not use the normal Gmail password). Set `RCM_GMAIL_ADDRESS`, `RCM_GMAIL_APP_PASSWORD`, `RCM_PUBLIC_SITE_URL` (the production HTTPS site root), and `RCM_EMAIL_TOKEN_SECRET` (at least 32 random characters) as private system environment variables. Generate a signing key using `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. For Windows/XAMPP, add them via Windows System Properties → Environment Variables, then restart Apache and ensure the Windows Task Scheduler account inherits the same variables. Gmail SMTP uses TLS on port 587. Do not commit credentials or enter them into chat or public admin forms.
- **Install email subscriptions:** run `C:\xampp\php\php.exe scripts\migrate-email-notifications.php` from the project directory (adjust the PHP path on other hosts). To test configured Gmail delivery, subscribe using an address you control, confirm through the email, then run `C:\xampp\php\php.exe scripts\send-email-digests.php` after publishing a new program or headline. The script does not send an empty digest.
- Email link signing is deterministic per subscriber so unsubscribe links from older digests continue to work. Changing `RCM_EMAIL_TOKEN_SECRET` invalidates previously sent unsubscribe links.
- **Schedule email digests:** the existing daily Windows Task Scheduler job that runs `scripts\sync-social-platforms.php` also sends email digests after refreshing social and news sources. Alternatively schedule `scripts\send-email-digests.php` once each day. Confirm the task uses the correct project directory and inherits the private Gmail/site environment variables. Failed deliveries are logged by subscriber ID only (not the email address) and the CLI exits unsuccessfully if any message fails.
- **Install sync schema:** after deploying this change, run `C:\xampp\php\php.exe scripts\migrate-social-sync.php` from the project directory (adjust the PHP path for the host). This adds only the sync tracking/token tables and is safe to rerun.
- **Install news feed schema:** run `C:\xampp\php\php.exe scripts\migrate-news-feeds.php` from the project directory. This is safe to rerun.
- **Install admin workflow schema:** run `C:\xampp\php\php.exe scripts\migrate-admin-workflow.php` from the project directory (adjust the PHP path for the host). This adds the moderation, inbox, activity, credential-monitor and scheduled-program fields; it also places previously auto-published synced items back into review once.
- **Schedule daily sync on Windows:** create a Windows Task Scheduler task that runs `C:\xampp\php\php.exe` with argument `C:\path\to\rcm-radio-media\scripts\sync-social-platforms.php`, with the project directory as “Start in”, and trigger it daily. This runner refreshes the social platforms and the news RSS feeds. On Linux, run `php /path/to/rcm-radio-media/scripts/sync-social-platforms.php` from cron. To refresh news alone, run `scripts/sync-news-feeds.php`. Admin → Automatic social feed sync → Sync platforms now tests social credentials; Admin → Collect news headlines refreshes publisher feeds. Platform API approval, scopes and valid unexpired credentials are required; errors are shown separately for each platform.
- **Credential change monitor:** configure `RCM_MONITOR_FINGERPRINT_KEY` as a stable private random environment variable. It enables keyed fingerprints so the admin can notice credential value changes without saving or displaying the values. Rotating this monitor key resets fingerprints and should be treated as a new baseline.
- **Partner uploads:** configure `RCM_PRIVATE_UPLOAD_DIR` or use the default private folder beside the site root. The host must allow PHP to create/write there. Only JPEG, PNG, WebP and PDF files up to 8 MB are accepted; admins review uploads before they become public.

## Security / production notes
- Keep `config.php` private and outside version control.
- Serve over HTTPS; use strong admin credentials and least-privilege DB credentials.
- Delete `setup_admin.php` after setup.
- Configure backups, PHP error logging, spam/rate limiting for public forms, and security headers.
- This is a project starter, not a substitute for a security review before public deployment.

## Main structure
- `index.php` — home page
- `news.php` — newsroom
- `programs.php` — all shows and podcasts
- `partnerships.php` — partner campaigns and advertising enquiries
- `social_updates` and `social_sync_*` tables — published platform posts, deduplication, sync health and encrypted TikTok tokens
- `scripts/sync-social-platforms.php` — CLI-only scheduled platform sync
- `email_subscribers` table and `scripts/send-email-digests.php` — double-opt-in listener notifications sent through Gmail SMTP
- `contact.php` — contact form
- `admin/` — login, dashboard and logout
- `api/programs.php` — JSON endpoint
- `includes/` — reusable PHP layout and DB helpers
- `assets/css/style.css` — responsive styling
- `assets/js/app.js` — navigation and player interaction
- `database.sql` — database schema and starter data

# Barangay Waste Management and Collection Monitoring System

ITP09 Systems Integration and Architecture project.
Stack: PHP, MySQL, custom REST API, HTML, Bootstrap 5, JavaScript.

Status: Phase 6 done (security hardening and error handling), plus the Figma redesign of all pages and the Forgot Password feature.
Next: documentation and defense notes. Online deployment waits for your hosting choice.

## What works now
Each role has **one dashboard page** with everything on it. The menu at the top jumps to each section.

| Who | Page | What is on it |
|---|---|---|
| Resident | `resident.html` | **Welcome message** with today's date, a **Next Pickup** banner and **Weekly Schedule** (only the resident's own zone; the zone is chosen at registration and cannot be changed), a **My Reports** card (status filter; each report is a card, open one to see its full status history) with a **Submit a Report** button that opens a pop-up form (zone, issue type, waste-type buttons, description, 1 required photo and an optional second one; a bottom sheet on phones), and an **avatar menu** in the header with **Profile** (change first and last name, email, phone number and password; changing the email or password asks for the current password) and **Log out** |
| Admin | `admin.html` | **Overview** (report counts by status, active schedules, registered residents; select a status tile to filter the report list), **Collection schedules** (add, edit, delete; inactive ones are hidden from residents), **Report history** (every report, filterable by status and zone; open one to see resident contact details and change its status with remarks, which is added to the history) |

Residents pick their address from a dropdown on the Register page (for example "Barangay Pogi Zone 1, Sitio Merete"). The address sets their zone, and neither can be changed by the resident afterwards (the Profile has no address field, and the server ignores one if sent). The resident dashboard opens with "Welcome, <first name>!". The password boxes on the Login and Register pages have an eye button to show or hide what was typed; on Register one eye controls both boxes. A resident without a zone (for example an older account) sees a message to contact the barangay office. The server only ever sends a resident the schedules of their own zone.

The pages follow the Figma designs (desktop and mobile). Fonts (Montserrat and Lato) load from `cdn.jsdelivr.net`, the same host Bootstrap uses, so the security policy did not change. The admin pages have no Figma design; they use the same colors, fonts, buttons and cards.

**Forgot password:** the Login page links to `forgot-password.html`. The resident enters their email and gets a link (valid for 1 hour, works once) to `reset-password.html`. The reply is the same whether or not the email exists. Only a hash of the link's token is stored (table `password_resets`). Admin accounts cannot use it; change the admin password with `tools/set-admin-password.php`. Emails are sent with PHP `mail()`; on XAMPP there is no mail server, so with `debug` set to `true` the link is written to the PHP error log (`C:\xampp\apache\logs\error.log`). Optional settings `app_url` and `mail_from` are in `config.example.php`.

A single report still opens on its own page (`report.html`), with a link back to the dashboard.

Photos are stored in the `uploads` folder under random names. Apache is told not to
serve that folder directly (`uploads/.htaccess`); photos are shown only through the API,
and only to the resident who sent the report and to admins.

## Local setup (XAMPP on Windows)

### 1. Put the project in XAMPP
1. Install XAMPP (PHP 8.1 or newer recommended).
2. Unzip this project so the folder is `C:\xampp\htdocs\waste-management`.
3. Open the **XAMPP Control Panel** and start **Apache** and **MySQL**.

### 2. Create the settings file
Copy `api\config\config.example.php` to `api\config\config.php`.
The defaults match a fresh XAMPP (user `root`, empty password), so no edits are needed.

### 3. Import the database (two files, in this order)
Both files are in the `database` folder of this project.

**Option A - phpMyAdmin (easiest)**
1. Open http://localhost/phpmyadmin
2. Click the **Import** tab at the top (do not select a database first).
3. Choose `database/schema.sql` and click **Import**. This creates the `waste_management` database and its 8 tables.
4. Click **Import** again, choose `database/seed.sql`, and click **Import**. This adds the starter data.
5. Click `waste_management` in the left list. You should see 8 tables.

**Option B - command line**
```
cd C:\xampp\htdocs\waste-management
C:\xampp\mysql\bin\mysql.exe -u root < database\schema.sql
C:\xampp\mysql\bin\mysql.exe -u root < database\seed.sql
```

> `schema.sql` deletes and recreates every table. Never re-run it on a database
> that holds data you want to keep.

**Already imported an older version?** First run `database/migration_02_pogi_zones.sql` (it sets the six Barangay Pogi zones, whatever zones you have now: it renames existing Zones 1-6 and adds the missing ones; safe to run again). Then run `database/migration_01_user_zone.sql` once (phpMyAdmin: select your database, open the **SQL** tab, paste the file's contents, click **Go**). Do this *before* uploading the new code. It adds the zone column to `users`; existing residents keep working and are asked to choose a zone at their next login.

**Forgot Password needs one more table:** on a database that already exists, run `database/migration_03_password_resets.sql` once the same way (a fresh `schema.sql` import already includes it).

**Reports now need 1 photo and can have 2 (15 MB in total).** On a database that already exists, run `database/migration_04_report_photos.sql` once (phpMyAdmin, SQL tab). It creates the `report_photos` table and copies the single photo of every older report into it, so old reports keep their photo. Running it twice is harmless. The PHP upload limits must allow 15 MB: `upload_max_filesize` at least 16M and `post_max_size` at least 20M (XAMPP already allows this; on cPanel use **MultiPHP INI Editor**). If a report with large photos fails with "The upload is too large", raise those two values.

### 4. Open the system
http://localhost/waste-management/public/login.html

Development admin login: `admin@barangay.local` / `Admin@12345`
Residents create their own account on the Register page.

**Change the admin password before real use.** From the project folder:
```
C:\xampp\php\php.exe tools\set-admin-password.php admin@barangay.local
```
It asks for the new password (at least 8 characters, with a letter and a number).

### 5. Run the tests (optional)
```
C:\xampp\php\php.exe tests\api_test.php http://localhost/waste-management/api
C:\xampp\php\php.exe tests\schedule_test.php http://localhost/waste-management/api
C:\xampp\php\php.exe tests\report_test.php http://localhost/waste-management/api
C:\xampp\php\php.exe tests\dashboard_test.php http://localhost/waste-management/api
C:\xampp\php\php.exe tests\profile_test.php http://localhost/waste-management/api
C:\xampp\php\php.exe tests\password_reset_test.php http://localhost/waste-management/api
C:\xampp\php\php.exe tests\security_test.php http://localhost/waste-management/api
C:\xampp\php\php.exe tests\performance_test.php http://localhost/waste-management/api
C:\xampp\php\php.exe tests\web_access_test.php http://localhost/waste-management
```
All lines should say PASS.

| Test | What it checks |
|---|---|
| `api_test`, `schedule_test`, `report_test`, `dashboard_test` | Accounts, schedules, reports, dashboard |
| `password_reset_test` | Forgot password: same reply for any email, one-time and expiring links, weak passwords, admin excluded |
| `security_test` | Role checks, CSRF, login limit, oversized or broken input, photo safety, security headers |
| `performance_test` | Each main request answers in under half a second (read-only) |
| `web_access_test` | The web server really blocks `.php` helpers, `database/`, `tests/`, `storage/` and `uploads/`. Run it on XAMPP: it needs real Apache because it reads the `.htaccess` files |

The tests create a few `test_...@example.com` accounts. `schedule_test.php` deletes the temporary schedule
it makes. The report tests leave their test reports (and a test photo in `uploads`) behind, because reports keep
their history on purpose. To start clean again, re-import `schema.sql` and `seed.sql`, empty the `uploads` folder
(keep `.htaccess` and `.gitkeep`) and empty `storage\login-attempts`.

## Security (Phase 6)
| Protection | How it works |
|---|---|
| Passwords | Stored only as hashes (`password_hash`) |
| SQL injection | Every query uses prepared statements |
| Cross-site scripting (XSS) | Pages show user text with `textContent`, never `innerHTML`; a Content-Security-Policy blocks inline scripts |
| CSRF | Every change (POST, PUT, DELETE) must send a secret token from the session |
| Sessions | HttpOnly and SameSite cookies, new id at login, 30-minute idle timeout |
| Roles | Checked on the server for every request. A resident asking for someone else's report gets "not found" |
| Login guessing | 5 wrong passwords for one email and device lock it for 15 minutes (counters are small files in `storage/login-attempts`) |
| Input size | JSON bodies over 64 KB and photos over 15 MB in total are refused; bad text encoding is refused |
| Photos | Every report needs 1 photo and may have 2 (15 MB in total); checked by their real content (JPG, PNG, WebP), renamed randomly, served only through the API after a permission check |
| Folders | `.htaccess` files deny web access to `api/config`, `controllers`, `models`, `helpers`, `database`, `tests`, `docs`, `storage`, `tools` and `uploads` |
| Errors | Visitors see a generic message; details go to the PHP error log |

**Before hosting online:** set `debug` to `false` in `api/config/config.php`, use HTTPS (the system then sends
HSTS and marks cookies `Secure` automatically), and change the admin password.

## Putting it on a cPanel host
1. In cPanel open **Manage My Databases**: create a database and a database user, then add the user to the database with **All Privileges**. Note the full names (they start with your account prefix) and the password you chose.
2. In **phpMyAdmin**, select that database and import `schema.sql`, then `seed.sql`. If you use the project's files, make sure they do not hard-code the XAMPP database name.
3. Upload the project folder with **File Manager** (for example into `public_html/waste-management`). Show hidden files so the `.htaccess` files go up too.
4. In `api/config`, copy `config.example.php` to `config.php` and edit it: `host` stays `localhost`; `name`, `user` and `password` are the cPanel values; set `debug` to `false`. For Forgot Password also set `app_url` to your site address (for example `https://waste-management-pogi.online`) and `mail_from` to an address on your domain (for example `no-reply@waste-management-pogi.online`).
5. Make sure the `uploads` and `storage` folders are writable (permission 755; use 775 if uploads or login limits fail).
6. If the page says "Could not connect to the database", open the `error_log` file that appears in the `api` folder in File Manager. It states the real reason (wrong password, wrong user name, user not added to the database).

## Academic disclaimer
Every page has a footer with a notice that the system is a demonstration made for an SIA subject project and is not an official barangay service. The text is in the `<footer class="site-footer">` block at the end of each `public/*.html` page; edit it there if the wording changes.

## Troubleshooting
| Problem | Fix |
|---|---|
| "Missing api/config/config.php" | Do step 2. |
| "Could not connect to the database" | Start MySQL in XAMPP; check the values in `config.php`. |
| API replies "Endpoint not found" for every URL | Apache's `mod_rewrite` must be on (it is by default in XAMPP) and `.htaccess` files allowed. |
| Pages look unstyled | Bootstrap loads from the internet (CDN); check your connection. |
| "Too many failed login attempts" | Wait the number of minutes shown, or empty `storage\login-attempts`. |
| Photos over a few MB fail with "The upload is too large" | Raise `upload_max_filesize` (16M or more) and `post_max_size` (20M or more) in PHP settings (cPanel: MultiPHP INI Editor). |
| Photo upload says "could not be uploaded" | Make sure the `uploads` folder exists in the project folder and is not read-only. |

## Folder guide
| Folder | Purpose |
|---|---|
| `api/` | PHP backend: `index.php` (router), `controllers/`, `models/`, `helpers/`, `config/` |
| `public/` | Pages, CSS, and JavaScript the browser loads (no inline scripts) |
| `database/` | `schema.sql` (tables), `seed.sql` (starter data) `migration_01_user_zone.sql` (for databases imported before zones were added to users) and `migration_02_pogi_zones.sql` (sets any database to the six Barangay Pogi zones) and `migration_03_password_resets.sql` (adds the Forgot Password table) and `migration_04_report_photos.sql` (adds the table for up to 2 photos per report) |
| `tests/` | API, security, speed and web-access tests |
| `tools/` | Command-line helpers (`set-admin-password.php`) |
| `storage/` | Login-attempt counters (not stored in Git, not reachable from the web) |
| `docs/` | Documentation (added in later phases) |
| `uploads/` | Report photos (not stored in Git) |

## Placeholders to replace later
`database/seed.sql` creates the six zones of Barangay Pogi (Zone 1 to 6 = Sitio Merete, Munoz, Arzadon, Daguison, Serafica, Aranda); each zone is one address on the Register page. The sample collection schedules cover Zones 1-3 only, so add schedules for Zones 4-6 as the admin.

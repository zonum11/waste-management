# Barangay Waste Management and Collection Monitoring System

ITP09 Systems Integration and Architecture project.
Stack: PHP, MySQL, custom REST API, HTML, Bootstrap 5, JavaScript.

Status: Phase 6 done (everything from Phase 5, plus security hardening and error handling).
Next: documentation and defense notes. Online deployment waits for your hosting choice.

## What works now
| Who | Can do |
|---|---|
| Resident | Register, log in, view active collection schedules (filter by zone), submit a waste report (zone, issue type, waste types, description, optional photo), see their own reports with status and the full status history |
| Admin | Log in to a **Dashboard** (report counts by status, the 5 latest reports, active schedules, registered residents; each status tile opens the report list for that status). **Schedules** page: add / edit / delete schedules (inactive ones are hidden from residents). **Reports** page (report history): every report, filterable by status, zone and date range; open one to see resident contact details and its full status history, and to change the status with remarks (every change is added to the history) |

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
C:\xampp\php\php.exe tests\security_test.php http://localhost/waste-management/api
C:\xampp\php\php.exe tests\performance_test.php http://localhost/waste-management/api
C:\xampp\php\php.exe tests\web_access_test.php http://localhost/waste-management
```
All lines should say PASS.

| Test | What it checks |
|---|---|
| `api_test`, `schedule_test`, `report_test`, `dashboard_test` | Accounts, schedules, reports, dashboard |
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
| Input size | JSON bodies over 64 KB and photos over 2 MB are refused; bad text encoding is refused |
| Photos | Checked by their real content (JPG, PNG, WebP), renamed randomly, served only through the API after a permission check |
| Folders | `.htaccess` files deny web access to `api/config`, `controllers`, `models`, `helpers`, `database`, `tests`, `docs`, `storage`, `tools` and `uploads` |
| Errors | Visitors see a generic message; details go to the PHP error log |

**Before hosting online:** set `debug` to `false` in `api/config/config.php`, use HTTPS (the system then sends
HSTS and marks cookies `Secure` automatically), and change the admin password.

## Academic disclaimer
Every page shows a notice that the system is a demonstration made for an SIA subject project and is not an official barangay service. The text is in the `<aside class="disclaimer">` block near the end of each `public/*.html` page; edit it there if the wording changes.

## Troubleshooting
| Problem | Fix |
|---|---|
| "Missing api/config/config.php" | Do step 2. |
| "Could not connect to the database" | Start MySQL in XAMPP; check the values in `config.php`. |
| API replies "Endpoint not found" for every URL | Apache's `mod_rewrite` must be on (it is by default in XAMPP) and `.htaccess` files allowed. |
| Pages look unstyled | Bootstrap loads from the internet (CDN); check your connection. |
| "Too many failed login attempts" | Wait the number of minutes shown, or empty `storage\login-attempts`. |
| Photo upload says "could not be uploaded" | Make sure the `uploads` folder exists in the project folder and is not read-only. |

## Folder guide
| Folder | Purpose |
|---|---|
| `api/` | PHP backend: `index.php` (router), `controllers/`, `models/`, `helpers/`, `config/` |
| `public/` | Pages, CSS, and JavaScript the browser loads (no inline scripts) |
| `database/` | `schema.sql` (tables) and `seed.sql` (starter data) |
| `tests/` | API, security, speed and web-access tests |
| `tools/` | Command-line helpers (`set-admin-password.php`) |
| `storage/` | Login-attempt counters (not stored in Git, not reachable from the web) |
| `docs/` | Documentation (added in later phases) |
| `uploads/` | Report photos (not stored in Git) |

## Placeholders to replace later
`database/seed.sql` uses placeholder zone names ("Zone 1-3") and the barangay name "Barangay Sample".

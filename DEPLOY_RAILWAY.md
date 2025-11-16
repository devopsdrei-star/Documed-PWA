# Deploying DocuMed to Railway

This guide walks you through hosting the PHP + static frontend on Railway alongside the existing MySQL service.

## 1. Prerequisites
- A Railway project (already has a MySQL service based on your screenshot).
- GitHub repo connected (or zip upload). Repository root contains `documed_pwa/`.
- Your schema already applied (via `backend/api/db_install.php` locally or Railway SQL console).

## 2. Create the Web Service
1. Go to Railway project > Architecture.
2. Click `+ Create` > `New Service` > `GitHub Repo` (select your repo) or upload.
3. Nixpacks will detect PHP, but set the start command explicitly:
   - Start Command: `php -S 0.0.0.0:$PORT -t documed_pwa`
4. (Optional) Add a Healthcheck path: `/backend/api/db_connection_test.php` (in Settings after deploy).

### Why 0.0.0.0:$PORT
Railway injects the PORT env var. Binding to 0.0.0.0 exposes the server to the container network; the `-t documed_pwa` sets documed_pwa as the web root so static pages and backend API are reachable.

## 3. Link Environment Variables
In the Web service (NOT the MySQL service):
- Variables > "Add Variable Reference" and pick these from the MySQL service if available:
  - `MYSQL_URL` (simplest; includes user/pass/host/db)
  - Or discrete: `MYSQLHOST`, `MYSQLPORT`, `MYSQLUSER`, `MYSQLPASSWORD`, `MYSQLDATABASE` / `MYSQL_DATABASE`
- You do NOT need `MYSQL_PUBLIC_URL` inside Railway (internal hostname is faster).
- Optionally set: `DEBUG_DB=1` temporarily to log the resolved host/db.

## 4. Deploy
Click "Deploy" (or it auto-builds). When finished:
- Web service > Settings > Domains > Generate Domain.
- Click "Open" to test.

## 5. Verify
Visit these endpoints on your live domain:
- `/backend/api/db_env_debug.php` → should show `onRailway: true` and internal host.
- `/backend/api/db_connection_test.php` → `{"success":true,"version":"..."}`
- `/frontend/admin/admin_login.html` (admin login page)

If you need to seed again on the live DB:
- `/backend/api/db_install.php` (idempotent; will skip existing tables)
- Or run seeds through the MySQL SQL console (paste contents of `backend/config/seed_medicines.sql`).

## 6. Optional Portal Page
Create a simple page linking all role login pages (admin/user/dentist/doc_nurse). Example file `frontend/portal.html` with links to each. (Can be added later.)

## 7. Common Issues
| Symptom | Cause | Fix |
|---------|-------|-----|
| 404 on `/frontend/...` | Wrong web root | Ensure start command uses `-t documed_pwa` |
| 502 / container crash | Missing PHP extension | Verify base image (Nixpacks) includes `pdo_mysql`; add a `composer.json` with `ext-pdo_mysql` requirement if needed |
| `{success:false}` DB error | Env vars not set in Web service | Add variable references and redeploy |
| Using public proxy host inside Railway | Only `MYSQL_PUBLIC_URL` present | Add internal `MYSQL_URL` reference |
| Password rotated in DB | Old creds cached | Redeploy Web service after updating vars |

## 8. Security Tips
- Do not commit real passwords or `.env` with secrets. Your local `.env` loader skips placeholders; production relies on Railway env vars.
- Disable `DEBUG_DB` after verification to reduce log noise.

## 9. Quick Rollback Plan
- Keep previous deploy (Railway keeps history). If new deploy breaks, hit "Rollback" to last good.
- Maintain schema migrations in separate SQL files; never edit live tables manually without a backup.

## 10. Next Improvements
- Add a small deployment CI (GitHub Actions) to auto-deploy main branch.
- Add a healthcheck route combining DB + basic app metrics.
- Implement seed toggles (`db_install.php?seed=medicines,reports`).

## 11. Manual Local Test Command
```powershell
php -S localhost:8084 -t documed_pwa
```
Then browse `http://localhost:8084/frontend/admin/admin_login.html`.

## 12. Removing Public URL Locally
If you only want internal behavior in dev (rare), unset `MYSQL_PUBLIC_URL` and use a local MySQL instead; otherwise keep the public proxy.

---
**Done:** After following these steps you should have a live domain like `https://your-service.up.railway.app/frontend/user/user_login.html` accessible and backed by your Railway MySQL.

---

## Deploy WITHOUT GitHub (Railway CLI)

If your code isn’t in GitHub yet, you can deploy directly from your local folder using the Railway CLI.

Prereqs on Windows:
- Node.js 18+ installed
- PowerShell

1) Install the CLI and log in

```powershell
npm i -g @railway/cli
railway login
```

2) From your project root (the folder that contains `Procfile`), link to your Railway project

```powershell
cd %USERPROFILE%\Documents\DocMed_2
# If you already created a Railway project in the Dashboard, link to it:
railway link
# Otherwise, create a new project and service:
# railway init
```

3) Make sure you have a Procfile (already added in this repo)

```
web: php -S 0.0.0.0:$PORT -t documed_pwa
```

4) Deploy from your local folder

```powershell
railway up
```

This uploads the current directory and triggers a build/deploy using Nixpacks.

5) Connect the Web service to your MySQL service

- Open the Railway Dashboard → Your Web service → Variables
- Click “Add Variable Reference” and select from the MySQL service: either `MYSQL_URL` OR the discrete `MYSQLHOST`, `MYSQLPORT`, `MYSQLUSER`, `MYSQLPASSWORD`, `MYSQLDATABASE`
- (Optional) Add `DEBUG_DB=1` to verify logs, then remove it later
- Redeploy if prompted

6) Assign a domain and test

- Web service → Settings → Domains → Generate Domain → Open
- Verify:
  - `/backend/api/db_env_debug.php` (env detection)
  - `/backend/api/db_connection_test.php` (DB connectivity)
  - `/frontend/user/user_login.html` (user login)

Notes:
- Composer dependencies will be installed automatically during build (thanks to `composer.json`).
- If you see a PDO MySQL error, add this to `composer.json` to force the extension at build time:

```json
{
  "require": {
    "ext-pdo_mysql": "*",
    "endroid/qr-code": "^6.0",
    "dompdf/dompdf": "^2.0",
    "phpmailer/phpmailer": "^6.9"
  }
}
```

Then redeploy with `railway up`.

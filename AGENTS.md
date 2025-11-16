# DocuMed PWA - Agent Guidelines

## Commands
- **Frontend Build**: `npm run build` (in documed_pwa/)
- **Frontend Dev**: `npm start` (in documed_pwa/) - runs on localhost:3000
- **Frontend Test**: `npm test` (in documed_pwa/)
- **PHP Backend**: Uses built-in PHP server or MAMP (localhost:3306)
- **Dependencies**: `composer install` for PHP, `npm install` for React

## Architecture
- **Backend**: PHP API in `backend/api/` with MySQL database (`db_med`)
- **Frontend**: Dual setup - React PWA (planned, in `src/`) + Traditional HTML/CSS/JS (active, in `frontend/`)
- **Database**: 5 tables (users, admins, patients, appointments, transactions) via `backend/config/db.php`
- **Authentication**: Session-based with localStorage, supports user/admin roles

## Code Style
- **PHP**: Snake_case variables, action-based routing (`?action=...`), PDO prepared statements, JSON responses
- **JavaScript**: camelCase variables, vanilla JS with fetch API, consistent error handling patterns
- **CSS**: Kebab-case classes, component-based styling (`.admin-layout`, `.user-layout`)
- **Security**: Password hashing, input validation, CORS headers, LocalStorage for sessions
- **Error Handling**: Try-catch blocks in PHP, `.catch()` in JavaScript, user-friendly messages

## File Structure
- `frontend/admin/` - Admin dashboard pages
- `frontend/user/` - Patient-facing interface  
- `backend/api/` - REST endpoints (auth, patient, appointment, report, qr)
- `backend/config/db.php` - Database connection

## Frontend JS Hygiene (2025-10-13)
- Marked several legacy/unreferenced scripts as deprecated and wrapped them in a no-op guard to avoid execution while keeping history:
	- `frontend/assets/js/user.js`
	- `frontend/assets/js/login.js`
	- `frontend/assets/js/manage_user.js`
	- `frontend/assets/js/dentist_appointments.js`
	- `frontend/assets/js/admin_login_handler.js`
	- `frontend/assets/js/admin_audit_trail.js`
	- `frontend/assets/js/medical_exam_report.js`
- To restore any file: remove the `if (false) { ... }` guard (if present), update as needed, and include it via a `<script src="...">` tag on the target HTML page.

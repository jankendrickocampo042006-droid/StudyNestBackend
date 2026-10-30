# StudyNest setup

StudyNest keeps the LavaLust PHP API and the Vite/Node.js frontend in separate folders:

- `app/`, `public/`, `scheme/`: LavaLust backend
- `frontend/`: Node.js frontend
- `database/studynest.sql`: MySQL schema with no seeded learning content

## Run locally with Laragon

1. Create/import the `studynest` database by running `database/studynest.sql` in MySQL or phpMyAdmin. The database starts empty; subjects, topics, lessons, and quizzes are created through the content manager.
2. Copy `.env.example` to `.env` in the project root and set the MySQL credentials. Point Laragon/Apache at this project so its URL is `http://localhost/StudyNest/LavaLust-main`.
3. Copy `frontend/.env.example` to `frontend/.env`. Update `VITE_API_PROXY_TARGET` if the backend URL differs from the example.
4. In a backend terminal, install the locked mail dependency:

   ```powershell
   cd C:\laragon\www\StudyNest\LavaLust-main
   composer install
   ```

   Configure `SMTP_USERNAME`, `SMTP_PASSWORD`, and `SMTP_FROM_EMAIL` in `.env` using a Google App Password before testing registration.
5. In the VS Code integrated terminal, run:

   ```powershell
   cd C:\laragon\www\StudyNest\frontend
   npm install
   npm run dev
   ```

   Keep that terminal running and open `http://localhost:4173`. Before starting the frontend, start Apache and MySQL from Laragon and make sure the backend `.env` and frontend `.env` files are configured as above.
6. For the production frontend, run `npm run build`; serve the generated `frontend/dist/` files using a static web server configured to proxy `/api` to the LavaLust backend.

The frontend always uses the LavaLust API; it has no local demo-data fallback. `VITE_API_PROXY_TARGET` points the Vite development proxy at LavaLust. `VITE_API_BASE_URL` is only for same-origin deployments where the frontend and API share an origin.

Email verification and password reset use Gmail SMTP through PHPMailer. Set `SMTP_USERNAME`, `SMTP_PASSWORD`, and `SMTP_FROM_EMAIL` in the backend `.env`. Use a Google App Password, not your usual Gmail password. Password reset sends a six-digit code that expires after 10 minutes and is limited to five guesses and five sends per day with a 60-second cooldown.

The SQL import is the simplest setup path. LavaLust migrations are disabled by default in `app/config/migration.php`. Migration `003_create_studynest_tables.php` creates the StudyNest schema; migration `004_create_user_activities.php` adds the activity table for existing installations.

## Add content

1. Register a user and sign in.
2. Promote that user to an administrator using MySQL/phpMyAdmin. There is no separate admin login; sign in with the same account after changing its role:

   ```sql
   UPDATE studynest.users
   SET role = 'admin'
   WHERE email = 'your-email@example.com';
   ```

   The existing `users.role` column and role-protected LavaLust endpoints control permissions. No schema change is needed just to promote the first administrator; the teacher-application fields are added separately below.

3. Sign out and back in. Administrators, moderators, and approved teachers land directly in **Manage learning content**; student dashboard, progress, study, and schedule navigation is hidden from their interface. They can still open profile settings or sign out. Create subjects first, then topics, lessons, and quizzes/questions. Lesson content may include an optional PDF (up to 20 MB); PDF files are stored outside the public directory and authenticated students can view or download them from the lesson page. Quiz questions support multiple choice, true/false, and identification. Identification answers are checked without case sensitivity and with surrounding whitespace ignored. These records are shared learning content stored in MySQL. Administrators and moderators use the same sign-in form as students; the database role controls access to the management workspace.
4. On the registration page, applicants can choose **Student** or **Teacher**. Both must verify their email with a six-digit code that expires after 10 minutes. Codes allow up to five guesses and are limited to five sends per day with a 60-second resend cooldown. Teacher applicants must take or upload a clear photo of their school-issued teacher ID (JPEG, PNG, or WebP; maximum 5 MB). Images are stored outside the public directory and served only to an administrator reviewing pending applications. Teacher access remains pending until an administrator approves the application; rejected applicants keep a student account.
5. To add a teacher account directly, sign in as an administrator and use **Teachers → Add teacher** in the management workspace. This admin-created route bypasses the application review. Teachers can manage subjects, topics, lessons, and quizzes, but cannot create or manage teacher accounts.
6. Students can register and create their own study sets, flashcards, favorites, schedules, and quiz attempts. Their dashboard, progress, activity timeline, and notifications are derived from database records.

### Existing database update for email verification and ID photos

The `database/studynest.sql` schema includes these columns for a fresh database. If teacher applications were already added by migration `006`, run migration `app/migrations/007_email_verification_and_teacher_id_photo.php` using the LavaLust migration runner, or apply the following statements once in phpMyAdmin. Active existing accounts are marked verified so they can continue to sign in. For installations that have not yet applied migration `006`, apply it first.

```sql
ALTER TABLE users
    ADD COLUMN teacher_id_photo VARCHAR(80) NULL,
    ADD COLUMN email_verified_at DATETIME NULL,
    ADD COLUMN email_otp_hash VARCHAR(255) NULL,
    ADD COLUMN email_otp_expires_at DATETIME NULL,
    ADD COLUMN email_otp_sent_at DATETIME NULL,
    ADD COLUMN email_otp_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN email_otp_resend_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN email_otp_resend_reset_at DATETIME NULL;

UPDATE users
SET email_verified_at = COALESCE(created_at, NOW())
WHERE email_verified_at IS NULL AND is_active = 1;
```

### Existing database update for lesson PDFs and identification questions

For an existing installation, run migration `app/migrations/009_lesson_pdfs_and_identification_questions.php` with the LavaLust migration runner, or apply the following statements once in phpMyAdmin. Migrations are disabled by default in `app/config/migration.php`; only enable them when intentionally running a migration, then disable them again:

```sql
ALTER TABLE lessons
    ADD COLUMN pdf_storage_name VARCHAR(80) NULL,
    ADD COLUMN pdf_original_name VARCHAR(180) NULL;

ALTER TABLE quiz_questions
    MODIFY type ENUM('multiple_choice', 'true_false', 'identification') NOT NULL,
    MODIFY correct_option VARCHAR(255) NOT NULL;
```

Set PHP's `upload_max_filesize` to at least `21M` and `post_max_size` to at least `22M` to permit the 20 MB lesson PDF limit, then restart Apache. Existing lessons remain unchanged and PDFs are optional.

For existing installations, also apply migration `app/migrations/008_add_password_reset_otp.php`, or add these fields once in phpMyAdmin:

```sql
ALTER TABLE users
    ADD COLUMN password_reset_otp_hash VARCHAR(255) NULL,
    ADD COLUMN password_reset_otp_expires_at DATETIME NULL,
    ADD COLUMN password_reset_otp_sent_at DATETIME NULL,
    ADD COLUMN password_reset_otp_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN password_reset_otp_send_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN password_reset_otp_send_reset_at DATETIME NULL;
```

## API

The API is mounted below `/api` and uses PHP session cookies plus a session CSRF token:

- Authentication routes are handled by `StudyNestAuthApi`; student learning and profile routes by `StudyNestLearningApi`; catalog and teacher administration routes by `StudyNestManageApi`. They share database and authorization helpers in `StudyNestApiBase`.
- The shared base loads LavaLust's Session library with `$this->call->library('session')`. Session settings are in `app/config/config.php`: file storage, `LLSession` cookie, 7,200-second server lifetime, and a 1,800-second inactivity timeout. Cookies are HttpOnly and SameSite Strict; set `cookie_secure` to `TRUE` when serving the application over HTTPS.
- The API now uses the configured LavaLust cookie instead of PHP's default session cookie, so existing sessions must sign in again once after upgrading.
- `GET /api/health`, `GET /api/bootstrap`
- `POST /api/auth/register`, `POST /api/auth/login`, `POST /api/auth/logout`
- `POST /api/auth/verify-email`, `POST /api/auth/resend-otp`
- `POST /api/auth/forgot-password`, `POST /api/auth/reset-password`
- `GET|PUT /api/profile`
- `GET|POST /api/study-sets`, `GET|PUT|DELETE /api/study-sets/{id}`
- `GET|POST /api/schedules`, `DELETE /api/schedules/{id}`
- `GET|POST /api/favorites`, `DELETE /api/favorites/{lessonId}`
- `POST /api/lessons/{lessonId}/review`
- `GET /api/lessons/{lessonId}/pdf` (authenticated PDF viewing; add `?download=1` to download)
- `GET /api/quizzes/{id}`, `POST /api/quizzes/{id}/attempts`
- Admin/teacher content management: `/api/manage/subjects`, `/api/manage/topics`, `/api/manage/lessons`, `/api/manage/quizzes`
- Admin-only teacher accounts: `GET|POST /api/manage/teachers`
- Admin-only teacher application review and ID image: `GET /api/manage/teacher-applications`, `GET /api/manage/teacher-applications/{id}/photo`, `PUT /api/manage/teacher-applications/{id}`

Study-set writes include all cards in the same transaction. Quiz scores and correct answers are calculated by the backend; answer keys are not included in quiz questions sent to the browser.
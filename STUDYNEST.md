# StudyNest setup

StudyNest keeps the LavaLust PHP API and the Vite/Node.js frontend in separate folders:

- `app/`, `public/`, `scheme/`: LavaLust backend
- `frontend/`: Node.js frontend
- `database/studynest.sql`: MySQL schema with no seeded learning content

## Run locally with Laragon

1. Create/import the `studynest` database by running `database/studynest.sql` in MySQL or phpMyAdmin. The database starts empty; subjects, topics, lessons, and quizzes are created through the content manager.
2. Copy `.env.example` to `.env` in the project root and set the MySQL credentials. Point Laragon/Apache at this project so its URL is `http://localhost/StudyNest/LavaLust-main`.
3. Copy `frontend/.env.example` to `frontend/.env`. Update `VITE_API_PROXY_TARGET` if the backend URL differs from the example.
4. In a backend terminal, install the locked PHP dependencies:

   ```powershell
   cd C:\laragon\www\StudyNest\LavaLust-main
   composer install
   ```

   Configure `SMTP_USERNAME`, `SMTP_PASSWORD`, and `SMTP_FROM_EMAIL` in `.env` using a Google App Password before testing email verification.
5. In the VS Code integrated terminal, run:

   ```powershell
   cd C:\laragon\www\StudyNest\frontend
   npm install
   npm run dev
   ```

   Keep that terminal running and open `http://localhost:4173`. Before starting the frontend, start Apache and MySQL from Laragon and make sure the backend `.env` and frontend `.env` files are configured as above.
6. For the production frontend, run `npm run build`; serve the generated `frontend/dist/` files using a static web server configured to proxy `/api` to the LavaLust backend.

The frontend always uses the LavaLust API; it has no local demo-data fallback. `VITE_API_PROXY_TARGET` points the Vite development proxy at LavaLust. `VITE_API_BASE_URL` sets the backend origin for a split deployment; leave it empty when the API is served from the same origin. A trailing `/api` is normalized automatically.

## Google sign-in

Google sign-in uses Google Identity Services in the frontend and verifies the returned ID token in the PHP backend. To enable it locally:

1. In Google Cloud Console, create a project, configure the OAuth consent screen as **External**, and add your Google account as a test user if the app is in Testing.
2. Create an OAuth client ID with application type **Web application**. Add both development origins as **Authorized JavaScript origins**:
   - `http://localhost:4173`
   - `http://127.0.0.1:4173`
   No redirect URI or client secret is needed for this sign-in button.
3. Copy the OAuth client ID (not a client secret) into both local environment files:
   - `frontend/.env`: `VITE_GOOGLE_CLIENT_ID=your-client-id`
   - backend `.env`: `GOOGLE_CLIENT_ID=your-client-id`
4. Restart the Vite dev server and Apache/PHP after editing environment files.
5. Open StudyNest using one of the exact origins above and choose **Continue with Google**. A new Google account creates a verified student account. Existing StudyNest accounts with the same verified email sign in with their existing role.

The client ID is public configuration; never put an OAuth client secret in the frontend. The backend verifies the ID token signature, expiry, issuer, and audience before creating a session. Google sign-in confirms the email address, so it does not send the separate StudyNest email-verification OTP.

Email verification and password reset use SMTP through PHPMailer. Set `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, and `SMTP_FROM_EMAIL` in the backend `.env` or the Render backend service's Environment settings. `SMTP_TIMEOUT` defaults to 10 seconds. Gmail uses ports 587/465, which Render Free web services block; to keep PHPMailer, use an SMTP relay that supports STARTTLS on a permitted port such as 2525, and verify the sender address/domain with that provider. If using Gmail, upgrade the Render web service to a paid plan that permits SMTP. Never use your normal Gmail password; use a Google App Password. Password reset sends a six-digit code that expires after 10 minutes and is limited to five guesses and five sends per day with a 60-second cooldown.

The SQL import is the simplest setup path. LavaLust migrations are disabled by default in `app/config/migration.php`, so deploying PHP code does not apply schema changes. Migration `003_create_studynest_tables.php` creates the StudyNest schema; migration `004_create_user_activities.php` adds the activity table for existing installations. Migration `011_persist_teacher_id_photos.php` adds private `MEDIUMBLOB` storage for teacher ID images, so they survive Render deploys and restarts. Migrations `012_repair_teacher_application_schema.php` and `013_repair_account_registration_schema.php` repair partially migrated teacher-review, image, and email-verification schemas.

Study sets can use an existing topic or a personal topic with an uploaded PDF. The PDF is stored as a private `LONGBLOB` in MySQL, so it remains available across local and Render deployments and is served only to the owning user. New installations include these fields in `database/studynest.sql`; existing databases should run `database/repair_teacher_application_schema.sql` before deploying this feature (or apply migration `014_add_study_set_pdf_storage.php`). The upload limit is 20 MB; Laragon PHP should have `upload_max_filesize=22M` and `post_max_size=24M`, while the Render Docker image sets these limits automatically.

For an existing local or Render database, select the StudyNest database and run `database/repair_teacher_application_schema.sql` once in phpMyAdmin or the database SQL console before deploying the matching backend. The script is safe to re-run and adds only missing columns/indexes. Verify that `users.teacher_id_photo_data` exists as `MEDIUMBLOB`. Older pending applications whose image was previously stored only on Render's ephemeral filesystem cannot be recovered; after repairing the schema, the applicant must sign in and upload the ID photo again from their pending-application page.

Render uses a disposable, instance-local filesystem, so PHP file sessions may be lost after a restart or unavailable when requests reach another instance. After running the repair SQL (which creates the shared `sessions` table), set `SESSION_DRIVER=database` in the Render backend environment and redeploy. The backend then stores session data in MySQL across restarts and instances. The default inactivity timeout is two hours; session cookies expire after two hours as well.

Subjects belong to the teacher who creates them; their topics, lessons, and quizzes inherit that ownership. Teachers can only view/manage their own content, while admins and moderators can manage all content and the student catalog remains shared. Admins can view approved teacher accounts and registered student accounts in their workspace. For existing databases, apply migration `010_subject_ownership.php` before deploying these API changes. It also assigns old subjects from matching subject-creation activity records when available; any remaining unassigned subjects are admin-managed until assigned.

If applying the ownership migration manually to an existing MySQL database, run the schema update once. The backfill query requires the `user_activities` table from migration `004`; omit that query if the table is not present.

```sql
ALTER TABLE subjects
    ADD COLUMN owner_id INT UNSIGNED NULL AFTER description,
    ADD INDEX subjects_owner_idx (owner_id),
    ADD CONSTRAINT subjects_owner_fk
        FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL;

UPDATE subjects s
JOIN (
    SELECT title, MAX(id) AS activity_id
    FROM user_activities
    WHERE activity_type = 'content_created' AND details = 'Subject'
    GROUP BY title
) latest ON latest.title = s.name
JOIN user_activities activity ON activity.id = latest.activity_id
SET s.owner_id = activity.user_id
WHERE s.owner_id IS NULL;
```

Subjects without a matching creation activity remain unassigned. An admin can assign one by setting `subjects.owner_id` to the owning teacher's user ID.

For an existing database, apply the teacher ID photo storage update before deploying:

```sql
ALTER TABLE users
    ADD COLUMN teacher_id_photo_data MEDIUMBLOB NULL AFTER teacher_id_photo;
```

New teacher ID photos are stored in this private database column instead of Render's ephemeral local filesystem. Existing pending applicants whose photo file was lost during an earlier deploy can sign in and upload the image again from their application page.

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
4. On the registration page, applicants can choose **Student** or **Teacher**. Both must verify their email with a six-digit code that expires 15 minutes after the mail server accepts it. Codes allow up to five guesses and are limited to five successfully sent codes per 24 hours with a 60-second resend cooldown. Teacher applicants must take or upload a clear photo of their school-issued teacher ID (JPEG, PNG, or WebP; maximum 5 MB). Images are stored outside the public directory and served only to an administrator reviewing pending applications. Teacher access remains pending until an administrator approves the application; rejected applicants keep a student account.
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
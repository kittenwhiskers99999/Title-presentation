# CFS-SIS — Calabrian Formation School (website + family portal + registrar)

Plain HTML/CSS/JS front end (index.html, styles.css, script.js) talking to a PHP 8 + PDO + MariaDB API (api/).
No frameworks. Local XAMPP demo.

## Run it
1. Copy this whole folder to `C:\xampp\htdocs\cfs-sis`
2. XAMPP Control Panel: start **Apache** and **MySQL**
3. http://localhost/phpmyadmin -> **Import** tab (no database selected) -> choose `database/cfs_sis_mariadb.sql` -> Import.
   WARNING: this DELETES any existing `cfs_sis` database and rebuilds it (MariaDB 10.3+ needed).
4. Open **http://localhost/cfs-sis/**

Needs PHP 8.0+ with the `fileinfo` extension on (XAMPP has it on by default).

## Accounts
- Parent: click *Enroll Now* -> Create Account (any email, 8+ character password)
- Registrar: footer/top bar **Faculty & Staff** (the sign-in form is pre-filled)
  `registrar@calabrianfs.edu.ph` / `registrar123`

## 5-minute demo script
1. Create a parent account -> Enroll **New Student - Grade 1** -> fill the 4 steps (upload any JPG/PNG/PDF) -> Submit. No payment yet.
2. Portal shows *Submitted*; **Billing & Payments** says the invoice comes after approval.
3. Request a screening (Kindergarten or Transferee) from the portal.
4. Sign out. **Faculty & Staff** -> sign in. Open the application: Verify each document, then **Approve**: this sends the invoice (creates the fees). Try *Flag for follow-up* first to see the family-facing remark.
5. Sign back in as the parent: status is *Approved* and Billing now shows the invoice. Pay with any 13-digit GCash reference + a JPG/PNG proof; the registrar verifies it from the same drawer.

## Folder map
- `index.html`, `styles.css`, `script.js` - the whole UI (views switch client-side, data comes from the API)
- `api/auth` register, login, logout, me
- `api/enrollment` submit, status, mine (the guardian's applications)
- `api/billing` pay, history
- `api/uploads` document, file (the only way to view an uploaded file)
- `api/screening` request (kindergarten / transferee)
- `api/registrar` list, detail, approve, reject, request_revision, verify_document, verify_payment, screening
- `api/students` list
- `config/` your database/helpers/auth/registrar/uploads files (DB login: root, empty password)
- `database/cfs_sis_mariadb.sql` full schema + seed (2026-2027 school year, fee schedule, demo registrar)
- `storage/` created on first upload; direct web access is blocked by an .htaccess it writes

## Behaviour worth knowing
- New students are always Grade 1; continuing students pick Grade 2-6. Kindergarten and transferees use the screening request (registrar sees them in the dashboard).
- Approve is blocked until the PSA birth certificate, report card and 2x2 photo are verified. Fees (the invoice) are created at approval; rejected applications never get one, and payments are refused until approval. The registrar can override with a written remark (recorded in `audit_logs`).
- *Flag for follow-up* sets "Needs revision": the parent sees the remark and can replace documents. *Reject* is final.
- Payments: a GCash reference can only be used once; payments count toward the balance only after the registrar verifies them.

## Known limits (demo scope)
- News, events, announcements and the program pages on the public site are static text (the `content_posts` table exists but is not wired up).
- The GCash number and the fee breakdown shown in wizard step 4 are static display text; the amount actually charged comes from the database fee schedule.
- "Receipt" = a link to the uploaded payment proof; no receipt PDFs are generated.
- The dashboard loads the 100 newest applications.
- Chatbot tables exist; the chatbot itself is not built yet.
- Session cookie is not marked `secure` (needs HTTPS in production).

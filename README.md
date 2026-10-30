# Real Estate Listing Platform

A server-rendered PHP/MySQL real estate marketplace with property search, listing management, buyer inquiries, appointments, favorites, and an admin area. Some workflows exist in source but are not fully verified. Payment processing is currently disabled because the previous success-return implementation did not verify payments with Stripe.

## Project Objectives

- Let users browse property sale and rental listings.
- Support buyer inquiries, offers, favorites, appointments, and rental-interest requests.
- Support property management pages for Agent accounts.
- Provide administrative pages for user, listing, and transaction management.
- Search and compare available listings through a lightweight PHP property assistant.

## Feature Status

| Feature | Status |
| --- | --- |
| Homepage discovery and live inventory counts | Implemented from database listings; browser-checked at desktop widths 1280px, 1366px, and 1920px |
| Property filters, sort, pagination | Prepared GET filters for type, location, minimum/maximum price, allowlisted sorting, and count-based pagination; location/type/budget/sort queries verified against local DB |
| Property details and offers | Database-backed gallery and role-aware contact; two images rendered; authenticated offer persisted and verified in MySQL, then cleaned up |
| Buyer offers, favorites, appointments | Offer, favorite add/remove, and appointment persistence were verified with isolated temporary accounts; those records were removed afterward |
| Rental-interest and Agent review workflow | Present; database-backed workflow not tested in this environment |
| Agent property create/edit/images | Agent dashboard and owner-scoped listing management are implemented; new or edited listings await Super Admin approval. |
| Agent property workflow | Agent dashboard, listing management, and pending offer decisions are limited to listings owned by the logged-in Agent; Agents can also view appointments assigned by a Super Admin. |
| Agent demo inventory | Repeatable CLI seeder adds 100 clearly labeled available listings for existing Agent ID 63; rerun verified idempotent. |
| Buyer, Agent, and Super Admin authentication | All roles sign in with email/password and an explicit role choice. Registration stores a phone number without phone verification. |
| Shared login and logout | Implemented and locally smoke-tested against XAMPP/MySQL; logout and protected-page redirect verified |
| Super Admin dashboard | Distinct navy-and-gold console for user/role management, listing moderation, appointment assignment, payment monitoring, reports, and analytics. |
| Public marketplace and auth design | Shared desktop design system applied to homepage, listings, details, login, registration, header, and footer; legacy account/admin pages still need redesign |
| Property assistant | PHP/MySQL chat widget supports natural-language listing filters, recommendations, listing Q&A/comparisons, and confirmed appointment requests without an external AI service |
| Stripe payments | Disabled. Checkout and return endpoints respond with HTTP 503; no payment can be completed through the application |
| Automated tests | No automated test suite was found |

## Changes in This Pass

- Replaced Seller-facing registration/login/dashboard routes with Agent functionality while preserving seller-owned property, offer, and payment records through an explicit role migration.
- Removed phone verification from registration and login; phone numbers are stored as account details. Email OTP password reset remains optional.
- Added Super Admin role gates, account activation controls, property approval/rejection, appointment assignment, payment monitoring, and reports.
- Added shared password-verifying login and logout endpoints.
- Disabled unverified Stripe checkout and success-return routes and removed direct payment-completion handlers from property details.
- Replaced generated-SQL chatbot execution with validated JSON search filters and a parameterized listing query.
- Added a session-based PHP property assistant using parameterized MySQL queries, actual listing data, and the existing pending appointment workflow.
- Added an Agent dashboard section for reviewing and accepting or rejecting pending sale offers on owned available listings.
- Added Buyer/Agent dashboard themes and role-scoped dashboards; hardened property editing and image removal, converted property deletion to CSRF-protected POST, and parameterized reset-token queries.
- Added this initial audit and refreshed setup/status documentation.

## Role Capabilities

- **Buyer:** sign in with email and password; browse/search approved listings, save favorites, submit offers, express rental interest, request appointments, and review recorded activity. Phone numbers are stored without verification.
- **Agent:** sign in with a password; manage listings linked to that account, which require approval before public display; review pending offers on owned sale listings; and view related inquiries/appointments.
- **Super Admin:** sign in with email and password through the admin portal; manage Buyer/Agent status and roles, add/edit/deactivate properties, approve/reject listings, assign Agents to appointments, review users and reported properties, monitor recorded bookings/payments, and view reports and analytics.

Super Admin provisioning is intentionally not exposed as a public form. The legacy admin registration page remains disabled. Existing Admin accounts are converted to `super_admin` by the migration; new Super Admin accounts must be provisioned by a trusted database operator with a PHP `password_hash` value. The Super Admin console uses a distinct navy-and-gold theme. Removing a property places it on hold so related history is retained.

## Role Themes

A shared stylesheet defines Buyer and Agent visual themes, and the Super Admin console has its own navy-and-gold theme. Registration offers Buyer and Agent only; Super Admin access cannot be self-registered.

Only Agents and Super Admins have dashboards: `agent_dashboard.php` and `admin/dashboard.php`. Buyers return to the marketplace after signing in and use the marketplace navigation for favorites, transactions, appointments, and account details. `seller_dashboard.php` redirects to the Agent dashboard for compatibility. Protected admin pages share a server-side Super Admin guard; Agent listing controls remain scoped by the original `seller_id` ownership field.

## AI Feature Status

The buyer-facing chat widget posts to `chatbot_api.php`. PHP parses common search phrases and uses parameterized queries against approved, available listings. It supports chat-session preferences, factual questions/comparisons, and appointment requests that require a logged-in Buyer, date/time confirmation, conflict checks, and a pending database record. It does not access favorites or call a paid AI provider. Responses are limited to information available in listing records; building style and parking are not stored as dedicated fields. `chatbot_server.py` is an older optional Gemini implementation and is not required by the PHP widget.

## Technology and Architecture

- PHP server-rendered pages using `mysqli`.
- MySQL/MariaDB database; the supplied SQL dump targets `realestatephp` and was generated with MariaDB 10.4.32.
- Composer dependencies: `stripe/stripe-php` 17.1.1 and `firebase/php-jwt` for server-side Firebase ID-token verification, locked in `composer.lock`.
- Static HTML/CSS/JavaScript, Bootstrap assets, and Font Awesome.
- A legacy optional Flask/Gemini chatbot remains in the repository but is not used by the PHP property assistant.
- PHPMailer is used by appointment email workflows. Registration no longer needs SMTP or OTP.

There is no frontend build step, API framework, or migration framework. PHP pages and the property assistant connect directly to the database.

## Project Structure

```text
admin/                 Admin pages and assets
css/, js/, images/     Frontend assets
include/               Shared header/footer and PHPMailer
DATABASE FILE/         MariaDB SQL dump and migrations
config.php             PHP database connection
secrets.example.php    Placeholder PHP secrets
chatbot_api.php        PHP/MySQL property assistant endpoint
include/chatbot-widget.php  Buyer-facing chat widget
composer.json          PHP dependency manifest
*.php                  Public pages and workflow handlers
TEST_REPORT.md         Executed checks and known blockers
```

## Prerequisites

Verified on the current development machine:

- PHP CLI 8.2.12 (XAMPP installation).
- Composer 2.10.3.
- The SQL dump identifies MariaDB 10.4.32. Use a compatible MySQL/MariaDB server through XAMPP.
- Apache and MySQL from XAMPP for local web/database access.
- PHP with the `mysqli` and `mbstring` extensions is required for the property assistant; no Python runtime or AI API key is needed.

Stripe and SMTP credentials are not required for browsing, registration, login, the property assistant, or the currently disabled payments. SMTP settings remain necessary if appointment email delivery is used.

## Windows Setup (XAMPP)

1. Install XAMPP with Apache and MySQL, Composer, and Git. Start Apache and MySQL from the XAMPP Control Panel.
2. Clone the supplied repository into XAMPP's `htdocs` directory:

   ```powershell
   Set-Location C:\xampp\htdocs
   git clone https://github.com/nithin-jella/AI-powered-real-estate-listing-platform.git
   Set-Location .\AI-powered-real-estate-listing-platform
   ```

   The current workspace may instead be located at `C:\xampp\htdocs\AWS-based-Realestate-project`; use the folder you actually have.

3. Install PHP dependencies:

   ```powershell
   composer install
   ```

4. Create the database in `http://localhost/phpmyadmin` with the name `realestatephp`, using `utf8mb4` where available. Select that database, choose **Import**, and import `DATABASE FILE/realestatephp (3).sql`.

   Fresh imports include unique `(user_id, property_id)` favorite and `user.email` constraints. For an existing database, check for duplicate favorite pairs and duplicate email addresses, resolve any duplicates without deleting user data, then apply `DATABASE FILE/migrations/20261022_add_favorite_user_property_unique.sql` and `DATABASE FILE/migrations/20261023_add_user_email_unique.sql` once each.

   The dump contains example user/property records, including personal-looking names and email addresses. Treat it as sample data, do not use those accounts as real users, and remove or anonymize personal data before sharing a deployment.

   Back up the database, then apply `DATABASE FILE/migrations/20261024_super_admin_auth_and_property_review.sql` once. It converts stored Admin roles to `super_admin` and Seller roles to Agent without changing user IDs or property/payment ownership; it also adds account status, legacy phone/OTP fields, listing-review, and property-report fields. It marks existing listings approved and leaves new listings pending by default. Phone verification fields are not used by registration or login.

   The supplied SQL dump already includes the appointment Agent-assignment column and index. For an existing database imported before that change, apply `DATABASE FILE/migrations/20261024_add_appointment_agent_assignment.sql` once. Do not apply that migration after importing the current dump.

5. PHP reads `DB_HOST`, `DB_USER`, `DB_PASSWORD`, and `DB_NAME` from the PHP server process environment. If unset, defaults are `localhost`, `root`, empty password, and `realestatephp` for local XAMPP. Configure overrides in the Apache environment when needed; `admin/config.php` reuses the shared connection. Do not use root/no-password credentials outside a disposable local environment.
6. Optional email delivery for password-reset codes and appointment notifications requires SMTP settings `MAIL_USERNAME`, `MAIL_APP_PASSWORD`, and `APP_SEND_EMAIL=true` in the Apache/PHP environment. Email delivery is not required for registration or sign-in. Never commit real secrets.
7. Open the site using the folder name you cloned:

   ```text
   http://localhost/AI-powered-real-estate-listing-platform/
   ```

   For this workspace's existing folder, use `http://localhost/AWS-based-Realestate-project/`.

## Linux/macOS Setup

Install a compatible PHP runtime with `mysqli`, a MySQL/MariaDB server, Apache (or another PHP-capable web server), Composer, and Git. Clone the repository into the web server document root, then run `composer install`. Create the `realestatephp` database and import the same SQL dump using phpMyAdmin or a local MySQL/MariaDB client. Update both PHP config files if the database host/user/password differs. The application has not been tested on Linux/macOS; commands, document-root paths, and service names depend on the chosen distribution.

The property assistant requires no additional service, Python installation, or AI provider credentials.

## Example Property Inventory

The one-time example seeder is available at `scripts/seed_agent_listings.php` and requires an active Agent login plus a CSRF-protected POST. Open `http://localhost/<project-folder>/scripts/seed_agent_listings.php` while signed in as the Agent who should own the listings. It creates up to 200 unique Bengaluru-area `Sale` and `Rental` examples for that session's database user, uses existing local image assets, and is safe to rerun. No account ID is configured in the script.

Every seeded title is marked `EXAMPLE LISTING` and its description says the property, address, amenities, price, and availability are unverified fictional placeholders. These examples are approved and visible in the marketplace for local demonstration only; replace them with genuine, reviewed listings before public use. The database schema remains unchanged.

The former `DEMO - Seed30 -` inventory can be cleaned up with `php scripts\cleanup_demo_properties.php`. This transaction deletes only explicitly marked demo listings without favorites, appointments, offers, rental inquiries, or rental contracts; it retires activity-linked demo listings by setting them on hold and preserves their history. Other sample-like listings are left for manual review.

## Authentication and Account Setup

Public registration creates Buyer or Agent accounts with email, password, and a stored phone number; phone verification is not part of registration or login. Login uses email/password and requires the user to select the matching role. Email OTP password reset is available when SMTP is configured. Super Admin access is selected on the dedicated admin login portal; Super Admin accounts are provisioned by a trusted database operator and cannot self-register.

Legacy Firebase/OTP database fields and migrations may remain for compatibility with older installations, but current registration and sign-in do not use them. The legacy support-workflow migration is no longer required; existing ticket records and tables are left untouched. Install PHP dependencies with `composer install`.

Sessions are regenerated after successful login. The migration adds `is_active`; protected role checks re-read both account status and role so deactivated accounts and changed roles lose stale session access. All existing admin endpoints route through the Super Admin-only admin database configuration.

Agent appointment, offer, and rental-inquiry decisions require a session CSRF token and constrain updates to an owned property or an appointment assigned to that agent. Super Admins can assign/reassign appointments. Property edits and new listings return to pending review; the marketplace only exposes approved listings. Removing a listing places it on hold instead of deleting the property or related booking, payment, favorite, and report history. Existing projects/builders are not represented in the supplied schema and are not fabricated. Property reports use a new table and Super Admin review page.

## Payment Gateway

Stripe SDK 17.1.1 is present, and placeholder `STRIPE_SECRET_KEY` remains in `secrets.example.php`. Sale and rental checkout are disabled with HTTP 503. The previous return handlers treated query-string redirects as proof of payment and updated database records without Stripe verification; these handlers were removed from the payment path. No webhook, idempotency store, or tested provider workflow is implemented. No payment was made or marked successful by this revision.

Do not configure live keys or collect money through this application. Re-enable payments only after defining sale/rental business terms, implementing server-created orders and verified webhooks, and adding test-mode coverage for signatures, duplicate events, failures, and ownership.

## API and Application URLs

- PHP website: `http://localhost/<project-folder>/`
- Agent dashboard: `agent_dashboard.php` (Agent only)
- Super Admin dashboard: `admin/dashboard.php` (Super Admin only)
- Register/login: `register.php` and `login.php`; all roles use email/password, with a role selector at sign-in. The Admin portal is available at `login.php?portal=admin`.
- phpMyAdmin: `http://localhost/phpmyadmin`
- Property assistant: open the floating chat control on a public or Buyer page; chat requests are handled by `chatbot_api.php`.
- No separate REST API documentation or frontend dev server is included.
- Stripe checkout and success-return URLs are intentionally unavailable and return HTTP 503.

## Testing and Quality Checks

From PowerShell in the project directory, syntax-check PHP files with:

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```

There is no configured PHPUnit or automated integration test suite. Syntax checks can be run with the PowerShell command above. Historical baseline results are recorded in [TEST_REPORT.md](TEST_REPORT.md); they predate the latest Admin console changes. Run database-backed authorization checks after applying the appropriate migrations.

Additional local checks: unauthenticated property editing returned HTTP 403, and all four sale/rental checkout and payment-return URLs returned HTTP 503. No payment was attempted.

## Troubleshooting

- **Apache or MySQL is unavailable:** start both in the XAMPP Control Panel, then confirm the project directory is under `C:\xampp\htdocs` and browse the matching folder URL.
- **Database connection fails:** start MySQL in the XAMPP Control Panel and verify it is listening on the configured host/port (3306 by default). Check that the `realestatephp` database was created/imported and that the PHP server process has correct `DB_HOST`, `DB_USER`, `DB_PASSWORD`, and `DB_NAME` values. Unset values default to XAMPP `root` with an empty password. When MySQL is unavailable, the site returns a generic database-unavailable message; check the Apache/PHP error log for details.
- **Composer reports missing PHP extensions:** enable `mysqli`, `curl`, `json`, `mbstring`, and `openssl` in the PHP configuration used by Composer and Apache, then restart Apache and rerun `composer install`.
- **Property assistant is unavailable:** ensure Apache/PHP and MySQL are running, the project database is configured, and the PHP `mysqli` and `mbstring` extensions are enabled.
- **Checkout returns HTTP 503:** this is intentional. Razorpay was not enabled because no payment workflow was selected; sale and rental payments remain disabled.

## Security and Known Limitations

- The included SQL dump contains sample records. Never expose it as production data.
- PHP database configuration supports process environment overrides and logs connection failures without exposing raw details, but still defaults to XAMPP root/empty-password credentials. Production requires explicit least-privileged credentials and protected server-side logs.
- Public registration supports Buyer and Agent only; Super Admin accounts require trusted provisioning.
- Phone numbers are stored but not verified; account authentication uses email and password.
- Property edit and deletion check ownership; deletion and Agent appointment, offer, and rental-inquiry actions require POST plus a session CSRF token. Remaining legacy mutations still need review.
- Payments are disabled. No Razorpay transaction or live payment capability is enabled; introduce a defined booking-fee workflow before adding checkout.
- The public home and listing pages were checked at mobile and desktop viewport widths. Several dashboards and legacy account pages remain outside this visual audit.
- Chatbot source uses a safe filter allowlist and parameterized property query, but provider behavior, rate limiting, and deployment hardening remain unverified.

## Deployment Notes

This repository is configured for local development, not production deployment. Before deployment, apply the required migrations to a backed-up database, provision a Super Admin securely, configure/test email delivery if needed, use TLS and least-privileged database accounts, remove sample data, review all role/ownership checks and uploads, add automated tests and logging, and keep payment endpoints disabled until a verified provider integration is complete.

## MyDatabase
<img width="1898" height="938" alt="image" src="https://github.com/user-attachments/assets/2485f971-4771-402c-8106-3fe3d3594315" />

##  My Home page
<img width="1911" height="902" alt="image" src="https://github.com/user-attachments/assets/928496f3-0e42-4e3b-b57b-60324335ee84" />

##  Sign up page
<img width="837" height="782" alt="image" src="https://github.com/user-attachments/assets/de4a1e40-1b44-44e0-bd35-9858013dbb80" />

## Login page
<img width="915" height="736" alt="image" src="https://github.com/user-attachments/assets/73876abc-24a7-41b4-a0ed-f619c78dd262" />

## Buyer account
<img width="813" height="887" alt="image" src="https://github.com/user-attachments/assets/0bf8e008-6a33-48fc-9289-9f68c1f11ef2" />
<img width="828" height="580" alt="image" src="https://github.com/user-attachments/assets/eeff541f-6da3-43c3-a064-29b69f5de045" />
<img width="791" height="345" alt="image" src="https://github.com/user-attachments/assets/2cc3ad0b-470c-4f16-88d4-991ad80f2591" />
<img width="838" height="841" alt="image" src="https://github.com/user-attachments/assets/e19f365d-8628-4060-b409-309d632ef0fd" />

## Agent account
<img width="782" height="492" alt="image" src="https://github.com/user-attachments/assets/80fc8e95-d4a4-4b70-b61a-ff452f075b11" />
<img width="916" height="853" alt="image" src="https://github.com/user-attachments/assets/0fa26445-f117-486b-8c61-978f72be73f3" />
<img width="887" height="842" alt="image" src="https://github.com/user-attachments/assets/389ad120-06ee-4ac6-ab40-03ee52a39e92" />
<img width="846" height="832" alt="image" src="https://github.com/user-attachments/assets/24a2bd68-f25e-49eb-b965-70f54cdc80ae" />
<img width="592" height="827" alt="image" src="https://github.com/user-attachments/assets/3730b0b0-7c59-4b1b-9124-d5473df1ca68" />














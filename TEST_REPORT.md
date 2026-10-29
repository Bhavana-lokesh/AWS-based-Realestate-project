# Test Report

Date: 2026-10-23  
Environment: Windows/XAMPP, Apache and MySQL reachable on localhost, PHP CLI 8.2.12, Composer 2.10.3. No dedicated PHPUnit or browser E2E suite is configured. The local `realestatephp` database was used; isolated test accounts and rows were removed.

## Results

**Passed: 28 | Failed: 0 | Not tested: 5**

| # | Area | Result | Evidence |
| --- | --- | --- | --- |
| 1 | Local startup/runtime | **Passed** | PHP CLI 8.2.12 and Composer 2.10.3 available; Apache port 80 and MySQL port 3306 reachable. Composer validation passed with only the existing missing-license-field warning. Home, listing, registration, login, and property-detail routes returned HTTP 200. |
| 2 | Application DB connection and schema | **Passed** | `config.php` connected to `realestatephp`; core tables use InnoDB and expected foreign keys were present. Final state after demo seeding: 11 users, 38 listings (36 available), 4 favorites, 2 offers, 1 appointment, 4 payment rows, 15 rental contracts, and 4 rental-interest rows. |
| 3 | Email uniqueness migration | **Passed** | No duplicate email groups existed when the migration was applied; `unique_user_email` was added and verified. |
| 4 | PHP syntax | **Passed** | Final `php -l` passed for all 81 application PHP files outside `vendor/`, including the seeder. |
| 5 | Home UI and responsive layout | **Passed** | Browser rendered 4 available listing cards. Checks at requested 375, 768, 1280, and 1920 viewport widths found no horizontal document overflow; no console errors surfaced on tested home/list pages. |
| 6 | Search/filter integration | **Passed** | `property.php?type=Rental&city=Houston` returned HTTP 200 and exactly 3 matching cards; all rendered listing images loaded. |
| 7 | Registration | **Passed** | App registration accepted an isolated account, stored a password hash, and assigned Buyer despite a forged `role=Admin` field. A repeated email received the duplicate-email message. Account and audit record were removed. |
| 8 | Seller create | **Passed** | Authenticated Seller created a Sale listing through the form; the listing was read back from MySQL with the expected owner and `available` status. |
| 9 | Seller edit/persistence | **Passed** | Seller edited the listing through the form. The changed title and price were verified in MySQL on a subsequent request. |
| 10 | Seller delete | **Passed** | Seller deleted the isolated listing through the CSRF-protected POST action; a database read confirmed it was gone. Test seller and related rows were removed. |
| 11 | Seller-page ownership | **Passed** | Each seller control page returned 200 for the owner and 403 for a different owner. A malformed property ID returned 400. |
| 12 | Seller action CSRF and record scope | **Passed** | Missing tokens returned 403. Valid tokens paired with another property’s offer, appointment, or rental-interest ID returned 404; all target records remained Pending and all properties remained available. |
| 13 | Payment fail-closed behavior and test cleanup | **Passed** | All four checkout/return endpoints returned HTTP 503. No payment was attempted. Isolated HTTP-test fixtures were removed; baseline row counts were restored. |
| 14 | Buyer sale offer | **Passed** | Registered and signed in an isolated Buyer, submitted a CSRF-protected offer on property 38, then reloaded the detail page and verified the Pending status. |
| 15 | Buyer favorites | **Passed** | Saved property 38, verified it on Saved Properties, removed it, and verified the empty state. |
| 16 | Buyer rental interest | **Passed** | Submitted an inquiry for available rental property 32 and reloaded the detail page to verify Pending status. |
| 17 | Buyer appointment request | **Passed** | Submitted a future appointment for property 38; the confirmation response and Pending database row were verified. SMTP was disabled. |
| 18 | Three-role registration and login | **Passed** | Buyer, Seller, and Agent registration stored the selected allowed role; login authenticated from the database role and redirected each account to its matching dashboard. Public role validation rejects values outside those three roles. |
| 19 | Role dashboard theme | **Passed** | Each role's dashboard rendered its matching `data-role` theme: Buyer blue, Seller green, Agent purple. |
| 20 | Dashboard authorization matrix | **Passed** | Each authenticated role received HTTP 200 for its own dashboard and HTTP 403 for the other two dashboards. |
| 21 | Seller listing CRUD after role checks | **Passed** | Fresh Seller account registered/logged in, created an owned listing, edited title/price/status to `hold`, verified database persistence, deleted the listing, and removed the isolated account. |
| 22 | Agent listing CRUD | **Passed** | Fresh Agent created a listing owned by that Agent, saw it in the managed dashboard, and deleted it; all temporary records were removed. No cross-owner access is inferred from Agent role. |
| 23 | Registration responsive layout | **Passed** | Browser checked 375, 768, and 1280 CSS-pixel viewport requests; the account selector rendered all three roles, the page had no horizontal overflow, and no console/page errors were captured. The browser harness reported effective viewport widths of 300, 614, and 1024 respectively. |
| 24 | Role-stage fixture cleanup | **Passed** | Temporary accounts/listings created by the role-flow HTTP tests were removed before demo seeding; unrelated existing accounts were left unchanged. |
| 25 | Seller private-page access | **Passed** | A logged-in Seller received HTTP 403 for Buyer and Agent dashboards, Favorites, and Transactions; the Seller dashboard rendered without a Favorites navigation link. |
| 26 | Agent ownership boundary | **Passed** | A logged-in Agent received HTTP 404 for an existing Seller-owned listing editor, and the Agent dashboard did not render that listing. |
| 27 | Composer validation | **Passed with warning** | `composer validate --no-check-publish` succeeded; the existing `composer.json` has no license field. |
| 28 | Administrator registration rejection | **Passed** | Submitted `account_type=admin` with a forged `role=Admin`; registration displayed the invalid-account-type error and no user row was created. |

## Not Tested / Remaining Blockers

| Area | Status and exact blocker |
| --- | --- |
| SMTP delivery | **Not tested.** Email is disabled by default. Configure `secrets.php` from `secrets.example.php` and set `APP_SEND_EMAIL=true` in the Apache/PHP process to run a test delivery. |
| Python chatbot | **Not tested.** `python --version` reports that the Windows App Execution Alias has no interpreter (exit 9009); no `py` launcher is present. Install Python, create the documented virtual environment, install `requirements.txt`, configure `.env` with a Gemini key and least-privileged DB account, then run `python chatbot_server.py`. |
| Payments | **Not tested.** Payments intentionally return HTTP 503. Do not use live keys or attempt charges. Provider order creation, signatures/webhooks, idempotency, transaction persistence, and sandbox tests remain unimplemented. |
| Image upload/removal and invalid-upload handling | **Not retested in the role implementation stage.** A prior report recorded one valid JPEG upload and a rental-contract insert as passing; invalid types, oversize files, and the new image-removal action remain untested. |
| Agent/Admin and all legacy-page responsive UI | **Not tested.** Role/theme routing was checked, but the visual browser pass covered the registration selector rather than every dashboard and legacy page. |

## Data and Safety Notes

- The live database contains pre-existing sample property records with poor-quality names and implausible dimensions. They were left unchanged.
- No payment was made or marked successful.
- Role-stage test fixtures were cleaned up. Existing user rows were not changed during the demo seed.
- No automated test runner or CI configuration was found in the project root.

## Example Inventory Update (2026-10-27)

**Passed:** The current example seeder at `scripts/seed_agent_listings.php` required the active Agent session and CSRF token, derived the owner from the server-side session (Agent ID `110`), and committed exactly 200 unique listings. The records have valid positive prices/areas, one existing local image each, `available` status, and approved review status: 160 sales and 40 rentals. A second submission added zero rows. The agent dashboard and My properties page both showed 200; the buyer browse page returned the examples for a Bengaluru locality search, and a seeded detail page returned HTTP 200 with the example disclosure.

**Passed:** The transactional cleanup removed 65 explicitly marked `DEMO - Seed30 -` listings and their 130 image rows because they had no user activity. The remaining 35 explicitly marked records were set to `hold` because they have appointments and/or rental-contract history; their records and activity were preserved. No marked old demo listings remain available in the buyer marketplace.

**Manual review:** Listings titled `test` (property 36), `seller test` (property 37), and `test multiple image` (property 38) were not changed because they have favorite or rental-inquiry/contract history and their titles alone are not sufficient evidence to delete records. Properties 36 and 38 are still marked available and should be reviewed by an administrator before being treated as genuine inventory.

**Note:** The 200 new records are prominently labeled illustrative, fictional examples with unverified prices, addresses, amenities, ownership, and availability. Replace them with genuine, reviewed listings before any public use.

## Super Admin Console Verification (2026-10-24)

**Passed:** Provisioned and authenticated a local active `super_admin` account. The distinct admin login and dashboard were verified in the browser. Dashboard counts, user-detail links, listing review, payment ledger, reports, and appointment assignment were visible. The appointments, payments, users, property list/add, reports, and reported-property routes each returned HTTP 200 without PHP errors. No browser page errors remained on these routes after guarding optional DataTables, Morris chart, and SlimScroll initializers. PHP syntax checks passed for all 33 Admin PHP files and the Agent dashboard helper.

The appointment assignment migration was applied to the local database. The supplied SQL dump now includes the assignment column and index for fresh imports; existing databases should apply that migration once. The temporary Super Admin password is intentionally not recorded in this report.

## Example Listing Photos (2026-10-27)

**Passed:** Added 200 locally stored Pexels property photos, with 40 images each for apartments, independent houses, villas, residential plots, and commercial offices. Photo IDs and source URLs are recorded in `admin/property/example-property-photo-sources.csv`; the 200 photo IDs and image-content hashes are unique. All image files are valid JPEGs and all 200 Apache image URLs returned HTTP 200.

**Passed:** Updated the authenticated Agent seeder to assign each example listing its corresponding numbered photo and disclose that the stock photo is representative, not a photograph of the example property. The active Agent session resolved to user ID 110; rerunning the seeder verified 200 owned listings and inserted zero additional rows. Database checks confirmed 200 distinct titles, 200 correctly mapped photos, and 200 disclosures.

**Passed:** The Agent dashboard loaded all 200 example photos without broken images. The anonymous marketplace request returned 200 Bengaluru listings and displayed nine paginated cards with nine example photos and nine View Details actions. The seeded property detail page also loaded its assigned photo successfully.

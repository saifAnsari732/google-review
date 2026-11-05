# Project: Google Review Manager (Production-Ready Local Runtime)

## Architecture
- **Framework & Core**: Symfony 5.4 LTS, PHP 7.4/8.x compatible.
- **Dual Presentation Layer**:
  - **MVC Web Application**: AdminLTE v3.2, Bootstrap 4.6, jQuery, Twig templates for admin back-office and landing page.
  - **REST API**: OpenAPI 3.0 specification (`config/routes/api/v1/bo-openapi-specifications.yaml`) via `Nijens\OpenapiBundle`.
- **Data & Storage Layer**:
  - Doctrine ORM with multi-database support: local SQLite DBAL (`var/data.db`) for zero-dependency local host run, and MySQL 8.0 support.
  - Cache Layer: Configurable Redis / Filesystem / Array cache fallback for password recovery and caching.
  - Media & Files: Local QR code storage in `public/google_reviews/qr_codes/`.
- **Integrations & Mocks**:
  - Google Place API (Autocomplete & Details): Native cURL transport with zero-credential local mock fixture mode.
  - Google reCAPTCHA v3: Anti-spam verification with local mock validation mode.
  - Mailer: Symfony Mailer with `null://null` or local Mailpit support.
  - SendGrid: API integration with zero-credential fallback.
- **Runtime Target**: Native Windows Local Host Runtime (no Docker required).
  - Built-in PHP Web Server: `php -S 127.0.0.1:8000 -t public/` (or Symfony CLI).
  - Host Asset Tooling: Node/NPM at `E:\node.exe` with Gulp 4 for asset compilation into `public/dist/`.

## Feature Inventory
| # | Feature / Work Item | Description | Milestone | Source |
|---|---------------------|-------------|-----------|--------|
| 1 | Composer Repository URL Fix | Change SSH URL `git@github.com:yjmorales/php-core.git` to public HTTPS `https://github.com/yjmorales/php-core.git` | M1 | Survey 1, 2, 3 |
| 2 | Remove Undeclared `Illuminate\Support\Arr` | Replace undeclared Laravel helper with native PHP array access in `Transport`, `PlaceDetailsService`, `PlaceAutocompleteService` | M1 | Survey 2 |
| 3 | Fix `PlaceDetailsService` Exception Return | Replace `return new InvalidArgumentException()` with `throw new InvalidArgumentException()` in `getAddressComponentSection()` | M1 | Survey 1, 2 |
| 4 | Fix `Place::getFullAddress()` Concatenation | Fix string concatenation bug in `Place.php:168` so street number is correctly included in full address | M1 | Survey 1 |
| 5 | Fix `ReviewModel::toObject()` Null Dereference | Add null check on nullable `$business->getPlace()` and fix double semicolon typo | M1 | Survey 1 |
| 6 | Fix `GooglePlaceController` Syntax Typo | Remove redundant double semicolon `$this->_em($doctrine)->persist($place);;` | M1 | Survey 1 |
| 7 | Fix `PlaceMutator` Place ID Type Mismatch | Fix `Address::$placeId` type from `?int` to `?string` and map Google Place ID string correctly | M1 | Survey 1, 2 |
| 8 | Fix Dependency Injection `services.yaml` | Remove non-existent `GoogleReviewManager` and `TextExtension`, fix `Transport` and `ApiGoogleReviewManager` arguments | M1 | Survey 1 |
| 9 | Fix `ExceptionListener` API Handling | Ensure API requests under `/api/v1` receive JSON error responses instead of 302 HTML redirects in prod mode | M1 | Survey 1 |
| 10 | Remove Dead Code `TestController` | Remove developer scratch route `src/Controller/Business/TestController.php` marked `todo: remove` | M1 | Survey 1, 2 |
| 11 | Fix BOLA/IDOR Security on API Endpoints | Protect `/api/v1/admin/business/{id}/remove` and review update/removal endpoints in `security.yaml` with `ROLE_ADMIN` / auth | M1 | Survey 1 |
| 12 | Fix Host Header Injection in Password Reset | Replace `$_SERVER['HTTP_ORIGIN']` with configured trusted host or validated request host in `ChangePasswordController.php` | M1 | Survey 1 |
| 13 | Fix Insecure PRNG in Password Reset Token | Replace predictable `md5(uniqid())` with cryptographically secure `bin2hex(random_bytes(32))` | M1 | Survey 1 |
| 14 | Fix CSRF on User Deletion | Protect user deletion route `GET /admin/user/{id}/remove` with CSRF token verification or convert to POST with token | M1 | Survey 1 |
| 15 | Fix Insecure TLS Verification | Re-enable SSL/TLS peer certificate validation (`CURLOPT_SSL_VERIFYPEER = true`) in `Transport.php` | M1 | Survey 1 |
| 16 | Fix Null Pointer on Unknown Email in Reset | Add check for non-existent user in `ChangePasswordController` to avoid 500 error on unknown email | M1 | Survey 1, 2 |
| 17 | Test Infrastructure Setup | Create structured test directories (`tests/Unit`, `tests/Integration`, `tests/Functional`) and bootstrap configuration | M2 | Survey 2 |
| 18 | Test Environment Config (`.env.test`) | Populate `.env.test` and `phpunit.xml.dist` with complete defaults for all 14+ required parameters | M2 | Survey 2 |
| 19 | Test Database Isolation | Configure SQLite in-memory / file DBAL under `when@test:` in `doctrine.yaml` for isolated, fast test execution | M2 | Survey 2 |
| 20 | Unit Test Suite Implementation | Implement comprehensive unit tests for Review Manager, QR Manager, Place services, Enums, Entities, and Forms | M2 | Survey 2 |
| 21 | Integration Test Suite Implementation | Implement integration tests for `BusinessRepository` and `EntityGeneratorCommand` | M2 | Survey 2 |
| 22 | Functional Test Suite Implementation | Implement functional tests for Landing page, Login/Security, Business Admin, User Admin, Review APIs, Place APIs | M2 | Survey 2 |
| 23 | Static Analysis & Code Quality Tooling | Configure `phpstan/phpstan` at level 5/6 with `phpstan.neon` and composer scripts (`composer test`, `composer phpstan`) | M2 | Survey 2 |
| 24 | Windows Host PHP & Composer Setup | Provide automated instructions and verification scripts for installing PHP (with required extensions) & Composer on Windows | M3 | User Directive, Survey 3 |
| 25 | Local Environment Config (`.env` & `.env.example`) | Create `.env` and `.env.example` with zero-credential defaults, SQLite/MySQL DBAL, and mock flags | M3 | User Directive, Survey 3 |
| 26 | Zero-Credential Google API & reCAPTCHA Mocks | Implement local mock mode for Google Places Autocomplete/Details and reCAPTCHA v3 verification | M3 | Survey 3, User Directive |
| 27 | Filesystem / Array Cache Fallback | Configure cache service in `services.yaml` to gracefully fallback to filesystem/array cache if Redis is not running | M3 | User Directive, Survey 3 |
| 28 | Application Health Check Route (`/health`) | Implement `HealthController` with `/health` returning HTTP 200 and JSON status (`{"status": "UP", "database": "UP"}`) | M3 | Survey 3 |
| 29 | Frontend Asset Compilation | Compile CSS/JS bundles into `public/dist/` using host Node (`E:\node.exe`) and Gulp 4 | M3 | Survey 3, User Directive |
| 30 | QR Code Storage Directory Initialization | Ensure `public/google_reviews/qr_codes` directory exists with proper write permissions | M3 | Survey 3 |
| 31 | Final E2E Test Suite & Adversarial Hardening | Verify 100% test pass on all test tiers (Tiers 1-4) followed by Tier 5 adversarial stress/boundary test hardening | M4 | Project Pattern |

## Milestones
| # | Name | Scope | Dependencies | Status |
|---|------|-------|-------------|--------|
| M1 | Code Audit, Security Remediation & DI Stabilization | Fix all codebase defects, security vulnerabilities (BOLA, CSRF, TLS, Host Injection, PRNG), dependency SSH URLs, and DI container compilation issues. | none | IN_PROGRESS |
| M2 | Comprehensive Automated Test Coverage & Static Analysis | Create complete Unit, Integration, and Functional test suites, configure SQLite test DB, `.env.test`, PHPStan static analysis, and verify 100% test pass. | M1 | PLANNED |
| M3 | Native Windows Local Runtime Setup, Zero-Credential Mocks & Asset Build | Configure Windows host environment (PHP, Composer, Node/Gulp), `.env` with SQLite/MySQL fallback, Google API & reCAPTCHA local mocks, filesystem cache fallback, `/health` endpoint, and compile frontend assets. | M1, M2 | PLANNED |
| M4 | Final Milestone (100% E2E Verification & Adversarial Coverage Hardening) | Full application verification without live external credentials, running all automated test suites with 0 failures, and adversarial coverage hardening. | M1, M2, M3 | PLANNED |

## Interface Contracts
### Public API Contracts (`/api/v1`)
- `POST /api/v1/review/generate-guest`:
  - Request: Form data `place_id` (string), `email` (string), `token` (reCAPTCHA string).
  - Response: JSON `{"code": 200, "status": "OK", "data": {"review": {...}, "url": "...", "qr_code_base64": "..."}}`.
- `POST /api/v1/business/{id}/review/generate`:
  - Authentication: `ROLE_ADMIN` required.
  - Response: JSON `{"code": 200, "status": "OK", "data": {...}}`.
- `POST /api/v1/admin/business/{id}/remove`:
  - Authentication: `ROLE_ADMIN` required.
  - Response: JSON `{"code": 200, "status": "OK"}`.
- `GET /health`:
  - Access: Public.
  - Response: HTTP 200 JSON `{"status": "UP", "database": "UP", "timestamp": "..."}`.

### Service Layer Contracts
- `TransportInterface` / `Transport`: Outbound HTTP request handler supporting mock response injection when `MOCK_GOOGLE_SERVICES=true` or key is mock.
- `QrCodeManager`: Generates PNG images to `public/google_reviews/qr_codes/{filename}.png` and returns base64 data URI.
- `CacheRegistryInterface`: Abstract cache interface providing Redis adapter with fallback to `Symfony\Component\Cache\Adapter\FilesystemAdapter` when Redis connection is unavailable.

## Code Layout
- `config/`: Symfony configuration, routes, packages, DI container `services.yaml`.
- `src/Api/`: REST API controllers, models, validators.
- `src/Controller/`: MVC controllers for admin back-office and landing page.
- `src/Entity/`: Doctrine ORM entities (`Business`, `Place`, `Review`, `User`, `IndustrySector`).
- `src/Google/`: Google Map Place autocomplete, details, and transport services.
- `src/Service/`: Business services (`ApiGoogleReviewManager`, `QrCodeManager`).
- `templates/`: Twig presentation templates.
- `public/`: Web root (`index.php`), `dist/` (compiled assets), `google_reviews/qr_codes/`.
- `tests/`: Automated test suites (`Unit/`, `Integration/`, `Functional/`).
- `migrations/`: Doctrine database migrations.

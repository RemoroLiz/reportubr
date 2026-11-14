# AI DEVELOPMENT INSTRUCTIONS

## Project

Pantes Sales Report

## Technology

- PHP 8+
- MySQL / MariaDB
- JavaScript
- AJAX / Fetch
- CSS
- No PHP framework
- XAMPP compatible

## IMPORTANT

Before modifying code:

1. Do NOT read the entire repository unless necessary.
2. Identify the files related to the requested feature.
3. Read the relevant PHP file first.
4. Follow its dependencies into `inc/`, `assets/js/`, `api.php`, or other related files only when necessary.
5. Do not modify unrelated files.
6. Do not change database structure unless explicitly requested.
7. Preserve existing functionality.
8. Preserve existing security mechanisms.
9. After modification, check for PHP syntax errors and obvious JavaScript errors.

## Architecture

### Public PHP pages

Root PHP files are public entry points.

Examples:

- `index.php` → dashboard/home
- `penjualan.php` → sales
- `pembelian.php` → purchases
- `barang.php` → products
- `master_sales.php` → sales master
- `master_target.php` → targets
- `grading.php` → grading
- `pareto.php` → pareto analysis

### Internal code

`inc/`

Contains internal PHP logic and database/schema-related code.

Do not expose internal files unnecessarily.

### JavaScript

`assets/js/`

Main application JavaScript is:

`assets/js/app.js`

Third-party JavaScript libraries are inside:

`assets/js/vendor/`

### CSS

`assets/css/`

## Database

Database connection and configuration are handled through the existing configuration system.

Never hard-code database credentials.

Do not modify database schema unless explicitly requested.

## Security

Preserve:

- authentication
- authorization
- CSRF protection
- prepared statements
- password hashing
- login protection
- upload validation

Never remove security checks simply to make a feature work.

## Coding rules

Prefer modifying existing functionality instead of creating duplicate systems.

Use the existing coding style.

Avoid unnecessary refactoring.

Do not rename files or database columns unless explicitly requested.

Do not replace working AJAX/fetch architecture with another technology unless requested.

## Change strategy

For every request:

1. Understand the requested behavior.
2. Find the smallest set of files involved.
3. Read only those files and their required dependencies.
4. Make the smallest safe change.
5. Check related functionality.
6. Explain which files were changed and why.

## Git

Use a clear commit message.

Example:

`fix: correct sales forecast calculation`

or

`feat: add branch filter to weekly sales`

## Important

If the requested change is ambiguous, inspect the existing implementation before asking questions.

Do not rewrite the project from scratch.

Do not generate replacement files unnecessarily.

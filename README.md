# VibeLocate Laravel - Clean Structure

نسخة Laravel خفيفة ومنظمة مبنية على Backend المصادقة الأصلي فقط.

## Structure

- `app/Http/Controllers/Api/Auth/` — login, register, password, email verification, sessions, 2FA.
- `app/Http/Controllers/Api/ProfileController.php` — profile endpoints.
- `app/Http/Middleware/` — JWT, roles, permissions, CSRF, JSON validation and rate limiting.
- `app/Services/Auth/` — JWT, TOTP, CSRF and authorization services.
- `routes/api.php` — API routes.
- `config/vibelocate.php` — VibeLocate-specific configuration.

قاعدة البيانات لم يتم دمجها أو توليد Models/Migrations لها في هذه النسخة. يتم ربطها كخطوة لاحقة.

## First run

```bash
composer install
copy .env.example .env
php artisan key:generate
php artisan serve
```

لا تشغّل migrations قبل ربط قاعدة البيانات ومراجعة الـ schema.

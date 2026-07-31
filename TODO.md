# Stockora BASE_URL Implementation - Progress

## ✅ Done
- [x] 1. `includes/config.php` - Add `$Baseurl = BASE_URL;`
- [x] 2. `includes/functions.php` - Update redirect helpers with BASE_URL
- [x] 3. `includes/admin_layout.php` - All links → BASE_URL
- [x] 4. `includes/shop_layout.php` - All links → BASE_URL
- [ ] 5. Root PHP files (index, login, logout, landing, seed)
- [ ] 6. All admin/*.php files (13 files)
- [ ] 7. All shop/*.php files (28 files)
- [ ] 8. API files (api/sales.php, api/shop_report.php)
- [ ] 9. assets/js/app.js - Add BASE_URL context

## Plan

### Root PHP files
Replace hardcoded paths starting with `/` with BASE_URL prefix:
- `index.php`: Change `'/admin/index.php'` → `BASE_URL . '/admin/index.php'` etc.
- `login.php`: Change `'/admin/index.php'` → `BASE_URL . '/admin/index.php'` etc.
- `logout.php`: Change `'/login.php'` → `BASE_URL . '/login.php'`
- `landing.php`: Change all hardcoded `/login.php` → `BASE_URL . '/login.php'`, `/landing.php` → `BASE_URL . '/landing.php'`
- `store.php`: Change all `/assets/uploads/` → `BASE_URL . '/assets/uploads/'`, `/landing.php` → `BASE_URL . '/landing.php'`
- `seed.php`: No URL paths to fix

### admin/*.php files (13 files)
Replace all hardcoded paths like `/admin/...` → `BASE_URL . '/admin/...'`

### shop/*.php files (28 files)
Replace all hardcoded paths like `/shop/...` → `BASE_URL . '/shop/...'`

### API files
- `api/sales.php`: Already uses BASE_URL via functions.php
- `api/shop_report.php`: Already uses BASE_URL via functions.php

### assets/js/app.js
- Already has BASE_URL variable set from layouts (`var BASE_URL = '<?= BASE_URL ?>'`)


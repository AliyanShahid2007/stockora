<?php
require_once __DIR__ . '/includes/functions.php';
startSession();

if (isAdminLoggedIn()) { header('Location: ' . BASE_URL . '/admin/index.php'); exit; }
if (isShopLoggedIn()) { header('Location: ' . BASE_URL . '/shop/index.php'); exit; }

$error = '';
$input = ['shop_name'=>'', 'owner_name'=>'', 'email'=>'', 'phone'=>''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = [
        'shop_name'  => trim((string)($_POST['shop_name'] ?? '')),
        'owner_name' => trim((string)($_POST['owner_name'] ?? '')),
        'email'      => strtolower(trim((string)($_POST['email'] ?? ''))),
        'phone'      => trim((string)($_POST['phone'] ?? '')),
    ];
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm_password'] ?? '');

    if (!empty($_POST['website'])) {
        $error = 'Unable to create your account. Please try again.';
    } elseif (mb_strlen($input['shop_name']) < 2 || mb_strlen($input['owner_name']) < 2) {
        $error = 'Enter your shop name and full name.';
    } elseif (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must contain at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $db = getDB();
        $existing = $db->prepare("SELECT email FROM users WHERE email=? UNION SELECT email FROM shops WHERE email=? UNION SELECT email FROM admins WHERE email=? LIMIT 1");
        $existing->execute([$input['email'], $input['email'], $input['email']]);
        if ($existing->fetch()) {
            $error = 'An account with this email already exists. Please sign in.';
        } else {
            try {
                $db->beginTransaction();
                $shop = $db->prepare("INSERT INTO shops (name, owner_name, email, phone, status) VALUES (?, ?, ?, ?, 'active')");
                $shop->execute([$input['shop_name'], $input['owner_name'], $input['email'], $input['phone'] ?: null]);
                $shopId = (int)$db->lastInsertId();

                $user = $db->prepare("INSERT INTO users (shop_id, name, email, password, role, status) VALUES (?, ?, ?, ?, 'owner', 'active')");
                $user->execute([$shopId, $input['owner_name'], $input['email'], hashPassword($password)]);
                $userId = (int)$db->lastInsertId();

                // Inclusive start/end dates give the client seven complete trial days.
                $trial = $db->prepare("INSERT INTO subscriptions (shop_id, plan_name, amount, months, start_date, end_date, status, payment_method, notes)
                    VALUES (?, 'Free Trial', 0, 0, ?, ?, 'active', 'trial', 'Self-service 7-day free trial')");
                $trial->execute([$shopId, date('Y-m-d'), date('Y-m-d', strtotime('+6 days'))]);
                $db->commit();

                session_regenerate_id(true);
                $_SESSION['user_id'] = $userId;
                $_SESSION['user_name'] = $input['owner_name'];
                $_SESSION['user_role'] = 'owner';
                $_SESSION['shop_id'] = $shopId;
                $_SESSION['shop_name'] = $input['shop_name'];
                header('Location: ' . BASE_URL . '/shop/index.php?msg=' . urlencode('Welcome! Your 7-day free trial has started.') . '&type=success');
                exit;
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = 'Account could not be created. Please try again.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en"><head>
<link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/images/stockora-favicon.png">
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Start Free Trial — <?= htmlspecialchars(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
body{min-height:100vh;background:radial-gradient(circle at 15% 10%,#203a58 0,transparent 35%),linear-gradient(135deg,#091424,#111c35);color:#e8f4f4;font-family:Inter,system-ui,sans-serif}.trial-wrap{max-width:980px;margin:auto;padding:4rem 1rem}.trial-card{background:rgba(18,34,54,.92);border:1px solid rgba(14,206,206,.24);border-radius:22px;overflow:hidden;box-shadow:0 22px 70px rgba(0,0,0,.35)}.trial-side{background:linear-gradient(145deg,#6c63ff,#0ecece);padding:2.5rem}.form-control{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.2);color:#fff}.form-control:focus{background:rgba(255,255,255,.12);border-color:#0ecece;color:#fff;box-shadow:0 0 0 .2rem rgba(14,206,206,.16)}.form-control::placeholder{color:#92a8b7}.feature{background:rgba(0,0,0,.13);border-radius:10px;padding:.6rem .75rem;margin:.5rem 0}.btn-trial{background:linear-gradient(135deg,#0ecece,#6c63ff);border:0;color:#fff;font-weight:800;padding:.8rem}.honeypot{position:absolute;left:-9999px}
</style></head><body>
<main class="trial-wrap"><div class="trial-card row g-0"><section class="col-lg-5 trial-side"><a href="<?= BASE_URL ?>/landing.php" class="text-white text-decoration-none fw-bold"><i class="bi bi-arrow-left me-2"></i>Stockora</a><div class="mt-5"><span class="badge text-bg-light text-primary mb-3">7 DAYS FREE</span><h1 class="fw-bold">Run your shop smarter from day one.</h1><p class="opacity-90">Create your shop account in under two minutes. No card required.</p><div class="feature"><i class="bi bi-check-circle-fill me-2"></i>POS, products, stock and sales</div><div class="feature"><i class="bi bi-check-circle-fill me-2"></i>Customers and basic business reports</div><div class="feature"><i class="bi bi-lock-fill me-2"></i>AI Engine &amp; Commerce Cloud unlock on paid plans</div></div></section><section class="col-lg-7 p-4 p-md-5"><div class="mb-4"><h2 class="fw-bold">Start your free trial</h2><p class="text-secondary mb-0">Your trial ends automatically after 7 days. You can upgrade anytime.</p></div><?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?><form method="post" novalidate><div class="honeypot" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div><div class="row g-3"><div class="col-12"><label class="form-label">Shop name</label><input class="form-control" name="shop_name" required maxlength="255" value="<?= htmlspecialchars($input['shop_name']) ?>" placeholder="e.g. Karachi Book Store"></div><div class="col-md-6"><label class="form-label">Your full name</label><input class="form-control" name="owner_name" required maxlength="255" value="<?= htmlspecialchars($input['owner_name']) ?>"></div><div class="col-md-6"><label class="form-label">Phone <span class="text-secondary">(optional)</span></label><input class="form-control" name="phone" maxlength="50" value="<?= htmlspecialchars($input['phone']) ?>"></div><div class="col-12"><label class="form-label">Email address</label><input class="form-control" type="email" name="email" required maxlength="255" value="<?= htmlspecialchars($input['email']) ?>"></div><div class="col-md-6"><label class="form-label">Password</label><input class="form-control" type="password" name="password" required minlength="8" placeholder="Minimum 8 characters"></div><div class="col-md-6"><label class="form-label">Confirm password</label><input class="form-control" type="password" name="confirm_password" required minlength="8"></div><div class="col-12"><button class="btn btn-trial w-100" type="submit"><i class="bi bi-rocket-takeoff-fill me-2"></i>Create My Free Trial</button></div></div></form><p class="small text-secondary text-center mt-4 mb-0">Already have an account? <a href="<?= BASE_URL ?>/login.php" class="text-info">Sign in</a></p></section></div></main>
</body></html>

<?php
// Main entry point - redirect to appropriate panel
require_once 'includes/functions.php';
startSession();

if (isAdminLoggedIn()) {
    header('Location: ' . BASE_URL . '/admin/index.php');
} elseif (isShopLoggedIn()) {
    header('Location: ' . BASE_URL . '/shop/index.php');
} else {
    header('Location: ' . BASE_URL . '/landing.php');
}
exit;

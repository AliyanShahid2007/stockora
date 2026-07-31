<?php
require_once 'includes/functions.php';
startSession();
session_destroy();
header('Location: ' . BASE_URL . '/login.php?msg=Logged+out+successfully&type=success');
exit;

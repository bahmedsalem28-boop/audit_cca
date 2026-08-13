<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';

deconnecter();
header('Location: ' . BASE_URL . '/login.php');
exit;

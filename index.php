<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';

header('Location: ' . BASE_URL . (utilisateurConnecte() ? '/dashboard.php' : '/login.php'));
exit;

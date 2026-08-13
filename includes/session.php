<?php
/**
 * session.php — Démarrage sécurisé de session + expiration automatique
 * À inclure en tout premier sur chaque page protégée.
 */

require_once __DIR__ . '/../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => isset($_SERVER['HTTPS']),   // true si HTTPS actif
        'httponly' => true,                        // inaccessible en JavaScript
        'samesite' => 'Lax',
    ]);
    session_name('AUDIT_CAAT_SESSID');
    session_start();
}

// --- Expiration automatique par inactivité ---
if (isset($_SESSION['derniere_activite']) && (time() - $_SESSION['derniere_activite'] > SESSION_TIMEOUT)) {
    $_SESSION = [];
    session_unset();
    session_destroy();
    session_start();
    $_SESSION['erreur_session'] = 'Votre session a expiré par inactivité. Veuillez vous reconnecter.';
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$_SESSION['derniere_activite'] = time();

// --- Régénération périodique de l'ID de session (anti session fixation) ---
if (!isset($_SESSION['cree_le'])) {
    $_SESSION['cree_le'] = time();
} elseif (time() - $_SESSION['cree_le'] > 10 * 60) {
    session_regenerate_id(true);
    $_SESSION['cree_le'] = time();
}

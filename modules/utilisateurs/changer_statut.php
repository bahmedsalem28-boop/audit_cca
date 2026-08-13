<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit_logger.php';
require_once __DIR__ . '/../../config/database.php';

exigerRole(['ADMIN']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verifier()) {
    header('Location: liste.php');
    exit;
}

$pdo = Database::getConnexion();
$moi = utilisateurCourant();
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$nouveauStatut = filter_input(INPUT_POST, 'nouveau_statut', FILTER_VALIDATE_INT);

if ($id === (int) $moi['id']) {
    header('Location: liste.php?erreur=auto_suppression');
    exit;
}

if ($id !== null && in_array($nouveauStatut, [0, 1], true)) {
    $pdo->prepare('UPDATE utilisateurs SET actif = :a WHERE id = :id')->execute(['a' => $nouveauStatut, 'id' => $id]);
    journaliser($moi['id'], 'CHANGEMENT_STATUT_UTILISATEUR', 'utilisateurs', (string) $id, $nouveauStatut ? 'Activé' : 'Désactivé');
}

header('Location: liste.php');
exit;

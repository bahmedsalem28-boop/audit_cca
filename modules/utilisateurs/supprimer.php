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

if ($id === (int) $moi['id']) {
    header('Location: liste.php?erreur=auto_suppression');
    exit;
}

if ($id) {
    $stmtEmail = $pdo->prepare('SELECT email FROM utilisateurs WHERE id = :id');
    $stmtEmail->execute(['id' => $id]);
    $email = $stmtEmail->fetchColumn();

    try {
        // FK ON DELETE RESTRICT sur dossiers_audit.utilisateur_id, fichiers_fec.utilisateur_id, etc.
        $pdo->prepare('DELETE FROM utilisateurs WHERE id = :id')->execute(['id' => $id]);
        journaliser($moi['id'], 'SUPPRESSION_UTILISATEUR', 'utilisateurs', (string) $id, (string) $email);
        header('Location: liste.php');
    } catch (Throwable $e) {
        error_log('Erreur suppression utilisateur : ' . $e->getMessage());
        header('Location: liste.php?erreur=references');
    }
    exit;
}

header('Location: liste.php');
exit;

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
$dossierId = filter_input(INPUT_POST, 'dossier_id', FILTER_VALIDATE_INT);

if ($id) {
    $stmt = $pdo->prepare('SELECT ecriture_num, journal_code FROM ecritures WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $ref = $stmt->fetch();

    $pdo->prepare('DELETE FROM ecritures WHERE id = :id')->execute(['id' => $id]);
    if ($ref) {
        journaliser($moi['id'], 'SUPPRESSION_ECRITURE', 'ecritures', (string) $id, $ref['journal_code'] . '/' . $ref['ecriture_num']);
    }
}

header('Location: liste.php' . ($dossierId ? '?dossier_id=' . $dossierId : ''));
exit;

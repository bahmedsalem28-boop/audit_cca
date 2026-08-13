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
$utilisateur = utilisateurCourant();
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if ($id) {
    $stmtNom = $pdo->prepare('SELECT nom_client FROM dossiers_audit WHERE id = :id');
    $stmtNom->execute(['id' => $id]);
    $nom = $stmtNom->fetchColumn();

    try {
        // Les FK sont en CASCADE sur fichiers_fec/ecritures/anomalies/parametres_test : suppression complète assumée
        $pdo->prepare('DELETE FROM dossiers_audit WHERE id = :id')->execute(['id' => $id]);
        journaliser($utilisateur['id'], 'SUPPRESSION_DOSSIER', 'dossiers_audit', (string) $id, (string) $nom);
        header('Location: liste.php');
    } catch (Throwable $e) {
        error_log('Erreur suppression dossier : ' . $e->getMessage());
        header('Location: liste.php?erreur=references');
    }
    exit;
}

header('Location: liste.php');
exit;

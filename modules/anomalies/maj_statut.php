<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit_logger.php';
require_once __DIR__ . '/../../config/database.php';

exigerRole(['ADMIN', 'AVANCE']);

$retour = $_POST['retour'] ?? (BASE_URL . '/modules/anomalies/liste.php');
// Sécurité : n'autoriser une redirection que vers une page interne de l'application
if (!str_starts_with($retour, BASE_URL)) {
    $retour = BASE_URL . '/modules/anomalies/liste.php';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verifier()) {
    header('Location: ' . $retour);
    exit;
}

$pdo = Database::getConnexion();
$utilisateur = utilisateurCourant();

$anomalieId = filter_input(INPUT_POST, 'anomalie_id', FILTER_VALIDATE_INT);
$nouveauStatut = $_POST['nouveau_statut'] ?? '';

$statutsValides = ['non_traite', 'en_cours', 'traite', 'ecarte'];
if ($anomalieId && in_array($nouveauStatut, $statutsValides, true)) {
    $stmt = $pdo->prepare(
        'UPDATE anomalies SET statut_traitement = :statut, traite_par = :uid, date_traitement = NOW() WHERE id = :id'
    );
    $stmt->execute(['statut' => $nouveauStatut, 'uid' => $utilisateur['id'], 'id' => $anomalieId]);

    journaliser($utilisateur['id'], 'MAJ_STATUT_ANOMALIE', 'anomalies', (string) $anomalieId, "Nouveau statut : $nouveauStatut");
}

header('Location: ' . $retour);
exit;

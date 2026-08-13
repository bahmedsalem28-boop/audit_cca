<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit_logger.php';
require_once __DIR__ . '/../../includes/tests_caat.php';
require_once __DIR__ . '/../../includes/tests_caat_globaux.php';
require_once __DIR__ . '/../../includes/tests_caat_comptes.php';
require_once __DIR__ . '/../../config/database.php';

exigerRole(['ADMIN', 'AVANCE']);

function rediriger_erreur_tests(string $message): void
{
    header('Location: lancer.php?statut=erreur&message=' . urlencode($message));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verifier()) {
    rediriger_erreur_tests('Requête invalide ou jeton de sécurité expiré.');
}

$pdo = Database::getConnexion();
$utilisateur = utilisateurCourant();

$dossierId = filter_input(INPUT_POST, 'dossier_id', FILTER_VALIDATE_INT);
if (!$dossierId) {
    rediriger_erreur_tests('Veuillez sélectionner un dossier d\'audit.');
}

$testsDemandes = array_filter((array) ($_POST['tests'] ?? []), fn($t) => is_string($t));
$testsAutorises = [
    'DOUBLONS', 'WEEKEND', 'ROUND_NUMBER', 'CHRONO_INVERSEE', 'ANNULATION_RAPIDE',
    'BENFORD', 'TOP_SAISISSEURS', 'FIN_PERIODE', 'SCORING_RISQUE',
    'REVUE_ANALYTIQUE', 'SOLDE_ANORMAL',
];
$testsDemandes = array_values(array_intersect($testsDemandes, $testsAutorises));

if (empty($testsDemandes)) {
    rediriger_erreur_tests('Veuillez sélectionner au moins un test à exécuter.');
}

$fonctionsParTest = [
    'DOUBLONS'           => 'testDoublons',
    'WEEKEND'            => 'testEcrituresAtypiques',
    'ROUND_NUMBER'       => 'testRoundNumbers',
    'CHRONO_INVERSEE'    => 'testChronologieInversee',
    'ANNULATION_RAPIDE'  => 'testAnnulationRapide',
    'BENFORD'            => 'testBenford',
    'TOP_SAISISSEURS'    => 'testTopSaisisseurs',
    'FIN_PERIODE'        => 'testConcentrationFinPeriode',
    'SCORING_RISQUE'     => 'testScoringRisque',
    'REVUE_ANALYTIQUE'   => 'testRevueAnalytique',
    'SOLDE_ANORMAL'      => 'testSoldesAnormaux',
];

// SCORING_RISQUE doit s'exécuter en dernier : il agrège les résultats des autres tests
usort($testsDemandes, fn($a, $b) => ($a === 'SCORING_RISQUE') <=> ($b === 'SCORING_RISQUE'));

$resultats = [];
try {
    foreach ($testsDemandes as $code) {
        $fn = $fonctionsParTest[$code];
        $nb = $fn($pdo, $dossierId);
        $resultats[$code] = $nb;
    }
} catch (Throwable $e) {
    error_log('Erreur exécution tests CAAT : ' . $e->getMessage());
    // Message détaillé temporaire pour diagnostic (à remettre en générique une fois le bug corrigé)
    rediriger_erreur_tests('Erreur : ' . $e->getMessage() . ' (fichier ' . basename($e->getFile()) . ', ligne ' . $e->getLine() . ')');
}

$resume = [];
foreach ($resultats as $code => $nb) {
    $resume[] = "$code : $nb";
}
$resumeTexte = implode(' · ', $resume);

journaliser(
    $utilisateur['id'],
    'EXECUTION_TESTS',
    'dossiers_audit',
    (string) $dossierId,
    'Tests exécutés : ' . $resumeTexte
);

header('Location: lancer.php?dossier_id=' . $dossierId . '&statut=succes&resume=' . urlencode($resumeTexte));
exit;

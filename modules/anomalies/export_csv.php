<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_logger.php';
require_once __DIR__ . '/../../includes/export_csv.php';
require_once __DIR__ . '/../../config/database.php';

exigerConnexion();
$pdo = Database::getConnexion();

$fDossier = filter_input(INPUT_GET, 'dossier_id', FILTER_VALIDATE_INT) ?: null;
$fGravite = in_array($_GET['gravite'] ?? '', ['critique', 'elevee', 'moyenne', 'faible'], true) ? $_GET['gravite'] : null;
$fStatut  = in_array($_GET['statut'] ?? '', ['non_traite', 'en_cours', 'traite', 'ecarte'], true) ? $_GET['statut'] : null;
$fType    = filter_input(INPUT_GET, 'type_test_id', FILTER_VALIDATE_INT) ?: null;
$fRecherche = trim((string) ($_GET['q'] ?? ''));

$conditions = [];
$params = [];
if ($fDossier) { $conditions[] = 'a.dossier_id = :dossier_id'; $params['dossier_id'] = $fDossier; }
if ($fGravite) { $conditions[] = 'a.gravite = :gravite'; $params['gravite'] = $fGravite; }
if ($fStatut)  { $conditions[] = 'a.statut_traitement = :statut'; $params['statut'] = $fStatut; }
if ($fType)    { $conditions[] = 'a.type_test_id = :type_test_id'; $params['type_test_id'] = $fType; }
if ($fRecherche !== '') { $conditions[] = 'a.description LIKE :q'; $params['q'] = '%' . $fRecherche . '%'; }
$where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

$sql = "SELECT a.gravite, a.statut_traitement, a.date_detection, a.description,
               tt.libelle AS test_libelle, d.nom_client, d.exercice,
               e.journal_code, e.ecriture_num, e.compte_num
        FROM anomalies a
        JOIN types_tests tt ON tt.id = a.type_test_id
        JOIN dossiers_audit d ON d.id = a.dossier_id
        LEFT JOIN ecritures e ON e.id = a.ecriture_id
        $where
        ORDER BY FIELD(a.gravite,'critique','elevee','moyenne','faible'), a.date_detection DESC
        LIMIT 50000";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$anomalies = $stmt->fetchAll();

$libellesGravite = ['critique' => 'Critique', 'elevee' => 'Élevée', 'moyenne' => 'Moyenne', 'faible' => 'Faible'];
$libellesStatut  = ['non_traite' => 'Non traité', 'en_cours' => 'En cours', 'traite' => 'Traité', 'ecarte' => 'Écarté'];

$entetes = ['Client', 'Exercice', 'Gravité', 'Test', 'Journal', 'N° écriture', 'Compte', 'Description', 'Statut', 'Détecté le'];
$lignes = [];
foreach ($anomalies as $a) {
    $lignes[] = [
        $a['nom_client'], $a['exercice'], $libellesGravite[$a['gravite']], $a['test_libelle'],
        $a['journal_code'], $a['ecriture_num'], $a['compte_num'], $a['description'],
        $libellesStatut[$a['statut_traitement']], date('d/m/Y', strtotime($a['date_detection'])),
    ];
}

journaliser(utilisateurCourant()['id'], 'EXPORT_CSV', 'anomalies', (string) $fDossier, count($lignes) . ' lignes exportées');

exporterCSV('anomalies' . ($fDossier ? '_dossier_' . $fDossier : ''), $entetes, $lignes);

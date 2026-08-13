<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_logger.php';
require_once __DIR__ . '/../../includes/export_csv.php';
require_once __DIR__ . '/../../config/database.php';

exigerConnexion();
$pdo = Database::getConnexion();

$fDossier = filter_input(INPUT_GET, 'dossier_id', FILTER_VALIDATE_INT);
if (!$fDossier) {
    header('Location: liste.php');
    exit;
}
$fJournal = trim((string) ($_GET['journal'] ?? ''));
$fCompte = trim((string) ($_GET['compte'] ?? ''));
$fDateDebut = (string) ($_GET['date_debut'] ?? '');
$fDateFin = (string) ($_GET['date_fin'] ?? '');
$fRecherche = trim((string) ($_GET['q'] ?? ''));

$conditions = ['f.dossier_id = :dossier_id'];
$params = ['dossier_id' => $fDossier];
if ($fJournal !== '') { $conditions[] = 'e.journal_code = :journal'; $params['journal'] = $fJournal; }
if ($fCompte !== '') { $conditions[] = 'e.compte_num LIKE :compte'; $params['compte'] = $fCompte . '%'; }
if ($fDateDebut !== '') { $conditions[] = 'e.ecriture_date >= :date_debut'; $params['date_debut'] = $fDateDebut; }
if ($fDateFin !== '') { $conditions[] = 'e.ecriture_date <= :date_fin'; $params['date_fin'] = $fDateFin; }
if ($fRecherche !== '') {
    $conditions[] = '(e.ecriture_lib LIKE :q OR e.piece_ref LIKE :q OR e.ecriture_num LIKE :q)';
    $params['q'] = '%' . $fRecherche . '%';
}
$where = 'WHERE ' . implode(' AND ', $conditions);

// Limite raisonnable pour un export ponctuel (au-delà, l'utilisateur doit affiner ses filtres)
$sql = "SELECT e.journal_code, e.ecriture_num, e.ecriture_date, e.compte_num, e.compte_lib,
               e.piece_ref, e.piece_date, e.ecriture_lib, e.debit, e.credit, e.saisi_par
        FROM ecritures e JOIN fichiers_fec f ON f.id = e.fec_id
        $where ORDER BY e.ecriture_date, e.journal_code, e.ecriture_num
        LIMIT 50000";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$ecritures = $stmt->fetchAll();

$entetes = ['Journal', 'N° écriture', 'Date', 'Compte', 'Libellé compte', 'Pièce', 'Date pièce', 'Libellé écriture', 'Débit', 'Crédit', 'Saisi par'];
$lignes = [];
foreach ($ecritures as $e) {
    $lignes[] = [
        $e['journal_code'], $e['ecriture_num'], date('d/m/Y', strtotime($e['ecriture_date'])),
        $e['compte_num'], $e['compte_lib'], $e['piece_ref'], $e['piece_date'] ? date('d/m/Y', strtotime($e['piece_date'])) : '',
        $e['ecriture_lib'], number_format((float) $e['debit'], 2, ',', ''), number_format((float) $e['credit'], 2, ',', ''), $e['saisi_par'],
    ];
}

journaliser(utilisateurCourant()['id'], 'EXPORT_CSV', 'ecritures', (string) $fDossier, count($lignes) . ' lignes exportées');

exporterCSV('ecritures_dossier_' . $fDossier, $entetes, $lignes);

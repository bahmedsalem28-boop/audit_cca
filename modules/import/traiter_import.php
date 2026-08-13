<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit_logger.php';
require_once __DIR__ . '/../../includes/FecParser.php';
require_once __DIR__ . '/../../config/database.php';

exigerRole(['ADMIN', 'AVANCE']);

function rediriger_erreur(string $message): void
{
    header('Location: import_fec.php?statut=erreur&message=' . urlencode($message));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rediriger_erreur('Requête invalide.');
}

if (!csrf_verifier()) {
    rediriger_erreur('Jeton de sécurité invalide. Merci de réessayer.');
}

$pdo = Database::getConnexion();
$utilisateur = utilisateurCourant();

// --- Validation du dossier ---
$dossierId = filter_input(INPUT_POST, 'dossier_id', FILTER_VALIDATE_INT);
if (!$dossierId) {
    rediriger_erreur('Veuillez sélectionner un dossier d\'audit.');
}
$stmtDossier = $pdo->prepare('SELECT id FROM dossiers_audit WHERE id = :id');
$stmtDossier->execute(['id' => $dossierId]);
if (!$stmtDossier->fetch()) {
    rediriger_erreur('Dossier d\'audit introuvable.');
}

// --- Validation du fichier uploadé ---
if (!isset($_FILES['fichier_fec']) || $_FILES['fichier_fec']['error'] === UPLOAD_ERR_NO_FILE) {
    rediriger_erreur('Veuillez sélectionner un fichier FEC à importer.');
}

$fichier = $_FILES['fichier_fec'];

$messagesErreurUpload = [
    UPLOAD_ERR_INI_SIZE   => 'Le fichier dépasse la taille maximale autorisée par le serveur.',
    UPLOAD_ERR_FORM_SIZE  => 'Le fichier dépasse la taille maximale autorisée par le formulaire.',
    UPLOAD_ERR_PARTIAL    => 'Le fichier n\'a été que partiellement téléversé.',
    UPLOAD_ERR_NO_TMP_DIR => 'Erreur serveur : répertoire temporaire manquant.',
    UPLOAD_ERR_CANT_WRITE => 'Erreur serveur : échec d\'écriture du fichier.',
];
if ($fichier['error'] !== UPLOAD_ERR_OK) {
    rediriger_erreur($messagesErreurUpload[$fichier['error']] ?? 'Erreur lors du téléversement du fichier.');
}

$tailleMax = 20 * 1024 * 1024; // 20 Mo
if ($fichier['size'] > $tailleMax) {
    rediriger_erreur('Le fichier dépasse la taille maximale autorisée (20 Mo).');
}

$extension = strtolower(pathinfo($fichier['name'], PATHINFO_EXTENSION));
if (!in_array($extension, ['txt', 'csv'], true)) {
    rediriger_erreur('Format de fichier non autorisé. Seuls .txt et .csv sont acceptés.');
}

if (!is_uploaded_file($fichier['tmp_name'])) {
    rediriger_erreur('Fichier invalide.');
}

// --- Lecture et parsing du fichier ---
$contenu = file_get_contents($fichier['tmp_name']);
if ($contenu === false) {
    rediriger_erreur('Impossible de lire le fichier téléversé.');
}

$parser = new FecParser();
$succesParsing = $parser->parser($contenu);

if (empty($parser->lignes)) {
    // Enregistrer l'échec pour traçabilité
    $stmt = $pdo->prepare(
        'INSERT INTO fichiers_fec (dossier_id, nom_fichier, nb_lignes, statut_import, message_erreur, utilisateur_id)
         VALUES (:dossier_id, :nom, 0, :statut, :erreur, :uid)'
    );
    $stmt->execute([
        'dossier_id' => $dossierId,
        'nom'        => $fichier['name'],
        'statut'     => 'erreur',
        'erreur'     => implode(' | ', array_slice($parser->erreurs, 0, 5)),
        'uid'        => $utilisateur['id'],
    ]);
    journaliser($utilisateur['id'], 'IMPORT_FEC_ECHEC', 'fichiers_fec', (string) $pdo->lastInsertId(), 'Aucune ligne exploitable dans le fichier');
    rediriger_erreur('Import impossible : ' . (implode(' ', array_slice($parser->erreurs, 0, 3)) ?: 'fichier illisible.'));
}

// --- Déplacement du fichier vers le stockage protégé ---
$nomStocke = date('Ymd_His') . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $fichier['name']);
$cheminStockage = __DIR__ . '/../../uploads/fec/' . $nomStocke;
@move_uploaded_file($fichier['tmp_name'], $cheminStockage);

// --- Insertion en base (transaction) ---
try {
    $pdo->beginTransaction();

    $stmtFec = $pdo->prepare(
        'INSERT INTO fichiers_fec (dossier_id, nom_fichier, chemin_stockage, nb_lignes, statut_import, utilisateur_id)
         VALUES (:dossier_id, :nom, :chemin, :nb, :statut, :uid)'
    );
    $stmtFec->execute([
        'dossier_id' => $dossierId,
        'nom'        => $fichier['name'],
        'chemin'     => 'uploads/fec/' . $nomStocke,
        'nb'         => count($parser->lignes),
        'statut'     => 'importe',
        'uid'        => $utilisateur['id'],
    ]);
    $fecId = (int) $pdo->lastInsertId();

    $stmtEcriture = $pdo->prepare(
        'INSERT INTO ecritures
            (fec_id, journal_code, journal_lib, ecriture_num, ecriture_date, compte_num, compte_lib,
             comp_aux_num, comp_aux_lib, piece_ref, piece_date, ecriture_lib, debit, credit,
             lettrage, date_lettrage, valid_date, montant_devise, idevise, saisi_par)
         VALUES
            (:fec_id, :journal_code, :journal_lib, :ecriture_num, :ecriture_date, :compte_num, :compte_lib,
             :comp_aux_num, :comp_aux_lib, :piece_ref, :piece_date, :ecriture_lib, :debit, :credit,
             :lettrage, :date_lettrage, :valid_date, :montant_devise, :idevise, :saisi_par)'
    );

    foreach ($parser->lignes as $ligne) {
        $stmtEcriture->execute([
            'fec_id'         => $fecId,
            'journal_code'   => $ligne['journal_code'],
            'journal_lib'    => $ligne['journal_lib'],
            'ecriture_num'   => $ligne['ecriture_num'],
            'ecriture_date'  => $ligne['ecriture_date'],
            'compte_num'     => $ligne['compte_num'],
            'compte_lib'     => $ligne['compte_lib'],
            'comp_aux_num'   => $ligne['comp_aux_num'],
            'comp_aux_lib'   => $ligne['comp_aux_lib'],
            'piece_ref'      => $ligne['piece_ref'],
            'piece_date'     => $ligne['piece_date'],
            'ecriture_lib'   => $ligne['ecriture_lib'],
            'debit'          => $ligne['debit'],
            'credit'         => $ligne['credit'],
            'lettrage'       => $ligne['lettrage'],
            'date_lettrage'  => $ligne['date_lettrage'],
            'valid_date'     => $ligne['valid_date'],
            'montant_devise' => $ligne['montant_devise'],
            'idevise'        => $ligne['idevise'],
            'saisi_par'      => $ligne['saisi_par'],
        ]);
    }

    // --- Contrôle d'équilibre PAR ÉCRITURE et enregistrement des anomalies ---
    $stmtDeseq = $pdo->prepare(
        "SELECT ecriture_num, journal_code, SUM(debit)-SUM(credit) AS ecart, MIN(id) AS premiere_ligne_id
         FROM ecritures WHERE fec_id = :fec_id
         GROUP BY ecriture_num, journal_code
         HAVING ABS(SUM(debit) - SUM(credit)) > 0.01"
    );
    $stmtDeseq->execute(['fec_id' => $fecId]);
    $deseq = $stmtDeseq->fetchAll();

    if (!empty($deseq)) {
        $stmtTypeTest = $pdo->prepare("SELECT id FROM types_tests WHERE code = 'EQUILIBRE'");
        $stmtTypeTest->execute();
        $typeTestId = $stmtTypeTest->fetchColumn();

        $stmtAnomalie = $pdo->prepare(
            'INSERT INTO anomalies (dossier_id, ecriture_id, type_test_id, gravite, description)
             VALUES (:dossier_id, :ecriture_id, :type_test_id, :gravite, :description)'
        );
        $stmtResultat = $pdo->prepare(
            'INSERT INTO resultats_tests (ecriture_id, type_test_id, statut, score_risque, detail)
             VALUES (:ecriture_id, :type_test_id, :statut, :score, :detail)'
        );

        foreach ($deseq as $d) {
            $description = sprintf(
                "Écriture %s (journal %s) déséquilibrée : écart de %s",
                $d['ecriture_num'], $d['journal_code'], number_format((float) $d['ecart'], 2, ',', ' ')
            );
            $stmtAnomalie->execute([
                'dossier_id'   => $dossierId,
                'ecriture_id'  => $d['premiere_ligne_id'],
                'type_test_id' => $typeTestId,
                'gravite'      => 'critique',
                'description'  => $description,
            ]);
            $stmtResultat->execute([
                'ecriture_id'  => $d['premiere_ligne_id'],
                'type_test_id' => $typeTestId,
                'statut'       => 'suspect',
                'score'        => 100,
                'detail'       => $description,
            ]);
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('Erreur import FEC : ' . $e->getMessage());
    rediriger_erreur('Erreur lors de l\'enregistrement en base de données. Import annulé.');
}

journaliser(
    $utilisateur['id'],
    'IMPORT_FEC',
    'fichiers_fec',
    (string) $fecId,
    sprintf('%d lignes importées, %d écriture(s) déséquilibrée(s)', count($parser->lignes), count($deseq))
);

header('Location: import_fec.php?statut=succes&fec_id=' . $fecId);
exit;

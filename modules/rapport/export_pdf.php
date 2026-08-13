<?php
// Empêche tout avertissement/notice PHP de polluer le flux binaire du PDF
ob_start();
ini_set('display_errors', '0');

require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_logger.php';
require_once __DIR__ . '/../../includes/cycles.php';
require_once __DIR__ . '/../../config/database.php';

exigerConnexion();

$dossierId = filter_input(INPUT_GET, 'dossier_id', FILTER_VALIDATE_INT);
if (!$dossierId) {
    header('Location: rapport.php');
    exit;
}

$cheminFpdf = __DIR__ . '/../../vendor/fpdf/fpdf.php';
if (!file_exists($cheminFpdf)) {
    header('Location: rapport.php?dossier_id=' . $dossierId . '&erreur=fpdf_manquant');
    exit;
}
require_once $cheminFpdf;

$pdo = Database::getConnexion();

$stmtDossier = $pdo->prepare('SELECT nom_client, exercice, date_debut, date_fin FROM dossiers_audit WHERE id = :id');
$stmtDossier->execute(['id' => $dossierId]);
$dossier = $stmtDossier->fetch();
if (!$dossier) {
    header('Location: rapport.php');
    exit;
}

$stmt = $pdo->prepare(
    "SELECT a.gravite, a.description, a.date_detection, a.statut_traitement,
            tt.libelle AS test_libelle, e.journal_code, e.compte_num, e.ecriture_num
     FROM anomalies a
     JOIN types_tests tt ON tt.id = a.type_test_id
     LEFT JOIN ecritures e ON e.id = a.ecriture_id
     WHERE a.dossier_id = :d
     ORDER BY FIELD(a.gravite,'critique','elevee','moyenne','faible'), a.date_detection DESC"
);
$stmt->execute(['d' => $dossierId]);
$anomalies = $stmt->fetchAll();

$parGravite = ['critique' => 0, 'elevee' => 0, 'moyenne' => 0, 'faible' => 0];
$parCycle = [];
foreach ($anomalies as $a) {
    $parGravite[$a['gravite']]++;
    $cycle = determinerCycle($a['journal_code'], $a['compte_num']);
    $parCycle[$cycle] = ($parCycle[$cycle] ?? 0) + 1;
}

function nettoyerTexte(string $s): string
{
    // FPDF de base attend du Windows-1252 (pas d'UTF-8 natif sans police externe).
    // //IGNORE évite l'avertissement "illegal character" et supprime les caractères
    // non convertibles plutôt que de faire échouer la conversion.
    $resultat = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
    if ($resultat === false) {
        $resultat = @mb_convert_encoding($s, 'CP1252', 'UTF-8');
    }
    return $resultat !== false ? $resultat : preg_replace('/[^\x20-\x7E]/', '', $s);
}

class RapportPDF extends FPDF
{
    public string $client = '';
    public string $exercice = '';

    public function Header(): void
    {
        $this->SetFont('Helvetica', 'B', 14);
        $this->Cell(0, 8, nettoyerTexte("Rapport d'audit assiste par analyse de donnees"), 0, 1);
        $this->SetFont('Helvetica', '', 10);
        $this->SetTextColor(90, 90, 90);
        $this->Cell(0, 6, nettoyerTexte($this->client . ' - Exercice ' . $this->exercice), 0, 1);
        $this->SetTextColor(0, 0, 0);
        $this->Ln(2);
        $this->SetDrawColor(16, 27, 45);
        $this->SetLineWidth(0.5);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(4);
    }

    public function Footer(): void
    {
        $this->SetY(-15);
        $this->SetFont('Helvetica', 'I', 8);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb} - Genere le ' . date('d/m/Y H:i'), 0, 0, 'C');
    }
}

$pdf = new RapportPDF();
$pdf->client = $dossier['nom_client'];
$pdf->exercice = $dossier['exercice'];
$pdf->AliasNbPages();
$pdf->AddPage();

// --- Synthèse par gravité ---
$pdf->SetFont('Helvetica', 'B', 11);
$pdf->Cell(0, 8, 'Synthese des anomalies par gravite', 0, 1);
$pdf->SetFont('Helvetica', '', 10);

$couleurs = [
    'critique' => [165, 49, 42], 'elevee' => [192, 102, 42], 'moyenne' => [179, 144, 28], 'faible' => [95, 122, 95],
];
$libelles = ['critique' => 'Critique', 'elevee' => 'Elevee', 'moyenne' => 'Moyenne', 'faible' => 'Faible'];

foreach ($parGravite as $g => $nb) {
    [$r, $vv, $b] = $couleurs[$g];
    $pdf->SetFillColor($r, $vv, $b);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(35, 8, $libelles[$g], 0, 0, 'C', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(15, 8, (string) $nb, 0, 1);
}
$pdf->Ln(4);

// --- Cartographie par cycle (tableau textuel, FPDF de base ne trace pas de graphiques) ---
if (!empty($parCycle)) {
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->Cell(0, 8, 'Repartition des anomalies par cycle d\'audit', 0, 1);
    $pdf->SetFont('Helvetica', '', 10);
    arsort($parCycle);
    foreach ($parCycle as $cycle => $nb) {
        $pdf->Cell(90, 7, nettoyerTexte($cycle), 0, 0);
        $pdf->Cell(0, 7, (string) $nb . ' anomalie(s)', 0, 1);
    }
    $pdf->Ln(4);
}

// --- Détail hiérarchisé ---
$pdf->SetFont('Helvetica', 'B', 11);
$pdf->Cell(0, 8, "Detail des anomalies (hierarchise par gravite)", 0, 1);
$pdf->SetFont('Helvetica', '', 9);

foreach ($anomalies as $a) {
    [$r, $vv, $b] = $couleurs[$a['gravite']];
    $pdf->SetFillColor($r, $vv, $b);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->Cell(22, 6, $libelles[$a['gravite']], 0, 0, 'C', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Helvetica', 'B', 9);
    $entete = $a['ecriture_num'] ? ($a['journal_code'] . '/' . $a['ecriture_num']) : nettoyerTexte($a['test_libelle']);
    $pdf->Cell(0, 6, ' ' . nettoyerTexte($entete), 0, 1);

    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetX($pdf->GetX() + 22);
    $pdf->MultiCell(0, 5, nettoyerTexte($a['description']));
    $pdf->Ln(1);

    if ($pdf->GetY() > 260) {
        $pdf->AddPage();
    }
}

if (empty($anomalies)) {
    $pdf->SetFont('Helvetica', 'I', 10);
    $pdf->Cell(0, 8, 'Aucune anomalie enregistree pour ce dossier.', 0, 1);
}

require_once __DIR__ . '/../../includes/audit_logger.php';
journaliser(utilisateurCourant()['id'], 'EXPORT_PDF', 'dossiers_audit', (string) $dossierId, 'Export du rapport d\'audit en PDF');

$nomFichier = 'rapport_audit_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $dossier['nom_client']) . '_' . $dossier['exercice'] . '.pdf';

// Sécurité : purger tout contenu accidentellement déjà écrit avant d'envoyer le PDF
if (ob_get_length()) {
    ob_end_clean();
}

$pdf->Output('D', $nomFichier);
exit;

<?php
/**
 * export_csv.php — Génère un export CSV compatible Excel (FR) et termine le script.
 *
 * @param string $nomFichier Nom du fichier téléchargé (sans extension)
 * @param array  $entetes    Libellés des colonnes (ligne d'en-tête)
 * @param array  $lignes     Tableau de lignes (chaque ligne = tableau indexé dans le même ordre que $entetes)
 */
function exporterCSV(string $nomFichier, array $entetes, array $lignes): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_-]/', '_', $nomFichier) . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $sortie = fopen('php://output', 'w');
    fwrite($sortie, "\xEF\xBB\xBF"); // BOM UTF-8 : garantit l'affichage correct des accents dans Excel
    fputcsv($sortie, $entetes, ';');
    foreach ($lignes as $ligne) {
        fputcsv($sortie, $ligne, ';');
    }
    fclose($sortie);
    exit;
}

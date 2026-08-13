<?php
/**
 * tests_caat.php — Algorithmes de détection CAAT (lot 1 : tests "écriture individuelle")
 *
 * Chaque fonction :
 *  - nettoie les résultats précédents du même test pour le dossier (ré-exécution sûre)
 *  - détecte les anomalies via une requête SQL ciblée
 *  - alimente resultats_tests (score/detail par ligne) et anomalies (registre hiérarchisé)
 *  - retourne le nombre d'anomalies créées
 */

require_once __DIR__ . '/../config/database.php';

/** Jours fériés au Sénégal (dates fixes ; les fêtes mobiles doivent être mises à jour chaque année) */
function joursFeriesSenegal(): array
{
    return [
        '2025-01-01', // Jour de l'an
        '2025-04-04', // Fête de l'indépendance
        '2025-05-01', // Fête du travail
        '2025-08-15', // Assomption
        '2025-11-01', // Toussaint
        '2025-12-25', // Noël
        // Fêtes mobiles (Korité, Tabaski, Maouloud, Tamkharit) à ajouter manuellement
        // chaque année selon le calendrier officiel publié par le gouvernement.
    ];
}

function idTypeTest(PDO $pdo, string $code): ?int
{
    static $cache = [];
    if (!isset($cache[$code])) {
        $stmt = $pdo->prepare('SELECT id FROM types_tests WHERE code = :code');
        $stmt->execute(['code' => $code]);
        $val = $stmt->fetchColumn();
        $cache[$code] = $val !== false ? (int) $val : null;
    }
    return $cache[$code];
}

function seuilParametre(PDO $pdo, int $dossierId, string $code, float $defaut): float
{
    $stmt = $pdo->prepare(
        "SELECT pt.seuil FROM parametres_test pt
         JOIN types_tests tt ON tt.id = pt.type_test_id
         WHERE pt.dossier_id = :d AND tt.code = :c AND pt.actif = 1"
    );
    $stmt->execute(['d' => $dossierId, 'c' => $code]);
    $val = $stmt->fetchColumn();
    return ($val !== false && $val !== null) ? (float) $val : $defaut;
}

/** Supprime les résultats précédents du test pour ce dossier (permet une ré-exécution sûre) */
function nettoyerResultatsTest(PDO $pdo, int $dossierId, int $typeTestId): void
{
    $pdo->prepare('DELETE FROM anomalies WHERE dossier_id = :d AND type_test_id = :t')
        ->execute(['d' => $dossierId, 't' => $typeTestId]);

    $pdo->prepare(
        "DELETE rt FROM resultats_tests rt
         JOIN ecritures e ON e.id = rt.ecriture_id
         JOIN fichiers_fec f ON f.id = e.fec_id
         WHERE f.dossier_id = :d AND rt.type_test_id = :t"
    )->execute(['d' => $dossierId, 't' => $typeTestId]);
}

function inserer(PDO $pdo, int $dossierId, int $typeTestId, int $ecritureId, string $gravite, string $description, float $score): void
{
    $pdo->prepare(
        'INSERT INTO anomalies (dossier_id, ecriture_id, type_test_id, gravite, description) VALUES (:d, :e, :t, :g, :desc)'
    )->execute(['d' => $dossierId, 'e' => $ecritureId, 't' => $typeTestId, 'g' => $gravite, 'desc' => $description]);

    $pdo->prepare(
        'INSERT INTO resultats_tests (ecriture_id, type_test_id, statut, score_risque, detail) VALUES (:e, :t, "suspect", :s, :desc)'
    )->execute(['e' => $ecritureId, 't' => $typeTestId, 's' => $score, 'desc' => $description]);
}

// =====================================================================
// 1) DÉTECTION DES DOUBLONS (même compte, même montant, même pièce)
// =====================================================================
function testDoublons(PDO $pdo, int $dossierId): int
{
    $typeId = idTypeTest($pdo, 'DOUBLONS');
    nettoyerResultatsTest($pdo, $dossierId, $typeId);

    $stmt = $pdo->prepare(
        "SELECT compte_num, debit, credit, piece_ref, COUNT(*) AS nb, GROUP_CONCAT(id) AS ids
         FROM ecritures e JOIN fichiers_fec f ON f.id = e.fec_id
         WHERE f.dossier_id = :d AND piece_ref IS NOT NULL AND piece_ref <> ''
               AND (debit > 0 OR credit > 0)
         GROUP BY compte_num, debit, credit, piece_ref
         HAVING nb > 1"
    );
    $stmt->execute(['d' => $dossierId]);
    $groupes = $stmt->fetchAll();

    $total = 0;
    foreach ($groupes as $g) {
        $montant = $g['debit'] > 0 ? $g['debit'] : $g['credit'];
        $description = sprintf(
            "%d lignes identiques détectées : compte %s, montant %s, pièce %s",
            $g['nb'], $g['compte_num'], number_format((float) $montant, 2, ',', ' '), $g['piece_ref']
        );
        foreach (explode(',', $g['ids']) as $ecritureId) {
            inserer($pdo, $dossierId, $typeId, (int) $ecritureId, 'elevee', $description, 85);
            $total++;
        }
    }
    return $total;
}

// =====================================================================
// 2) ÉCRITURES ATYPIQUES : week-end et jours fériés
// =====================================================================
function testEcrituresAtypiques(PDO $pdo, int $dossierId): int
{
    $typeId = idTypeTest($pdo, 'WEEKEND');
    nettoyerResultatsTest($pdo, $dossierId, $typeId);

    $feries = joursFeriesSenegal();
    $placeholders = implode(',', array_fill(0, count($feries), '?'));

    $sql = "SELECT id, ecriture_date, ecriture_num, journal_code
            FROM ecritures e JOIN fichiers_fec f ON f.id = e.fec_id
            WHERE f.dossier_id = ? AND (DAYOFWEEK(ecriture_date) IN (1,7)"
            . (count($feries) ? " OR ecriture_date IN ($placeholders)" : '') . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$dossierId], $feries));
    $lignes = $stmt->fetchAll();

    $total = 0;
    foreach ($lignes as $l) {
        $jourFr = ['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'][date('w', strtotime($l['ecriture_date']))];
        $estFerie = in_array($l['ecriture_date'], $feries, true);
        $motif = $estFerie ? 'jour férié' : 'week-end (' . $jourFr . ')';
        $description = sprintf(
            "Écriture %s (journal %s) saisie un %s le %s", $l['ecriture_num'], $l['journal_code'], $motif,
            date('d/m/Y', strtotime($l['ecriture_date']))
        );
        inserer($pdo, $dossierId, $typeId, (int) $l['id'], 'moyenne', $description, 65);
        $total++;
    }
    return $total;
}

// =====================================================================
// 3) MONTANTS RONDS SUSPECTS
// =====================================================================
function testRoundNumbers(PDO $pdo, int $dossierId): int
{
    $typeId = idTypeTest($pdo, 'ROUND_NUMBER');
    nettoyerResultatsTest($pdo, $dossierId, $typeId);
    $seuil = seuilParametre($pdo, $dossierId, 'ROUND_NUMBER', 1000);

    $stmt = $pdo->prepare(
        "SELECT id, debit, credit, ecriture_num, journal_code
         FROM ecritures e JOIN fichiers_fec f ON f.id = e.fec_id
         WHERE f.dossier_id = :d
               AND ((debit >= :s1 AND MOD(debit, :s2) = 0) OR (credit >= :s3 AND MOD(credit, :s4) = 0))"
    );
    $stmt->execute(['d' => $dossierId, 's1' => $seuil, 's2' => $seuil, 's3' => $seuil, 's4' => $seuil]);
    $lignes = $stmt->fetchAll();

    $total = 0;
    foreach ($lignes as $l) {
        $montant = $l['debit'] > 0 ? $l['debit'] : $l['credit'];
        $description = sprintf(
            "Montant rond suspect (%s) sur l'écriture %s (journal %s)",
            number_format((float) $montant, 2, ',', ' '), $l['ecriture_num'], $l['journal_code']
        );
        inserer($pdo, $dossierId, $typeId, (int) $l['id'], 'faible', $description, 45);
        $total++;
    }
    return $total;
}

// =====================================================================
// 4) CHRONOLOGIE INVERSÉE (numéro d'écriture croissant, date qui recule)
// =====================================================================
function testChronologieInversee(PDO $pdo, int $dossierId): int
{
    $typeId = idTypeTest($pdo, 'CHRONO_INVERSEE');
    nettoyerResultatsTest($pdo, $dossierId, $typeId);

    $stmt = $pdo->prepare(
        "SELECT journal_code, ecriture_num, MIN(ecriture_date) AS date_min, MIN(id) AS ligne_id
         FROM ecritures e JOIN fichiers_fec f ON f.id = e.fec_id
         WHERE f.dossier_id = :d
         GROUP BY journal_code, ecriture_num"
    );
    $stmt->execute(['d' => $dossierId]);
    $lignes = $stmt->fetchAll();

    // Regrouper par journal, puis trier par ordre naturel du numéro d'écriture
    $parJournal = [];
    foreach ($lignes as $l) {
        $parJournal[$l['journal_code']][] = $l;
    }

    $total = 0;
    foreach ($parJournal as $journal => $ecrituresJournal) {
        usort($ecrituresJournal, fn($a, $b) => strnatcmp($a['ecriture_num'], $b['ecriture_num']));

        $dateMaxPrecedente = null;
        $numPrecedent = null;
        foreach ($ecrituresJournal as $e) {
            if ($dateMaxPrecedente !== null && $e['date_min'] < $dateMaxPrecedente) {
                $description = sprintf(
                    "Rupture de chronologie dans le journal %s : l'écriture %s (%s) est datée avant l'écriture précédente %s (%s)",
                    $journal, $e['ecriture_num'], date('d/m/Y', strtotime($e['date_min'])),
                    $numPrecedent, date('d/m/Y', strtotime($dateMaxPrecedente))
                );
                inserer($pdo, $dossierId, $typeId, (int) $e['ligne_id'], 'moyenne', $description, 55);
                $total++;
            } else {
                $dateMaxPrecedente = $e['date_min'];
                $numPrecedent = $e['ecriture_num'];
            }
        }
    }
    return $total;
}

// =====================================================================
// 5) ÉCRITURES RAPIDEMENT ANNULÉES (contre-passation à J+n)
// =====================================================================
function testAnnulationRapide(PDO $pdo, int $dossierId): int
{
    $typeId = idTypeTest($pdo, 'ANNULATION_RAPIDE');
    nettoyerResultatsTest($pdo, $dossierId, $typeId);
    $jours = (int) seuilParametre($pdo, $dossierId, 'ANNULATION_RAPIDE', 3);

    $stmt = $pdo->prepare(
        "SELECT e1.id AS id1, e2.id AS id2, e1.ecriture_num AS n1, e2.ecriture_num AS n2,
                e1.compte_num, e1.debit AS d1, e1.credit AS c1,
                e1.ecriture_date AS date1, e2.ecriture_date AS date2, e1.journal_code
         FROM ecritures e1
         JOIN ecritures e2
           ON e2.fec_id = e1.fec_id
          AND e2.compte_num = e1.compte_num
          AND e2.id > e1.id
          AND e2.debit = e1.credit AND e2.credit = e1.debit
          AND e1.ecriture_num <> e2.ecriture_num
         JOIN fichiers_fec f ON f.id = e1.fec_id
         WHERE f.dossier_id = :d
               AND (e1.debit > 0 OR e1.credit > 0)
               AND DATEDIFF(e2.ecriture_date, e1.ecriture_date) BETWEEN 0 AND :jours"
    );
    $stmt->execute(['d' => $dossierId, 'jours' => $jours]);
    $paires = $stmt->fetchAll();

    $total = 0;
    foreach ($paires as $p) {
        $montant = $p['d1'] > 0 ? $p['d1'] : $p['c1'];
        $description = sprintf(
            "Écriture %s (compte %s, %s) probablement contre-passée par l'écriture %s le %s (délai : %d jour(s))",
            $p['n1'], $p['compte_num'], number_format((float) $montant, 2, ',', ' '), $p['n2'],
            date('d/m/Y', strtotime($p['date2'])),
            (strtotime($p['date2']) - strtotime($p['date1'])) / 86400
        );
        inserer($pdo, $dossierId, $typeId, (int) $p['id1'], 'elevee', $description, 75);
        inserer($pdo, $dossierId, $typeId, (int) $p['id2'], 'elevee', $description, 75);
        $total += 2;
    }
    return $total;
}

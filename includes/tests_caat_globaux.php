<?php
/**
 * tests_caat_globaux.php — Lot 2 : tests d'analyse globale (non limités à une écriture)
 */

require_once __DIR__ . '/tests_caat.php'; // réutilise idTypeTest(), seuilParametre(), nettoyerResultatsTest(), inserer()

// =====================================================================
// TEST DE BENFORD (premiers chiffres significatifs)
// Méthodologie MAD (Mean Absolute Deviation) de Nigrini.
// =====================================================================
function calculerDistributionBenford(PDO $pdo, int $dossierId): array
{
    $stmt = $pdo->prepare(
        "SELECT CASE WHEN debit > 0 THEN debit ELSE credit END AS montant
         FROM ecritures e JOIN fichiers_fec f ON f.id = e.fec_id
         WHERE f.dossier_id = :d AND (debit > 0 OR credit > 0)"
    );
    $stmt->execute(['d' => $dossierId]);
    $montants = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $observes = array_fill(1, 9, 0);
    $n = 0;
    foreach ($montants as $m) {
        $m = abs((float) $m);
        if ($m < 1) {
            continue; // Benford s'applique aux montants >= 1
        }
        $premierChiffre = (int) substr((string) $m, 0, 1);
        if ($premierChiffre >= 1 && $premierChiffre <= 9) {
            $observes[$premierChiffre]++;
            $n++;
        }
    }

    $attendus = [1=>30.1,2=>17.6,3=>12.5,4=>9.7,5=>7.9,6=>6.7,7=>5.8,8=>5.1,9=>4.6];

    $distribution = [];
    $sommeEcarts = 0;
    foreach (range(1, 9) as $d) {
        $pctObs = $n > 0 ? ($observes[$d] / $n) * 100 : 0;
        $ecart = abs($pctObs - $attendus[$d]);
        $sommeEcarts += $ecart;
        $distribution[] = [
            'chiffre'    => $d,
            'observe'    => round($pctObs, 2),
            'attendu'    => $attendus[$d],
            'ecart'      => round($ecart, 2),
            'nb'         => $observes[$d],
        ];
    }
    $mad = $n > 0 ? round(($sommeEcarts / 100) / 9, 5) : 0; // MAD sur proportions (0-1)

    // Seuils de Nigrini pour le test du premier chiffre
    if ($mad < 0.006) {
        $conformite = 'conforme';
        $libelleConformite = 'Conformité proche';
    } elseif ($mad < 0.012) {
        $conformite = 'acceptable';
        $libelleConformite = 'Conformité acceptable';
    } elseif ($mad < 0.015) {
        $conformite = 'marginale';
        $libelleConformite = 'Conformité marginale';
    } else {
        $conformite = 'non_conforme';
        $libelleConformite = 'Non conforme';
    }

    return [
        'distribution' => $distribution,
        'n'            => $n,
        'mad'          => $mad,
        'conformite'   => $conformite,
        'libelle'      => $libelleConformite,
    ];
}

function testBenford(PDO $pdo, int $dossierId): int
{
    $typeId = idTypeTest($pdo, 'BENFORD');
    // Test global : on nettoie uniquement l'anomalie de synthèse précédente (ecriture_id NULL)
    $pdo->prepare('DELETE FROM anomalies WHERE dossier_id = :d AND type_test_id = :t AND ecriture_id IS NULL')
        ->execute(['d' => $dossierId, 't' => $typeId]);

    $resultat = calculerDistributionBenford($pdo, $dossierId);
    if ($resultat['n'] < 30) {
        return 0; // échantillon trop faible pour être significatif
    }
    if ($resultat['conformite'] === 'conforme' || $resultat['conformite'] === 'acceptable') {
        return 0;
    }

    $gravite = $resultat['conformite'] === 'non_conforme' ? 'elevee' : 'moyenne';
    $pireEcart = null;
    foreach ($resultat['distribution'] as $d) {
        if ($pireEcart === null || $d['ecart'] > $pireEcart['ecart']) {
            $pireEcart = $d;
        }
    }
    $description = sprintf(
        "Distribution des premiers chiffres non conforme à la loi de Benford (MAD = %.5f, %s). Écart le plus marqué : chiffre %d (observé %.1f%% vs attendu %.1f%%).",
        $resultat['mad'], $resultat['libelle'], $pireEcart['chiffre'], $pireEcart['observe'], $pireEcart['attendu']
    );

    $pdo->prepare('INSERT INTO anomalies (dossier_id, ecriture_id, type_test_id, gravite, description) VALUES (:d, NULL, :t, :g, :desc)')
        ->execute(['d' => $dossierId, 't' => $typeId, 'g' => $gravite, 'desc' => $description]);

    return 1;
}

// =====================================================================
// ANALYSE DES UTILISATEURS SAISISSEURS (top 10 en volume)
// =====================================================================
function calculerTopSaisisseurs(PDO $pdo, int $dossierId, int $limite = 10): array
{
    $stmt = $pdo->prepare(
        "SELECT saisi_par, COUNT(*) AS nb_lignes, COALESCE(SUM(debit+credit),0) AS volume
         FROM ecritures e JOIN fichiers_fec f ON f.id = e.fec_id
         WHERE f.dossier_id = :d AND saisi_par IS NOT NULL AND saisi_par <> ''
         GROUP BY saisi_par
         ORDER BY nb_lignes DESC
         LIMIT :lim"
    );
    $stmt->bindValue('d', $dossierId, PDO::PARAM_INT);
    $stmt->bindValue('lim', $limite, PDO::PARAM_INT);
    $stmt->execute();
    $top = $stmt->fetchAll();

    $stmtTotal = $pdo->prepare(
        "SELECT COUNT(*) FROM ecritures e JOIN fichiers_fec f ON f.id = e.fec_id
         WHERE f.dossier_id = :d AND saisi_par IS NOT NULL AND saisi_par <> ''"
    );
    $stmtTotal->execute(['d' => $dossierId]);
    $total = (int) $stmtTotal->fetchColumn();

    foreach ($top as &$t) {
        $t['pct'] = $total > 0 ? round(($t['nb_lignes'] / $total) * 100, 1) : 0;
    }
    return ['top' => $top, 'total' => $total];
}

function testTopSaisisseurs(PDO $pdo, int $dossierId): int
{
    $typeId = idTypeTest($pdo, 'TOP_SAISISSEURS');
    $pdo->prepare('DELETE FROM anomalies WHERE dossier_id = :d AND type_test_id = :t AND ecriture_id IS NULL')
        ->execute(['d' => $dossierId, 't' => $typeId]);

    $seuilPct = seuilParametre($pdo, $dossierId, 'TOP_SAISISSEURS', 40.0);
    $resultat = calculerTopSaisisseurs($pdo, $dossierId, 1);

    if (empty($resultat['top']) || $resultat['top'][0]['pct'] < $seuilPct) {
        return 0;
    }

    $premier = $resultat['top'][0];
    $description = sprintf(
        "Concentration anormale de la saisie : l'utilisateur « %s » a saisi %d écritures, soit %.1f%% du volume total (seuil : %.0f%%).",
        $premier['saisi_par'], $premier['nb_lignes'], $premier['pct'], $seuilPct
    );
    $pdo->prepare('INSERT INTO anomalies (dossier_id, ecriture_id, type_test_id, gravite, description) VALUES (:d, NULL, :t, "faible", :desc)')
        ->execute(['d' => $dossierId, 't' => $typeId, 'desc' => $description]);

    return 1;
}

// =====================================================================
// CONCENTRATION DE FIN DE PÉRIODE
// =====================================================================
function calculerConcentrationFinPeriode(PDO $pdo, int $dossierId): array
{
    $stmtDossier = $pdo->prepare('SELECT date_debut, date_fin FROM dossiers_audit WHERE id = :d');
    $stmtDossier->execute(['d' => $dossierId]);
    $dossier = $stmtDossier->fetch();

    $stmtParJour = $pdo->prepare(
        "SELECT ecriture_date, COUNT(*) AS nb
         FROM ecritures e JOIN fichiers_fec f ON f.id = e.fec_id
         WHERE f.dossier_id = :d
         GROUP BY ecriture_date
         ORDER BY ecriture_date"
    );
    $stmtParJour->execute(['d' => $dossierId]);
    $parJour = $stmtParJour->fetchAll();

    $total = array_sum(array_column($parJour, 'nb'));

    $dateFin = new DateTime($dossier['date_fin']);
    $dateSeuil = (clone $dateFin)->modify('-6 days'); // dernière semaine de la période

    $nbFinPeriode = 0;
    foreach ($parJour as $j) {
        $d = new DateTime($j['ecriture_date']);
        if ($d >= $dateSeuil && $d <= $dateFin) {
            $nbFinPeriode += (int) $j['nb'];
        }
    }
    $pct = $total > 0 ? round(($nbFinPeriode / $total) * 100, 1) : 0;

    return [
        'par_jour'      => $parJour,
        'total'         => $total,
        'nb_fin_periode'=> $nbFinPeriode,
        'pct_fin_periode' => $pct,
        'date_seuil'    => $dateSeuil->format('Y-m-d'),
        'date_fin'      => $dateFin->format('Y-m-d'),
    ];
}

function testConcentrationFinPeriode(PDO $pdo, int $dossierId): int
{
    $typeId = idTypeTest($pdo, 'FIN_PERIODE');
    $pdo->prepare('DELETE FROM anomalies WHERE dossier_id = :d AND type_test_id = :t AND ecriture_id IS NULL')
        ->execute(['d' => $dossierId, 't' => $typeId]);

    $seuil = seuilParametre($pdo, $dossierId, 'FIN_PERIODE', 20.0);
    $resultat = calculerConcentrationFinPeriode($pdo, $dossierId);

    if ($resultat['pct_fin_periode'] < $seuil) {
        return 0;
    }

    $description = sprintf(
        "Concentration anormale d'écritures en fin de période : %d écritures (%.1f%% du total) enregistrées entre le %s et le %s (seuil : %.0f%%).",
        $resultat['nb_fin_periode'], $resultat['pct_fin_periode'],
        date('d/m/Y', strtotime($resultat['date_seuil'])), date('d/m/Y', strtotime($resultat['date_fin'])), $seuil
    );
    $pdo->prepare('INSERT INTO anomalies (dossier_id, ecriture_id, type_test_id, gravite, description) VALUES (:d, NULL, :t, "moyenne", :desc)')
        ->execute(['d' => $dossierId, 't' => $typeId, 'desc' => $description]);

    return 1;
}

// =====================================================================
// SCORING DE RISQUE PAR ÉCRITURE (agrégation des résultats de tests)
// =====================================================================
function calculerScoringRisque(PDO $pdo, int $dossierId, int $limite = 50): array
{
    $stmt = $pdo->prepare(
        "SELECT e.id, e.ecriture_num, e.journal_code, e.compte_num, e.ecriture_date, e.debit, e.credit,
                COUNT(rt.id) AS nb_tests_suspects, COALESCE(SUM(rt.score_risque),0) AS score_total,
                GROUP_CONCAT(DISTINCT tt.libelle SEPARATOR ' · ') AS tests_declenches
         FROM ecritures e
         JOIN fichiers_fec f ON f.id = e.fec_id
         JOIN resultats_tests rt ON rt.ecriture_id = e.id AND rt.statut = 'suspect'
         JOIN types_tests tt ON tt.id = rt.type_test_id
         WHERE f.dossier_id = :d
         GROUP BY e.id
         ORDER BY score_total DESC, nb_tests_suspects DESC
         LIMIT :lim"
    );
    $stmt->bindValue('d', $dossierId, PDO::PARAM_INT);
    $stmt->bindValue('lim', $limite, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function testScoringRisque(PDO $pdo, int $dossierId): int
{
    $typeId = idTypeTest($pdo, 'SCORING_RISQUE');
    $pdo->prepare('DELETE FROM anomalies WHERE dossier_id = :d AND type_test_id = :t')
        ->execute(['d' => $dossierId, 't' => $typeId]);

    // On ne synthétise que les écritures cumulant AU MOINS 2 tests suspects (risque composite réel)
    $classement = calculerScoringRisque($pdo, $dossierId, 200);
    $total = 0;
    foreach ($classement as $c) {
        if ((int) $c['nb_tests_suspects'] < 2) {
            continue;
        }
        $gravite = $c['score_total'] >= 200 ? 'critique' : ($c['score_total'] >= 120 ? 'elevee' : 'moyenne');
        $description = sprintf(
            "Écriture %s (journal %s, compte %s) cumule %d signalements (score composite %.0f) : %s",
            $c['ecriture_num'], $c['journal_code'], $c['compte_num'], $c['nb_tests_suspects'],
            $c['score_total'], $c['tests_declenches']
        );
        $pdo->prepare('INSERT INTO anomalies (dossier_id, ecriture_id, type_test_id, gravite, description) VALUES (:d, :e, :t, :g, :desc)')
            ->execute(['d' => $dossierId, 'e' => $c['id'], 't' => $typeId, 'g' => $gravite, 'desc' => $description]);
        $total++;
    }
    return $total;
}

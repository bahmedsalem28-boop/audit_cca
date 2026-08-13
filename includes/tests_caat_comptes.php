<?php
/**
 * tests_caat_comptes.php — Lot 3 : analyse au niveau du compte
 */

require_once __DIR__ . '/tests_caat.php';

/** Retrouve le dossier de l'exercice précédent pour le même client (même nom_client, exercice - 1) */
function trouverDossierN1(PDO $pdo, int $dossierId): ?array
{
    $stmt = $pdo->prepare('SELECT nom_client, exercice FROM dossiers_audit WHERE id = :id');
    $stmt->execute(['id' => $dossierId]);
    $dossier = $stmt->fetch();
    if (!$dossier || !ctype_digit((string) $dossier['exercice'])) {
        return null;
    }
    $exerciceN1 = (string) ((int) $dossier['exercice'] - 1);

    $stmt = $pdo->prepare('SELECT id FROM dossiers_audit WHERE nom_client = :nom AND exercice = :ex LIMIT 1');
    $stmt->execute(['nom' => $dossier['nom_client'], 'ex' => $exerciceN1]);
    $row = $stmt->fetch();
    return $row ? ['id' => (int) $row['id'], 'exercice' => $exerciceN1] : null;
}

/** Soldes par compte (debit - credit) pour un dossier donné */
function soldesParCompte(PDO $pdo, int $dossierId): array
{
    $stmt = $pdo->prepare(
        "SELECT compte_num, MAX(compte_lib) AS compte_lib, SUM(debit) AS total_debit, SUM(credit) AS total_credit,
                SUM(debit) - SUM(credit) AS solde
         FROM ecritures e JOIN fichiers_fec f ON f.id = e.fec_id
         WHERE f.dossier_id = :d
         GROUP BY compte_num"
    );
    $stmt->execute(['d' => $dossierId]);
    $resultat = [];
    foreach ($stmt->fetchAll() as $r) {
        $resultat[$r['compte_num']] = $r;
    }
    return $resultat;
}

// =====================================================================
// REVUE ANALYTIQUE N vs N-1
// =====================================================================
function calculerRevueAnalytique(PDO $pdo, int $dossierId): array
{
    $dossierN1 = trouverDossierN1($pdo, $dossierId);
    if (!$dossierN1) {
        return ['disponible' => false, 'lignes' => []];
    }

    $soldesN  = soldesParCompte($pdo, $dossierId);
    $soldesN1 = soldesParCompte($pdo, $dossierN1['id']);

    $tousComptes = array_unique(array_merge(array_keys($soldesN), array_keys($soldesN1)));
    sort($tousComptes);

    $lignes = [];
    foreach ($tousComptes as $compte) {
        $soldeN  = isset($soldesN[$compte]) ? (float) $soldesN[$compte]['solde'] : 0.0;
        $soldeN1 = isset($soldesN1[$compte]) ? (float) $soldesN1[$compte]['solde'] : 0.0;
        $libelle = $soldesN[$compte]['compte_lib'] ?? ($soldesN1[$compte]['compte_lib'] ?? '');

        if (abs($soldeN1) > 0.01) {
            $variationPct = round((($soldeN - $soldeN1) / abs($soldeN1)) * 100, 1);
        } else {
            $variationPct = $soldeN != 0 ? null : 0.0; // null = "nouveau compte", variation non calculable en %
        }

        $lignes[] = [
            'compte'        => $compte,
            'libelle'       => $libelle,
            'solde_n'       => round($soldeN, 2),
            'solde_n1'      => round($soldeN1, 2),
            'variation_abs' => round($soldeN - $soldeN1, 2),
            'variation_pct' => $variationPct,
            'nouveau'       => !isset($soldesN1[$compte]),
            'disparu'       => !isset($soldesN[$compte]),
        ];
    }

    return ['disponible' => true, 'exercice_n1' => $dossierN1['exercice'], 'lignes' => $lignes];
}

function testRevueAnalytique(PDO $pdo, int $dossierId): int
{
    $typeId = idTypeTest($pdo, 'REVUE_ANALYTIQUE');
    $pdo->prepare('DELETE FROM anomalies WHERE dossier_id = :d AND type_test_id = :t')
        ->execute(['d' => $dossierId, 't' => $typeId]);

    $seuil = seuilParametre($pdo, $dossierId, 'REVUE_ANALYTIQUE', 30.0);
    $resultat = calculerRevueAnalytique($pdo, $dossierId);
    if (!$resultat['disponible']) {
        return 0;
    }

    $total = 0;
    foreach ($resultat['lignes'] as $l) {
        $depassement = ($l['variation_pct'] !== null && abs($l['variation_pct']) >= $seuil)
            || ($l['variation_pct'] === null && abs($l['solde_n']) > 0.01);

        if (!$depassement || abs($l['variation_abs']) < 100) { // ignorer les écarts négligeables en valeur absolue
            continue;
        }

        $description = $l['variation_pct'] !== null
            ? sprintf(
                "Compte %s (%s) : variation de %.1f%% entre N-1 (%s) et N (%s), écart de %s.",
                $l['compte'], $l['libelle'], $l['variation_pct'],
                number_format($l['solde_n1'], 2, ',', ' '), number_format($l['solde_n'], 2, ',', ' '),
                number_format($l['variation_abs'], 2, ',', ' ')
            )
            : sprintf(
                "Compte %s (%s) : nouveau compte mouvementé en N pour un solde de %s (aucune activité en N-1).",
                $l['compte'], $l['libelle'], number_format($l['solde_n'], 2, ',', ' ')
            );

        $pdo->prepare('INSERT INTO anomalies (dossier_id, ecriture_id, type_test_id, gravite, description) VALUES (:d, NULL, :t, "moyenne", :desc)')
            ->execute(['d' => $dossierId, 't' => $typeId, 'desc' => $description]);
        $total++;
    }
    return $total;
}

// =====================================================================
// SOLDES ANORMAUX (sens inhabituel selon la nature du compte)
// =====================================================================
function classeCompte(string $compte): string
{
    $prefixe = substr($compte, 0, 1);
    return match ($prefixe) {
        '4' => substr($compte, 0, 2) === '40' ? 'fournisseur' : (substr($compte, 0, 2) === '41' ? 'client' : 'tiers'),
        '5' => 'tresorerie',
        '6' => 'charge',
        '7' => 'produit',
        default => 'autre',
    };
}

function calculerSoldesAnormaux(PDO $pdo, int $dossierId): array
{
    $soldes = soldesParCompte($pdo, $dossierId);
    $anomalies = [];
    foreach ($soldes as $compte => $s) {
        $classe = classeCompte($compte);
        $solde = (float) $s['solde'];
        $motif = null;

        if ($classe === 'fournisseur' && $solde > 0.01) {
            $motif = 'Compte fournisseur anormalement débiteur (le fournisseur nous devrait de l\'argent)';
        } elseif ($classe === 'client' && $solde < -0.01) {
            $motif = 'Compte client anormalement créditeur (le client aurait trop payé ou avoir non justifié)';
        } elseif ($classe === 'charge' && $solde < -0.01) {
            $motif = 'Compte de charge anormalement créditeur';
        } elseif ($classe === 'produit' && $solde > 0.01) {
            $motif = 'Compte de produit anormalement débiteur';
        }

        if ($motif !== null) {
            $anomalies[] = [
                'compte' => $compte, 'libelle' => $s['compte_lib'], 'solde' => round($solde, 2), 'motif' => $motif,
            ];
        }
    }
    return $anomalies;
}

function testSoldesAnormaux(PDO $pdo, int $dossierId): int
{
    $typeId = idTypeTest($pdo, 'SOLDE_ANORMAL');
    $pdo->prepare('DELETE FROM anomalies WHERE dossier_id = :d AND type_test_id = :t')
        ->execute(['d' => $dossierId, 't' => $typeId]);

    $anomaliesSolde = calculerSoldesAnormaux($pdo, $dossierId);
    $total = 0;
    foreach ($anomaliesSolde as $a) {
        $description = sprintf(
            "%s : compte %s (%s), solde de %s.",
            $a['motif'], $a['compte'], $a['libelle'], number_format($a['solde'], 2, ',', ' ')
        );
        $pdo->prepare('INSERT INTO anomalies (dossier_id, ecriture_id, type_test_id, gravite, description) VALUES (:d, NULL, :t, "elevee", :desc)')
            ->execute(['d' => $dossierId, 't' => $typeId, 'desc' => $description]);
        $total++;
    }

    // --- Comptes sans mouvement : actifs en N-1 mais absents en N ---
    $dossierN1 = trouverDossierN1($pdo, $dossierId);
    if ($dossierN1) {
        $comptesN  = array_keys(soldesParCompte($pdo, $dossierId));
        $comptesN1 = soldesParCompte($pdo, $dossierN1['id']);
        foreach ($comptesN1 as $compte => $s) {
            if (!in_array($compte, $comptesN, true)) {
                $description = sprintf(
                    "Compte %s (%s) mouvementé en %s mais sans aucun mouvement sur l'exercice audité.",
                    $compte, $s['compte_lib'], $dossierN1['exercice']
                );
                $pdo->prepare('INSERT INTO anomalies (dossier_id, ecriture_id, type_test_id, gravite, description) VALUES (:d, NULL, :t, "faible", :desc)')
                    ->execute(['d' => $dossierId, 't' => $typeId, 'desc' => $description]);
                $total++;
            }
        }
    }

    return $total;
}

<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

exigerConnexion();
$pdo = Database::getConnexion();
$role = utilisateurCourant()['role_code'];

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    header('Location: liste.php');
    exit;
}

$stmt = $pdo->prepare(
    "SELECT e.*, d.nom_client, d.exercice, d.id AS dossier_id
     FROM ecritures e JOIN fichiers_fec f ON f.id = e.fec_id JOIN dossiers_audit d ON d.id = f.dossier_id
     WHERE e.id = :id"
);
$stmt->execute(['id' => $id]);
$ecriture = $stmt->fetch();
if (!$ecriture) {
    header('Location: liste.php');
    exit;
}

// Autres lignes de la même écriture (même numéro, même journal, même FEC)
$stmtLignes = $pdo->prepare(
    'SELECT * FROM ecritures WHERE fec_id = :fec_id AND ecriture_num = :num AND journal_code = :journal ORDER BY id'
);
$stmtLignes->execute(['fec_id' => $ecriture['fec_id'], 'num' => $ecriture['ecriture_num'], 'journal' => $ecriture['journal_code']]);
$lignes = $stmtLignes->fetchAll();

$stmtTests = $pdo->prepare(
    "SELECT rt.*, tt.libelle AS test_libelle FROM resultats_tests rt
     JOIN types_tests tt ON tt.id = rt.type_test_id
     WHERE rt.ecriture_id = :id ORDER BY rt.score_risque DESC"
);
$stmtTests->execute(['id' => $id]);
$resultatsTests = $stmtTests->fetchAll();

$titrePage = 'Écriture ' . $ecriture['ecriture_num'];
$pageActive = 'ecritures';
require __DIR__ . '/../../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../../includes/sidebar.php'; ?>
  <div class="contenu">
    <div class="topbar">
      <div>
        <h1 style="margin:0;font-size:22px;">Écriture <?= htmlspecialchars($ecriture['journal_code'] . ' / ' . $ecriture['ecriture_num'], ENT_QUOTES, 'UTF-8') ?></h1>
        <div style="color:var(--texte-att);font-size:13px;"><?= htmlspecialchars($ecriture['nom_client'] . ' — Exercice ' . $ecriture['exercice'], ENT_QUOTES, 'UTF-8') ?></div>
      </div>
      <div style="display:flex;gap:8px;">
        <?php if ($role === 'ADMIN'): ?>
          <a class="btn btn-secondaire" href="modifier.php?id=<?= (int) $id ?>">Modifier</a>
          <form method="post" action="supprimer.php" onsubmit="return confirm('Supprimer définitivement cette ligne d\'écriture ?');">
            <?php require_once __DIR__ . '/../../includes/csrf.php'; echo csrf_champ(); ?>
            <input type="hidden" name="id" value="<?= (int) $id ?>">
            <input type="hidden" name="dossier_id" value="<?= (int) $ecriture['dossier_id'] ?>">
            <button type="submit" class="btn btn-secondaire" style="color:var(--risque-critique);border-color:var(--risque-critique);">Supprimer</button>
          </form>
        <?php endif; ?>
        <a class="btn btn-secondaire" href="liste.php?dossier_id=<?= (int) $ecriture['dossier_id'] ?>">← Retour à la liste</a>
      </div>
    </div>

    <div class="carte">
      <h3 style="margin-top:0;font-size:15px;">Toutes les lignes de l'écriture <?= htmlspecialchars($ecriture['ecriture_num'], ENT_QUOTES, 'UTF-8') ?></h3>
      <table class="table-audit">
        <thead><tr><th>Compte</th><th>Libellé compte</th><th>Pièce</th><th>Date</th><th>Libellé écriture</th><th>Débit</th><th>Crédit</th></tr></thead>
        <tbody>
          <?php $totalDebit = 0; $totalCredit = 0; ?>
          <?php foreach ($lignes as $l): $totalDebit += (float) $l['debit']; $totalCredit += (float) $l['credit']; ?>
            <tr style="<?= (int) $l['id'] === $id ? 'background:var(--papier-2);' : '' ?>">
              <td><?= htmlspecialchars($l['compte_num'], ENT_QUOTES, 'UTF-8') ?></td>
              <td style="font-size:12.5px;"><?= htmlspecialchars($l['compte_lib'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($l['piece_ref'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= date('d/m/Y', strtotime($l['ecriture_date'])) ?></td>
              <td style="font-size:12.5px;"><?= htmlspecialchars($l['ecriture_lib'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
              <td class="montant"><?= $l['debit'] > 0 ? number_format((float) $l['debit'], 2, ',', ' ') : '' ?></td>
              <td class="montant"><?= $l['credit'] > 0 ? number_format((float) $l['credit'], 2, ',', ' ') : '' ?></td>
            </tr>
          <?php endforeach; ?>
          <tr style="font-weight:600;">
            <td colspan="5" style="text-align:right;">Total</td>
            <td class="montant"><?= number_format($totalDebit, 2, ',', ' ') ?></td>
            <td class="montant"><?= number_format($totalCredit, 2, ',', ' ') ?></td>
          </tr>
        </tbody>
      </table>
      <?php if (abs($totalDebit - $totalCredit) > 0.01): ?>
        <div class="alerte alerte-erreur" style="margin-top:12px;margin-bottom:0;">Écriture déséquilibrée : écart de <?= number_format($totalDebit - $totalCredit, 2, ',', ' ') ?>.</div>
      <?php else: ?>
        <div class="alerte alerte-succes" style="margin-top:12px;margin-bottom:0;">Écriture équilibrée.</div>
      <?php endif; ?>
    </div>

    <div class="carte">
      <h3 style="margin-top:0;font-size:15px;">Résultats des tests CAAT sur cette ligne</h3>
      <?php if (empty($resultatsTests)): ?>
        <div class="alerte alerte-info" style="margin-bottom:0;">Aucun test n'a signalé cette ligne pour le moment.</div>
      <?php else: ?>
        <table class="table-audit">
          <thead><tr><th>Test</th><th>Statut</th><th>Score de risque</th><th>Détail</th></tr></thead>
          <tbody>
            <?php foreach ($resultatsTests as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['test_libelle'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><span class="badge <?= $r['statut'] === 'suspect' ? 'badge-critique' : 'badge-ok' ?>"><?= $r['statut'] === 'suspect' ? 'Suspect' : 'Conforme' ?></span></td>
                <td class="montant"><?= number_format((float) $r['score_risque'], 0, ',', ' ') ?></td>
                <td style="font-size:12.5px;max-width:400px;"><?= htmlspecialchars($r['detail'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>

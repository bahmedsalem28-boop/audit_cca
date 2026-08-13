<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/tests_caat_globaux.php';
require_once __DIR__ . '/../../config/database.php';

exigerConnexion();
$pdo = Database::getConnexion();

$dossiers = $pdo->query('SELECT id, nom_client, exercice FROM dossiers_audit ORDER BY nom_client')->fetchAll();
$dossierId = filter_input(INPUT_GET, 'dossier_id', FILTER_VALIDATE_INT) ?: ($dossiers[0]['id'] ?? null);

$classement = $dossierId ? calculerScoringRisque($pdo, $dossierId, 40) : [];

$titrePage = 'Scoring de risque';
$pageActive = 'tests';
require __DIR__ . '/../../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../../includes/sidebar.php'; ?>
  <div class="contenu">
    <div class="topbar">
      <h1 style="margin:0;font-size:22px;">Scoring de risque par écriture</h1>
    </div>

    <div class="carte">
      <form method="get" style="display:flex;gap:10px;align-items:flex-end;">
        <div class="champ" style="margin-bottom:0;min-width:260px;">
          <label for="dossier_id">Dossier</label>
          <select name="dossier_id" id="dossier_id" onchange="this.form.submit()">
            <?php foreach ($dossiers as $d): ?>
              <option value="<?= (int) $d['id'] ?>" <?= (int) $d['id'] === (int) $dossierId ? 'selected' : '' ?>>
                <?= htmlspecialchars($d['nom_client'] . ' (' . $d['exercice'] . ')', ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </form>
    </div>

    <div class="carte">
      <?php if (empty($classement)): ?>
        <div class="alerte alerte-info">Aucune écriture signalée pour l'instant. Lancez d'abord la batterie de tests sur ce dossier.</div>
      <?php else: ?>
        <p style="color:var(--texte-att);font-size:13px;margin-top:0;">
          Score composite = somme des scores de risque de chaque test ayant signalé l'écriture. Classement des écritures les plus à risque.
        </p>
        <table class="table-audit">
          <thead><tr><th>#</th><th>Écriture</th><th>Compte</th><th>Date</th><th>Montant</th><th>Nb signalements</th><th>Score</th><th>Tests déclenchés</th></tr></thead>
          <tbody>
            <?php foreach ($classement as $i => $c): ?>
              <tr>
                <td><?= $i + 1 ?></td>
                <td><?= htmlspecialchars($c['journal_code'] . ' / ' . $c['ecriture_num'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($c['compte_num'], ENT_QUOTES, 'UTF-8') ?></td>
                <td style="white-space:nowrap;"><?= date('d/m/Y', strtotime($c['ecriture_date'])) ?></td>
                <td class="montant"><?= number_format((float) max($c['debit'], $c['credit']), 2, ',', ' ') ?></td>
                <td class="montant"><?= $c['nb_tests_suspects'] ?></td>
                <td class="montant" style="font-weight:600;color:<?= $c['score_total'] >= 200 ? 'var(--risque-critique)' : ($c['score_total'] >= 120 ? 'var(--risque-elevee)' : 'var(--texte)') ?>;">
                  <?= number_format((float) $c['score_total'], 0, ',', ' ') ?>
                </td>
                <td style="font-size:12px;max-width:280px;"><?= htmlspecialchars($c['tests_declenches'], ENT_QUOTES, 'UTF-8') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/tests_caat_globaux.php';
require_once __DIR__ . '/../../config/database.php';

exigerConnexion();
$pdo = Database::getConnexion();

$dossiers = $pdo->query('SELECT id, nom_client, exercice FROM dossiers_audit ORDER BY nom_client')->fetchAll();
$dossierId = filter_input(INPUT_GET, 'dossier_id', FILTER_VALIDATE_INT) ?: ($dossiers[0]['id'] ?? null);

$resultat = $dossierId ? calculerTopSaisisseurs($pdo, $dossierId, 10) : ['top' => [], 'total' => 0];

$titrePage = 'Top saisisseurs';
$pageActive = 'tests';
require __DIR__ . '/../../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../../includes/sidebar.php'; ?>
  <div class="contenu">
    <div class="topbar">
      <h1 style="margin:0;font-size:22px;">Analyse des utilisateurs saisisseurs</h1>
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

    <?php if (empty($resultat['top'])): ?>
      <div class="alerte alerte-info">Aucune information de saisisseur disponible pour ce dossier (champ non renseigné dans le FEC importé).</div>
    <?php else: ?>
      <div class="carte">
        <h3 style="margin-top:0;font-size:15px;">Top <?= count($resultat['top']) ?> saisisseurs — <?= number_format($resultat['total'], 0, ',', ' ') ?> lignes au total</h3>
        <canvas id="graphSaisisseurs" height="110"></canvas>
      </div>

      <div class="carte">
        <table class="table-audit">
          <thead><tr><th>#</th><th>Saisisseur</th><th>Nb lignes</th><th>% du volume</th><th>Montant cumulé (débit+crédit)</th></tr></thead>
          <tbody>
            <?php foreach ($resultat['top'] as $i => $t): ?>
              <tr>
                <td><?= $i + 1 ?></td>
                <td><?= htmlspecialchars($t['saisi_par'], ENT_QUOTES, 'UTF-8') ?></td>
                <td class="montant"><?= number_format($t['nb_lignes'], 0, ',', ' ') ?></td>
                <td class="montant" style="<?= $t['pct'] >= 40 ? 'color:var(--risque-elevee);font-weight:600;' : '' ?>"><?= number_format($t['pct'], 1, ',', ' ') ?> %</td>
                <td class="montant"><?= number_format((float) $t['volume'], 2, ',', ' ') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($resultat['top'])): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
  new Chart(document.getElementById('graphSaisisseurs'), {
    type: 'bar',
    data: {
      labels: <?= json_encode(array_column($resultat['top'], 'saisi_par')) ?>,
      datasets: [{ label: 'Nombre de lignes saisies', data: <?= json_encode(array_map('intval', array_column($resultat['top'], 'nb_lignes'))) ?>, backgroundColor: '#1c2d47' }]
    },
    options: { indexAxis: 'y', plugins: { legend: { display: false } } }
  });
</script>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/tests_caat_comptes.php';
require_once __DIR__ . '/../../config/database.php';

exigerConnexion();
$pdo = Database::getConnexion();

$dossiers = $pdo->query('SELECT id, nom_client, exercice FROM dossiers_audit ORDER BY nom_client')->fetchAll();
$dossierId = filter_input(INPUT_GET, 'dossier_id', FILTER_VALIDATE_INT) ?: ($dossiers[0]['id'] ?? null);

$resultat = $dossierId ? calculerRevueAnalytique($pdo, $dossierId) : ['disponible' => false, 'lignes' => []];
$seuilAffiche = 30.0;

// Tri par variation absolue décroissante pour mettre en avant les écarts significatifs
if (!empty($resultat['lignes'])) {
    usort($resultat['lignes'], fn($a, $b) => abs($b['variation_abs']) <=> abs($a['variation_abs']));
}

$titrePage = 'Revue analytique N/N-1';
$pageActive = 'anomalies';
require __DIR__ . '/../../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../../includes/sidebar.php'; ?>
  <div class="contenu">
    <div class="topbar">
      <h1 style="margin:0;font-size:22px;">Revue analytique — variations N vs N-1 par compte</h1>
    </div>

    <div class="carte">
      <form method="get" style="display:flex;gap:10px;align-items:flex-end;">
        <div class="champ" style="margin-bottom:0;min-width:260px;">
          <label for="dossier_id">Dossier (exercice N)</label>
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

    <?php if (!$resultat['disponible']): ?>
      <div class="alerte alerte-info">
        Aucun exercice précédent (N-1) trouvé pour ce client. La revue analytique nécessite un dossier
        d'audit portant le même nom de client et l'exercice N-1 (ex : « 2024 » si le dossier actuel est « 2025 »).
      </div>
    <?php else: ?>
      <div class="carte">
        <div class="alerte alerte-info" style="margin-bottom:14px;">
          Comparaison avec l'exercice <?= htmlspecialchars($resultat['exercice_n1'], ENT_QUOTES, 'UTF-8') ?>.
          Seuil de signalement : variation ≥ <?= $seuilAffiche ?>% (et écart ≥ 100 en valeur absolue).
        </div>
        <canvas id="graphAnalytique" height="110"></canvas>
      </div>

      <div class="carte">
        <table class="table-audit">
          <thead><tr><th>Compte</th><th>Libellé</th><th>Solde N-1</th><th>Solde N</th><th>Écart</th><th>Variation</th></tr></thead>
          <tbody>
            <?php foreach ($resultat['lignes'] as $l): if (abs($l['variation_abs']) < 1) continue; ?>
              <?php
                $signale = ($l['variation_pct'] !== null && abs($l['variation_pct']) >= $seuilAffiche && abs($l['variation_abs']) >= 100)
                    || ($l['variation_pct'] === null && abs($l['solde_n']) > 0.01);
              ?>
              <tr>
                <td><?= htmlspecialchars($l['compte'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($l['libelle'], ENT_QUOTES, 'UTF-8') ?></td>
                <td class="montant"><?= number_format($l['solde_n1'], 2, ',', ' ') ?></td>
                <td class="montant"><?= number_format($l['solde_n'], 2, ',', ' ') ?></td>
                <td class="montant"><?= number_format($l['variation_abs'], 2, ',', ' ') ?></td>
                <td class="montant" style="<?= $signale ? 'color:var(--risque-elevee);font-weight:600;' : '' ?>">
                  <?= $l['variation_pct'] !== null ? number_format($l['variation_pct'], 1, ',', ' ') . ' %' : ($l['nouveau'] ? 'Nouveau compte' : '—') ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($resultat['disponible']): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
  const lignes = <?= json_encode(array_slice($resultat['lignes'], 0, 12)) ?>;
  new Chart(document.getElementById('graphAnalytique'), {
    type: 'bar',
    data: {
      labels: lignes.map(l => l.compte),
      datasets: [
        { label: 'Solde N-1', data: lignes.map(l => l.solde_n1), backgroundColor: '#b08d57' },
        { label: 'Solde N', data: lignes.map(l => l.solde_n), backgroundColor: '#1c2d47' }
      ]
    },
    options: { plugins: { title: { display: true, text: '12 comptes présentant les plus fortes variations' } } }
  });
</script>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>

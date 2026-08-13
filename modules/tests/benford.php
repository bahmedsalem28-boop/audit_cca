<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/tests_caat_globaux.php';
require_once __DIR__ . '/../../config/database.php';

exigerConnexion();
$pdo = Database::getConnexion();

$dossiers = $pdo->query('SELECT id, nom_client, exercice FROM dossiers_audit ORDER BY nom_client')->fetchAll();
$dossierId = filter_input(INPUT_GET, 'dossier_id', FILTER_VALIDATE_INT) ?: ($dossiers[0]['id'] ?? null);

$resultat = $dossierId ? calculerDistributionBenford($pdo, $dossierId) : null;

$couleursConformite = [
    'conforme' => 'ok', 'acceptable' => 'ok', 'marginale' => 'moyenne', 'non_conforme' => 'critique',
];

$titrePage = 'Test de Benford';
$pageActive = 'tests';
require __DIR__ . '/../../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../../includes/sidebar.php'; ?>
  <div class="contenu">
    <div class="topbar">
      <h1 style="margin:0;font-size:22px;">Test de Benford — premiers chiffres significatifs</h1>
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

    <?php if (!$resultat || $resultat['n'] < 30): ?>
      <div class="alerte alerte-info">Échantillon insuffisant (moins de 30 montants exploitables) pour un test de Benford significatif sur ce dossier.</div>
    <?php else: ?>
      <div class="grille-stats">
        <div class="stat">
          <div class="stat-label">Montants analysés</div>
          <div class="stat-valeur chiffre"><?= number_format($resultat['n'], 0, ',', ' ') ?></div>
        </div>
        <div class="stat">
          <div class="stat-label">MAD (Mean Absolute Deviation)</div>
          <div class="stat-valeur chiffre"><?= number_format($resultat['mad'], 5, ',', ' ') ?></div>
        </div>
        <div class="stat">
          <div class="stat-label">Conformité</div>
          <div class="stat-valeur"><span class="badge badge-<?= $couleursConformite[$resultat['conformite']] ?>"><?= htmlspecialchars($resultat['libelle'], ENT_QUOTES, 'UTF-8') ?></span></div>
        </div>
      </div>

      <div class="carte">
        <h3 style="margin-top:0;font-size:15px;">Distribution observée vs loi de Benford</h3>
        <canvas id="graphBenford" height="110"></canvas>
      </div>

      <div class="carte">
        <table class="table-audit">
          <thead><tr><th>Chiffre</th><th>Observé</th><th>Attendu (Benford)</th><th>Écart</th><th>Nb occurrences</th></tr></thead>
          <tbody>
            <?php foreach ($resultat['distribution'] as $d): ?>
              <tr>
                <td><?= $d['chiffre'] ?></td>
                <td class="montant"><?= number_format($d['observe'], 1, ',', ' ') ?> %</td>
                <td class="montant"><?= number_format($d['attendu'], 1, ',', ' ') ?> %</td>
                <td class="montant" style="<?= $d['ecart'] > 3 ? 'color:var(--risque-elevee);' : '' ?>"><?= number_format($d['ecart'], 1, ',', ' ') ?> pts</td>
                <td class="montant"><?= $d['nb'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($resultat && $resultat['n'] >= 30): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
  const labels = <?= json_encode(array_column($resultat['distribution'], 'chiffre')) ?>;
  const observe = <?= json_encode(array_column($resultat['distribution'], 'observe')) ?>;
  const attendu = <?= json_encode(array_column($resultat['distribution'], 'attendu')) ?>;

  new Chart(document.getElementById('graphBenford'), {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [
        { label: 'Observé (%)', data: observe, backgroundColor: '#1c2d47' },
        { label: 'Attendu Benford (%)', type: 'line', data: attendu, borderColor: '#b08d57', backgroundColor: '#b08d57', tension: 0.3, pointRadius: 3 }
      ]
    },
    options: { scales: { y: { beginAtZero: true, title: { display: true, text: '% des montants' } }, x: { title: { display: true, text: 'Premier chiffre significatif' } } } }
  });
</script>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>

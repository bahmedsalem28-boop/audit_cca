<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/tests_caat_globaux.php';
require_once __DIR__ . '/../../config/database.php';

exigerConnexion();
$pdo = Database::getConnexion();

$dossiers = $pdo->query('SELECT id, nom_client, exercice FROM dossiers_audit ORDER BY nom_client')->fetchAll();
$dossierId = filter_input(INPUT_GET, 'dossier_id', FILTER_VALIDATE_INT) ?: ($dossiers[0]['id'] ?? null);

$resultat = $dossierId ? calculerConcentrationFinPeriode($pdo, $dossierId) : null;

// Agrégation par mois pour une lecture plus lisible sur l'année
$parMois = [];
if ($resultat) {
    foreach ($resultat['par_jour'] as $j) {
        $mois = substr($j['ecriture_date'], 0, 7);
        $parMois[$mois] = ($parMois[$mois] ?? 0) + (int) $j['nb'];
    }
    ksort($parMois);
}

$titrePage = 'Concentration fin de période';
$pageActive = 'tests';
require __DIR__ . '/../../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../../includes/sidebar.php'; ?>
  <div class="contenu">
    <div class="topbar">
      <h1 style="margin:0;font-size:22px;">Analyse temporelle — concentration de fin de période</h1>
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

    <?php if ($resultat): ?>
      <div class="grille-stats">
        <div class="stat">
          <div class="stat-label">Total écritures</div>
          <div class="stat-valeur chiffre"><?= number_format($resultat['total'], 0, ',', ' ') ?></div>
        </div>
        <div class="stat">
          <div class="stat-label">Dans la dernière semaine de l'exercice</div>
          <div class="stat-valeur chiffre"><?= number_format($resultat['nb_fin_periode'], 0, ',', ' ') ?></div>
        </div>
        <div class="stat">
          <div class="stat-label">Part de la fin de période</div>
          <div class="stat-valeur chiffre" style="color:<?= $resultat['pct_fin_periode'] >= 20 ? 'var(--risque-elevee)' : 'var(--ok)' ?>;">
            <?= number_format($resultat['pct_fin_periode'], 1, ',', ' ') ?> %
          </div>
        </div>
      </div>

      <div class="carte">
        <h3 style="margin-top:0;font-size:15px;">Répartition mensuelle des écritures</h3>
        <canvas id="graphMois" height="100"></canvas>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($resultat): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
  new Chart(document.getElementById('graphMois'), {
    type: 'bar',
    data: {
      labels: <?= json_encode(array_keys($parMois)) ?>,
      datasets: [{ label: 'Écritures par mois', data: <?= json_encode(array_values($parMois)) ?>, backgroundColor: '#1c2d47' }]
    },
    options: { plugins: { legend: { display: false } } }
  });
</script>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>

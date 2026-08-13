<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

exigerConnexion();

$pdo = Database::getConnexion();
$u = utilisateurCourant();

// --- Statistiques générales (adaptées au périmètre visible par tous les rôles) ---
$nbDossiers   = (int) $pdo->query('SELECT COUNT(*) FROM dossiers_audit')->fetchColumn();
$nbEcritures  = (int) $pdo->query('SELECT COUNT(*) FROM ecritures')->fetchColumn();
$nbAnomalies  = (int) $pdo->query('SELECT COUNT(*) FROM anomalies')->fetchColumn();
$nbNonTraite  = (int) $pdo->query("SELECT COUNT(*) FROM anomalies WHERE statut_traitement = 'non_traite'")->fetchColumn();

// --- Répartition des anomalies par gravité (pour le graphique) ---
$stmtGravite = $pdo->query(
    "SELECT gravite, COUNT(*) AS nb FROM anomalies GROUP BY gravite
     ORDER BY FIELD(gravite, 'critique','elevee','moyenne','faible')"
);
$repartitionGravite = $stmtGravite->fetchAll();

// --- Top 5 des types de tests ayant détecté le plus d'anomalies ---
$stmtTypes = $pdo->query(
    "SELECT tt.libelle, COUNT(*) AS nb
     FROM anomalies a
     JOIN types_tests tt ON tt.id = a.type_test_id
     GROUP BY tt.id, tt.libelle
     ORDER BY nb DESC
     LIMIT 6"
);
$repartitionTypes = $stmtTypes->fetchAll();

$labelsGravite = array_map(fn($r) => ucfirst($r['gravite']), $repartitionGravite);
$valeursGravite = array_map(fn($r) => (int) $r['nb'], $repartitionGravite);

$labelsTypes = array_map(fn($r) => $r['libelle'], $repartitionTypes);
$valeursTypes = array_map(fn($r) => (int) $r['nb'], $repartitionTypes);

$titrePage = 'Tableau de bord';
$pageActive = 'dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>

  <div class="contenu">
    <div class="topbar">
      <div>
        <h1 style="margin:0;font-size:22px;">Vue d'ensemble</h1>
        <div style="color:var(--texte-att);font-size:13.5px;">
          Bienvenue, <?= htmlspecialchars($u['prenom'], ENT_QUOTES, 'UTF-8') ?>. Voici l'état des dossiers audités.
        </div>
      </div>
    </div>

    <div class="grille-stats">
      <div class="stat">
        <div class="stat-label">Dossiers d'audit</div>
        <div class="stat-valeur chiffre"><?= $nbDossiers ?></div>
      </div>
      <div class="stat">
        <div class="stat-label">Écritures analysées</div>
        <div class="stat-valeur chiffre"><?= number_format($nbEcritures, 0, ',', ' ') ?></div>
      </div>
      <div class="stat">
        <div class="stat-label">Anomalies détectées</div>
        <div class="stat-valeur chiffre"><?= $nbAnomalies ?></div>
      </div>
      <div class="stat">
        <div class="stat-label">Anomalies non traitées</div>
        <div class="stat-valeur chiffre" style="color:var(--risque-critique);"><?= $nbNonTraite ?></div>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
      <div class="carte">
        <h3 style="margin-top:0;font-size:15px;">Anomalies par niveau de gravité</h3>
        <canvas id="graphGravite" height="220"></canvas>
      </div>
      <div class="carte">
        <h3 style="margin-top:0;font-size:15px;">Anomalies par test CAAT</h3>
        <canvas id="graphTypes" height="220"></canvas>
      </div>
    </div>

    <div class="carte">
      <h3 style="margin-top:0;font-size:15px;">Accès rapide</h3>
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <a class="btn" href="<?= BASE_URL ?>/modules/anomalies/liste.php">Voir les anomalies</a>
        <a class="btn btn-secondaire" href="<?= BASE_URL ?>/modules/ecritures/liste.php">Consulter les écritures</a>
        <?php if (in_array($u['role_code'], ['ADMIN', 'AVANCE'], true)): ?>
          <a class="btn btn-secondaire" href="<?= BASE_URL ?>/modules/tests/lancer.php">Lancer une batterie de tests</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
  const couleursGravite = {
    'Critique': '#a5312a', 'Elevee': '#c0662a', 'Moyenne': '#b3901c', 'Faible': '#5f7a5f'
  };
  const labelsGravite = <?= json_encode($labelsGravite, JSON_UNESCAPED_UNICODE) ?>;
  const valeursGravite = <?= json_encode($valeursGravite) ?>;

  new Chart(document.getElementById('graphGravite'), {
    type: 'doughnut',
    data: {
      labels: labelsGravite,
      datasets: [{
        data: valeursGravite,
        backgroundColor: labelsGravite.map(l => couleursGravite[l] || '#999')
      }]
    },
    options: { plugins: { legend: { position: 'bottom' } } }
  });

  const labelsTypes = <?= json_encode($labelsTypes, JSON_UNESCAPED_UNICODE) ?>;
  const valeursTypes = <?= json_encode($valeursTypes) ?>;

  new Chart(document.getElementById('graphTypes'), {
    type: 'bar',
    data: {
      labels: labelsTypes,
      datasets: [{ label: 'Anomalies', data: valeursTypes, backgroundColor: '#1c2d47' }]
    },
    options: {
      plugins: { legend: { display: false } },
      scales: { x: { ticks: { autoSkip: false, maxRotation: 40, minRotation: 20 } } }
    }
  });
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>

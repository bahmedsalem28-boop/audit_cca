<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/cycles.php';
require_once __DIR__ . '/../../config/database.php';

exigerConnexion();
$pdo = Database::getConnexion();

$dossiers = $pdo->query('SELECT id, nom_client, exercice FROM dossiers_audit ORDER BY nom_client')->fetchAll();
$dossierId = filter_input(INPUT_GET, 'dossier_id', FILTER_VALIDATE_INT) ?: ($dossiers[0]['id'] ?? null);
$erreurGet = $_GET['erreur'] ?? null;

$dossierActuel = null;
foreach ($dossiers as $d) {
    if ((int) $d['id'] === (int) $dossierId) { $dossierActuel = $d; break; }
}

$anomalies = [];
if ($dossierId) {
    $stmt = $pdo->prepare(
        "SELECT a.id, a.gravite, a.description, a.date_detection, a.statut_traitement,
                tt.libelle AS test_libelle, e.journal_code, e.compte_num, e.ecriture_num
         FROM anomalies a
         JOIN types_tests tt ON tt.id = a.type_test_id
         LEFT JOIN ecritures e ON e.id = a.ecriture_id
         WHERE a.dossier_id = :d
         ORDER BY FIELD(a.gravite,'critique','elevee','moyenne','faible'), a.date_detection DESC"
    );
    $stmt->execute(['d' => $dossierId]);
    $anomalies = $stmt->fetchAll();
}

// --- Comptage par gravité ---
$parGravite = ['critique' => 0, 'elevee' => 0, 'moyenne' => 0, 'faible' => 0];
foreach ($anomalies as $a) {
    $parGravite[$a['gravite']]++;
}

// --- Cartographie par cycle ---
$parCycle = []; // cycle => ['critique'=>n,...,'score'=>n]
foreach ($anomalies as $a) {
    $cycle = determinerCycle($a['journal_code'], $a['compte_num']);
    if (!isset($parCycle[$cycle])) {
        $parCycle[$cycle] = ['critique' => 0, 'elevee' => 0, 'moyenne' => 0, 'faible' => 0, 'score' => 0, 'total' => 0];
    }
    $parCycle[$cycle][$a['gravite']]++;
    $parCycle[$cycle]['score'] += poidsGravite($a['gravite']);
    $parCycle[$cycle]['total']++;
}
uasort($parCycle, fn($a, $b) => $b['score'] <=> $a['score']);

$libellesGravite = ['critique' => 'Critique', 'elevee' => 'Élevée', 'moyenne' => 'Moyenne', 'faible' => 'Faible'];
$libellesStatut  = ['non_traite' => 'Non traité', 'en_cours' => 'En cours', 'traite' => 'Traité', 'ecarte' => 'Écarté'];

$titrePage = 'Rapport d\'audit';
$pageActive = 'rapport';
require __DIR__ . '/../../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../../includes/sidebar.php'; ?>
  <div class="contenu">
    <div class="topbar">
      <div>
        <h1 style="margin:0;font-size:22px;">Rapport d'audit assisté par données</h1>
        <?php if ($dossierActuel): ?>
          <div style="color:var(--texte-att);font-size:13.5px;">
            <?= htmlspecialchars($dossierActuel['nom_client'], ENT_QUOTES, 'UTF-8') ?> — Exercice <?= htmlspecialchars($dossierActuel['exercice'], ENT_QUOTES, 'UTF-8') ?>
          </div>
        <?php endif; ?>
      </div>
      <a class="btn" href="export_pdf.php?dossier_id=<?= (int) $dossierId ?>">Exporter en PDF</a>
    </div>

    <?php if ($erreurGet === 'fpdf_manquant'): ?>
      <div class="alerte alerte-erreur">
        La bibliothèque FPDF n'est pas installée. Téléchargez <code>fpdf.php</code> depuis
        <a href="http://www.fpdf.org/" target="_blank" rel="noopener">fpdf.org</a> et placez-le dans
        <code>vendor/fpdf/fpdf.php</code> à la racine du projet, puis réessayez.
      </div>
    <?php endif; ?>

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

    <div class="grille-stats">
      <div class="stat" style="border-left-color:var(--risque-critique);">
        <div class="stat-label">Critiques</div>
        <div class="stat-valeur chiffre"><?= $parGravite['critique'] ?></div>
      </div>
      <div class="stat" style="border-left-color:var(--risque-elevee);">
        <div class="stat-label">Élevées</div>
        <div class="stat-valeur chiffre"><?= $parGravite['elevee'] ?></div>
      </div>
      <div class="stat" style="border-left-color:var(--risque-moyenne);">
        <div class="stat-label">Moyennes</div>
        <div class="stat-valeur chiffre"><?= $parGravite['moyenne'] ?></div>
      </div>
      <div class="stat" style="border-left-color:var(--risque-faible);">
        <div class="stat-label">Faibles</div>
        <div class="stat-valeur chiffre"><?= $parGravite['faible'] ?></div>
      </div>
    </div>

    <div class="carte">
      <h3 style="margin-top:0;font-size:15px;">Cartographie visuelle des risques par cycle</h3>
      <?php if (empty($parCycle)): ?>
        <div class="alerte alerte-info" style="margin-bottom:0;">Aucune anomalie détectée pour ce dossier.</div>
      <?php else: ?>
        <canvas id="graphCycles" height="110"></canvas>
      <?php endif; ?>
    </div>

    <div class="carte">
      <h3 style="margin-top:0;font-size:15px;">Détail des anomalies (hiérarchisé par gravité)</h3>
      <table class="table-audit">
        <thead><tr><th>Gravité</th><th>Cycle</th><th>Test</th><th>Écriture</th><th>Description</th><th>Statut</th></tr></thead>
        <tbody>
          <?php foreach ($anomalies as $a): ?>
            <tr>
              <td><span class="badge badge-<?= $a['gravite'] ?>"><?= $libellesGravite[$a['gravite']] ?></span></td>
              <td style="font-size:12.5px;"><?= htmlspecialchars(determinerCycle($a['journal_code'], $a['compte_num']), ENT_QUOTES, 'UTF-8') ?></td>
              <td style="font-size:12.5px;"><?= htmlspecialchars($a['test_libelle'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= $a['ecriture_num'] ? htmlspecialchars($a['journal_code'] . '/' . $a['ecriture_num'], ENT_QUOTES, 'UTF-8') : '—' ?></td>
              <td style="font-size:13px;max-width:380px;"><?= htmlspecialchars($a['description'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><span class="badge badge-<?= ['non_traite'=>'critique','en_cours'=>'moyenne','traite'=>'ok','ecarte'=>'faible'][$a['statut_traitement']] ?>"><?= $libellesStatut[$a['statut_traitement']] ?></span></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($anomalies)): ?>
            <tr><td colspan="6" style="color:var(--texte-att);">Aucune anomalie enregistrée pour ce dossier.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if (!empty($parCycle)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
  const cycles = <?= json_encode(array_keys($parCycle), JSON_UNESCAPED_UNICODE) ?>;
  const critique = <?= json_encode(array_column($parCycle, 'critique')) ?>;
  const elevee   = <?= json_encode(array_column($parCycle, 'elevee')) ?>;
  const moyenne  = <?= json_encode(array_column($parCycle, 'moyenne')) ?>;
  const faible   = <?= json_encode(array_column($parCycle, 'faible')) ?>;

  new Chart(document.getElementById('graphCycles'), {
    type: 'bar',
    data: {
      labels: cycles,
      datasets: [
        { label: 'Critique', data: critique, backgroundColor: '#a5312a' },
        { label: 'Élevée', data: elevee, backgroundColor: '#c0662a' },
        { label: 'Moyenne', data: moyenne, backgroundColor: '#b3901c' },
        { label: 'Faible', data: faible, backgroundColor: '#5f7a5f' }
      ]
    },
    options: {
      scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { stepSize: 1 } } },
      plugins: { title: { display: true, text: 'Nombre d\'anomalies par cycle et par gravité' } }
    }
  });
</script>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>

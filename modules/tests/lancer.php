<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../config/database.php';

exigerRole(['ADMIN', 'AVANCE']);

$pdo = Database::getConnexion();
$dossiers = $pdo->query('SELECT id, nom_client, exercice FROM dossiers_audit ORDER BY nom_client')->fetchAll();

$dossierSelectionne = filter_input(INPUT_GET, 'dossier_id', FILTER_VALIDATE_INT) ?: ($dossiers[0]['id'] ?? null);

$groupesTests = [
    'Tests sur les écritures'  => ['DOUBLONS', 'WEEKEND', 'ROUND_NUMBER', 'CHRONO_INVERSEE', 'ANNULATION_RAPIDE'],
    'Tests d\'analyse globale' => ['BENFORD', 'TOP_SAISISSEURS', 'FIN_PERIODE', 'SCORING_RISQUE'],
    'Analyse des comptes'      => ['REVUE_ANALYTIQUE', 'SOLDE_ANORMAL'],
];

$stmtTests = $pdo->query('SELECT id, code, libelle, description, gravite_defaut FROM types_tests');
$tousLesTests = [];
foreach ($stmtTests->fetchAll() as $t) {
    $tousLesTests[$t['code']] = $t;
}

$statutGet = $_GET['statut'] ?? null;
$resumeGet = $_GET['resume'] ?? null;

$titrePage = 'Lancer les tests CAAT';
$pageActive = 'tests';
require __DIR__ . '/../../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../../includes/sidebar.php'; ?>
  <div class="contenu">
    <div class="topbar">
      <h1 style="margin:0;font-size:22px;">Batterie de tests CAAT — analyse des écritures</h1>
    </div>

    <?php if ($statutGet === 'succes'): ?>
      <div class="alerte alerte-succes">
        Tests exécutés avec succès. <?= htmlspecialchars($resumeGet ?? '', ENT_QUOTES, 'UTF-8') ?>
        — <a href="<?= BASE_URL ?>/modules/anomalies/liste.php?dossier_id=<?= (int) $dossierSelectionne ?>">Voir les anomalies détectées</a>.
      </div>
    <?php elseif ($statutGet === 'erreur'): ?>
      <div class="alerte alerte-erreur"><?= htmlspecialchars($_GET['message'] ?? 'Erreur lors de l\'exécution des tests.', ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="carte">
      <form method="post" action="executer_tests.php">
        <?= csrf_champ() ?>
        <div class="champ">
          <label for="dossier_id">Dossier d'audit</label>
          <select name="dossier_id" id="dossier_id" required>
            <?php foreach ($dossiers as $d): ?>
              <option value="<?= (int) $d['id'] ?>" <?= (int) $d['id'] === (int) $dossierSelectionne ? 'selected' : '' ?>>
                <?= htmlspecialchars($d['nom_client'] . ' — Exercice ' . $d['exercice'], ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <?php foreach ($groupesTests as $nomGroupe => $codes): ?>
          <label style="display:block;font-size:13px;color:var(--texte-att);margin:14px 0 8px;"><?= htmlspecialchars($nomGroupe, ENT_QUOTES, 'UTF-8') ?></label>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:8px;">
            <?php foreach ($codes as $code): if (!isset($tousLesTests[$code])) continue; $t = $tousLesTests[$code]; ?>
              <label style="display:flex;gap:10px;align-items:flex-start;border:1px solid var(--ligne);border-radius:3px;padding:12px;cursor:pointer;">
                <input type="checkbox" name="tests[]" value="<?= htmlspecialchars($t['code'], ENT_QUOTES, 'UTF-8') ?>" checked style="margin-top:3px;">
                <span>
                  <strong style="font-size:13.5px;"><?= htmlspecialchars($t['libelle'], ENT_QUOTES, 'UTF-8') ?></strong>
                  <span class="badge badge-<?= htmlspecialchars($t['gravite_defaut'], ENT_QUOTES, 'UTF-8') ?>" style="margin-left:6px;"><?= htmlspecialchars($t['gravite_defaut'], ENT_QUOTES, 'UTF-8') ?></span>
                  <br><span style="font-size:12.5px;color:var(--texte-att);"><?= htmlspecialchars($t['description'], ENT_QUOTES, 'UTF-8') ?></span>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>

        <button type="submit" class="btn" style="margin-top:8px;">Exécuter la batterie sélectionnée</button>
      </form>
    </div>

    <div class="carte">
      <h3 style="margin-top:0;font-size:15px;">Résultats détaillés &amp; visualisations</h3>
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <a class="btn btn-secondaire" href="<?= BASE_URL ?>/modules/tests/benford.php?dossier_id=<?= (int) $dossierSelectionne ?>">Test de Benford</a>
        <a class="btn btn-secondaire" href="<?= BASE_URL ?>/modules/tests/saisisseurs.php?dossier_id=<?= (int) $dossierSelectionne ?>">Top saisisseurs</a>
        <a class="btn btn-secondaire" href="<?= BASE_URL ?>/modules/tests/fin_periode.php?dossier_id=<?= (int) $dossierSelectionne ?>">Concentration fin de période</a>
        <a class="btn btn-secondaire" href="<?= BASE_URL ?>/modules/tests/scoring.php?dossier_id=<?= (int) $dossierSelectionne ?>">Scoring de risque</a>
        <a class="btn btn-secondaire" href="<?= BASE_URL ?>/modules/comptes/analytique.php?dossier_id=<?= (int) $dossierSelectionne ?>">Revue analytique N/N-1</a>
        <a class="btn btn-secondaire" href="<?= BASE_URL ?>/modules/comptes/soldes.php?dossier_id=<?= (int) $dossierSelectionne ?>">Soldes anormaux</a>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../config/database.php';

exigerRole(['ADMIN', 'AVANCE']);

$pdo = Database::getConnexion();

$dossiers = $pdo->query('SELECT id, nom_client, exercice FROM dossiers_audit ORDER BY nom_client')->fetchAll();

$historique = $pdo->query(
    "SELECT f.id, f.nom_fichier, f.date_import, f.nb_lignes, f.statut_import, f.message_erreur, d.nom_client, d.exercice
     FROM fichiers_fec f
     JOIN dossiers_audit d ON d.id = f.dossier_id
     ORDER BY f.date_import DESC
     LIMIT 10"
)->fetchAll();

// --- Résumé affiché après un import (via redirection avec ?fec_id=) ---
$resumeEquilibre = null;
$fecId = filter_input(INPUT_GET, 'fec_id', FILTER_VALIDATE_INT);
if ($fecId) {
    $stmtGlobal = $pdo->prepare('SELECT COUNT(*) AS nb, COALESCE(SUM(debit),0) AS total_debit, COALESCE(SUM(credit),0) AS total_credit FROM ecritures WHERE fec_id = :id');
    $stmtGlobal->execute(['id' => $fecId]);
    $global = $stmtGlobal->fetch();

    $stmtDeseq = $pdo->prepare(
        "SELECT ecriture_num, journal_code, SUM(debit) AS sd, SUM(credit) AS sc, SUM(debit)-SUM(credit) AS ecart
         FROM ecritures WHERE fec_id = :id
         GROUP BY ecriture_num, journal_code
         HAVING ABS(SUM(debit) - SUM(credit)) > 0.01
         ORDER BY ABS(SUM(debit) - SUM(credit)) DESC
         LIMIT 30"
    );
    $stmtDeseq->execute(['id' => $fecId]);
    $deseq = $stmtDeseq->fetchAll();

    $resumeEquilibre = [
        'nb_lignes'     => (int) $global['nb'],
        'total_debit'   => (float) $global['total_debit'],
        'total_credit'  => (float) $global['total_credit'],
        'ecart_global'  => round(((float) $global['total_debit']) - ((float) $global['total_credit']), 2),
        'deseq'         => $deseq,
    ];
}

$statutGet  = $_GET['statut'] ?? null;
$messageGet = $_GET['message'] ?? null;

$titrePage = 'Import FEC';
$pageActive = 'import';
require __DIR__ . '/../../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../../includes/sidebar.php'; ?>
  <div class="contenu">
    <div class="topbar">
      <h1 style="margin:0;font-size:22px;">Import d'un fichier d'écritures comptables (FEC)</h1>
    </div>

    <?php if ($statutGet === 'erreur'): ?>
      <div class="alerte alerte-erreur"><?= htmlspecialchars($messageGet ?? 'Une erreur est survenue lors de l\'import.', ENT_QUOTES, 'UTF-8') ?></div>
    <?php elseif ($statutGet === 'succes'): ?>
      <div class="alerte alerte-succes">Fichier importé avec succès. Contrôle d'équilibre effectué ci-dessous.</div>
    <?php endif; ?>

    <div class="carte">
      <h3 style="margin-top:0;font-size:15px;">Nouvel import</h3>
      <form method="post" action="traiter_import.php" enctype="multipart/form-data" id="formImportFec" novalidate>
        <?= csrf_champ() ?>
        <div class="champ">
          <label for="dossier_id">Dossier d'audit</label>
          <select name="dossier_id" id="dossier_id" required>
            <option value="">— Sélectionner un dossier —</option>
            <?php foreach ($dossiers as $d): ?>
              <option value="<?= (int) $d['id'] ?>">
                <?= htmlspecialchars($d['nom_client'] . ' — Exercice ' . $d['exercice'], ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="champ">
          <label for="fichier_fec">Fichier FEC (.txt ou .csv, encodage UTF-8 ou ISO-8859-1, 20 Mo max)</label>
          <input type="file" name="fichier_fec" id="fichier_fec" accept=".txt,.csv" required>
          <div class="erreur-champ" id="erreur-fichier"></div>
        </div>
        <button type="submit" class="btn">Importer et contrôler l'équilibre</button>
        <a href="<?= BASE_URL ?>/assets/exemples/exemple_FEC.txt" class="btn btn-secondaire" download>Télécharger un exemple de FEC</a>
      </form>
    </div>

    <?php if ($resumeEquilibre): ?>
      <div class="carte">
        <h3 style="margin-top:0;font-size:15px;">Résultat du contrôle d'équilibre — import #<?= $fecId ?></h3>
        <div class="grille-stats" style="margin-bottom:10px;">
          <div class="stat">
            <div class="stat-label">Lignes importées</div>
            <div class="stat-valeur chiffre"><?= number_format($resumeEquilibre['nb_lignes'], 0, ',', ' ') ?></div>
          </div>
          <div class="stat">
            <div class="stat-label">Total débit</div>
            <div class="stat-valeur chiffre"><?= number_format($resumeEquilibre['total_debit'], 2, ',', ' ') ?></div>
          </div>
          <div class="stat">
            <div class="stat-label">Total crédit</div>
            <div class="stat-valeur chiffre"><?= number_format($resumeEquilibre['total_credit'], 2, ',', ' ') ?></div>
          </div>
          <div class="stat">
            <div class="stat-label">Écart global</div>
            <div class="stat-valeur chiffre" style="color:<?= abs($resumeEquilibre['ecart_global']) < 0.01 ? 'var(--ok)' : 'var(--risque-critique)' ?>;">
              <?= number_format($resumeEquilibre['ecart_global'], 2, ',', ' ') ?>
            </div>
          </div>
        </div>

        <?php if (abs($resumeEquilibre['ecart_global']) < 0.01 && empty($resumeEquilibre['deseq'])): ?>
          <div class="alerte alerte-succes">Toutes les écritures sont équilibrées, et le FEC est équilibré globalement.</div>
        <?php else: ?>
          <div class="alerte alerte-erreur">
            <?= count($resumeEquilibre['deseq']) ?> écriture(s) déséquilibrée(s) détectée(s) — elles ont été
            automatiquement enregistrées dans le registre des anomalies (gravité critique).
          </div>
          <table class="table-audit">
            <thead>
              <tr><th>Journal</th><th>N° écriture</th><th>Total débit</th><th>Total crédit</th><th>Écart</th></tr>
            </thead>
            <tbody>
              <?php foreach ($resumeEquilibre['deseq'] as $ligne): ?>
                <tr>
                  <td><?= htmlspecialchars($ligne['journal_code'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars($ligne['ecriture_num'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td class="montant"><?= number_format((float) $ligne['sd'], 2, ',', ' ') ?></td>
                  <td class="montant"><?= number_format((float) $ligne['sc'], 2, ',', ' ') ?></td>
                  <td class="montant" style="color:var(--risque-critique);"><?= number_format((float) $ligne['ecart'], 2, ',', ' ') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="carte">
      <h3 style="margin-top:0;font-size:15px;">Historique des imports</h3>
      <table class="table-audit">
        <thead>
          <tr><th>Dossier</th><th>Fichier</th><th>Date</th><th>Lignes</th><th>Statut</th></tr>
        </thead>
        <tbody>
          <?php foreach ($historique as $h): ?>
            <tr>
              <td><?= htmlspecialchars($h['nom_client'] . ' (' . $h['exercice'] . ')', ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($h['nom_fichier'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($h['date_import'])), ENT_QUOTES, 'UTF-8') ?></td>
              <td class="montant"><?= number_format((int) $h['nb_lignes'], 0, ',', ' ') ?></td>
              <td>
                <?php if ($h['statut_import'] === 'importe'): ?>
                  <span class="badge badge-ok">Importé</span>
                <?php elseif ($h['statut_import'] === 'erreur'): ?>
                  <span class="badge badge-critique" title="<?= htmlspecialchars($h['message_erreur'] ?? '', ENT_QUOTES, 'UTF-8') ?>">Erreur</span>
                <?php else: ?>
                  <span class="badge badge-moyenne">En attente</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($historique)): ?>
            <tr><td colspan="5" style="color:var(--texte-att);">Aucun import pour le moment.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<script src="<?= BASE_URL ?>/assets/js/validation.js"></script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>

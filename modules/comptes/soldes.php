<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/tests_caat_comptes.php';
require_once __DIR__ . '/../../config/database.php';

exigerConnexion();
$pdo = Database::getConnexion();

$dossiers = $pdo->query('SELECT id, nom_client, exercice FROM dossiers_audit ORDER BY nom_client')->fetchAll();
$dossierId = filter_input(INPUT_GET, 'dossier_id', FILTER_VALIDATE_INT) ?: ($dossiers[0]['id'] ?? null);

$soldesAnormaux = $dossierId ? calculerSoldesAnormaux($pdo, $dossierId) : [];

$comptesSansMouvement = [];
if ($dossierId) {
    $dossierN1 = trouverDossierN1($pdo, $dossierId);
    if ($dossierN1) {
        $comptesN  = array_keys(soldesParCompte($pdo, $dossierId));
        $comptesN1 = soldesParCompte($pdo, $dossierN1['id']);
        foreach ($comptesN1 as $compte => $s) {
            if (!in_array($compte, $comptesN, true)) {
                $comptesSansMouvement[] = ['compte' => $compte, 'libelle' => $s['compte_lib'], 'exercice' => $dossierN1['exercice']];
            }
        }
    }
}

$titrePage = 'Soldes anormaux';
$pageActive = 'anomalies';
require __DIR__ . '/../../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../../includes/sidebar.php'; ?>
  <div class="contenu">
    <div class="topbar">
      <h1 style="margin:0;font-size:22px;">Soldes anormaux &amp; comptes sans mouvement</h1>
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
      <h3 style="margin-top:0;font-size:15px;">Comptes à solde de sens inhabituel</h3>
      <?php if (empty($soldesAnormaux)): ?>
        <div class="alerte alerte-succes" style="margin-bottom:0;">Aucun solde anormal détecté sur ce dossier.</div>
      <?php else: ?>
        <table class="table-audit">
          <thead><tr><th>Compte</th><th>Libellé</th><th>Solde</th><th>Motif</th></tr></thead>
          <tbody>
            <?php foreach ($soldesAnormaux as $a): ?>
              <tr>
                <td><?= htmlspecialchars($a['compte'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($a['libelle'], ENT_QUOTES, 'UTF-8') ?></td>
                <td class="montant" style="color:var(--risque-elevee);font-weight:600;"><?= number_format($a['solde'], 2, ',', ' ') ?></td>
                <td style="font-size:13px;"><?= htmlspecialchars($a['motif'], ENT_QUOTES, 'UTF-8') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="carte">
      <h3 style="margin-top:0;font-size:15px;">Comptes sans mouvement (actifs en N-1, absents en N)</h3>
      <?php if (empty($comptesSansMouvement)): ?>
        <div class="alerte alerte-info" style="margin-bottom:0;">Aucun exercice N-1 disponible pour comparaison, ou tous les comptes N-1 restent mouvementés.</div>
      <?php else: ?>
        <table class="table-audit">
          <thead><tr><th>Compte</th><th>Libellé</th><th>Dernier mouvement</th></tr></thead>
          <tbody>
            <?php foreach ($comptesSansMouvement as $c): ?>
              <tr>
                <td><?= htmlspecialchars($c['compte'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($c['libelle'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>Exercice <?= htmlspecialchars($c['exercice'], ENT_QUOTES, 'UTF-8') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit_logger.php';
require_once __DIR__ . '/../../config/database.php';

// Modifier des écritures comptables déjà importées est une opération sensible :
// réservée à l'administrateur, et systématiquement tracée dans le journal d'audit.
exigerRole(['ADMIN']);
$pdo = Database::getConnexion();
$moi = utilisateurCourant();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    header('Location: liste.php');
    exit;
}

$stmt = $pdo->prepare(
    "SELECT e.*, d.id AS dossier_id, d.nom_client FROM ecritures e
     JOIN fichiers_fec f ON f.id = e.fec_id JOIN dossiers_audit d ON d.id = f.dossier_id
     WHERE e.id = :id"
);
$stmt->execute(['id' => $id]);
$ecriture = $stmt->fetch();
if (!$ecriture) {
    header('Location: liste.php');
    exit;
}

$erreurs = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verifier()) {
        $erreurs[] = 'Jeton de sécurité invalide. Merci de réessayer.';
    } else {
        $compteNum = trim((string) ($_POST['compte_num'] ?? ''));
        $ecritureLib = trim((string) ($_POST['ecriture_lib'] ?? ''));
        $pieceRef = trim((string) ($_POST['piece_ref'] ?? ''));
        $debit = str_replace(',', '.', trim((string) ($_POST['debit'] ?? '0')));
        $credit = str_replace(',', '.', trim((string) ($_POST['credit'] ?? '0')));

        if ($compteNum === '') {
            $erreurs[] = 'Le numéro de compte est obligatoire.';
        }
        if (!is_numeric($debit) || !is_numeric($credit) || (float) $debit < 0 || (float) $credit < 0) {
            $erreurs[] = 'Débit et crédit doivent être des montants positifs.';
        }
        if ((float) $debit > 0 && (float) $credit > 0) {
            $erreurs[] = 'Une ligne ne peut pas être à la fois débitrice et créditrice.';
        }

        if (empty($erreurs)) {
            $ancienneValeur = sprintf('compte=%s, debit=%s, credit=%s, libelle=%s', $ecriture['compte_num'], $ecriture['debit'], $ecriture['credit'], $ecriture['ecriture_lib']);

            $stmt = $pdo->prepare(
                'UPDATE ecritures SET compte_num = :c, ecriture_lib = :l, piece_ref = :p, debit = :d, credit = :cr WHERE id = :id'
            );
            $stmt->execute(['c' => $compteNum, 'l' => $ecritureLib, 'p' => $pieceRef, 'd' => $debit, 'cr' => $credit, 'id' => $id]);

            $nouvelleValeur = sprintf('compte=%s, debit=%s, credit=%s, libelle=%s', $compteNum, $debit, $credit, $ecritureLib);
            journaliser($moi['id'], 'MODIFICATION_ECRITURE', 'ecritures', (string) $id, "Avant: $ancienneValeur | Après: $nouvelleValeur");

            header('Location: voir.php?id=' . $id);
            exit;
        }
    }
}

$titrePage = 'Modifier l\'écriture ' . $ecriture['ecriture_num'];
$pageActive = 'ecritures';
require __DIR__ . '/../../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../../includes/sidebar.php'; ?>
  <div class="contenu">
    <div class="topbar">
      <h1 style="margin:0;font-size:22px;">Modifier l'écriture <?= htmlspecialchars($ecriture['journal_code'] . ' / ' . $ecriture['ecriture_num'], ENT_QUOTES, 'UTF-8') ?></h1>
    </div>

    <div class="alerte alerte-info">
      La modification d'une écriture comptable est une action sensible : elle sera enregistrée dans le journal d'audit avec la valeur avant/après.
    </div>

    <?php foreach ($erreurs as $e): ?>
      <div class="alerte alerte-erreur"><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endforeach; ?>

    <div class="carte" style="max-width:640px;">
      <form method="post" action="" id="formEcriture" novalidate>
        <?= csrf_champ() ?>
        <div class="champ">
          <label for="compte_num">Numéro de compte</label>
          <input type="text" name="compte_num" id="compte_num" required maxlength="20"
                 value="<?= htmlspecialchars($ecriture['compte_num'], ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="champ">
          <label for="piece_ref">Référence pièce</label>
          <input type="text" name="piece_ref" id="piece_ref" maxlength="50"
                 value="<?= htmlspecialchars($ecriture['piece_ref'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="champ">
          <label for="ecriture_lib">Libellé</label>
          <input type="text" name="ecriture_lib" id="ecriture_lib" maxlength="255"
                 value="<?= htmlspecialchars($ecriture['ecriture_lib'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div style="display:flex;gap:14px;">
          <div class="champ" style="flex:1;">
            <label for="debit">Débit</label>
            <input type="number" step="0.01" min="0" name="debit" id="debit" value="<?= htmlspecialchars((string) $ecriture['debit'], ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="champ" style="flex:1;">
            <label for="credit">Crédit</label>
            <input type="number" step="0.01" min="0" name="credit" id="credit" value="<?= htmlspecialchars((string) $ecriture['credit'], ENT_QUOTES, 'UTF-8') ?>">
          </div>
        </div>
        <button type="submit" class="btn">Enregistrer les modifications</button>
        <a href="voir.php?id=<?= (int) $id ?>" class="btn btn-secondaire">Annuler</a>
      </form>
    </div>
  </div>
</div>
<script>
document.getElementById('formEcriture').addEventListener('submit', function (e) {
  var debit = parseFloat(document.getElementById('debit').value || 0);
  var credit = parseFloat(document.getElementById('credit').value || 0);
  if (debit > 0 && credit > 0) {
    alert('Une ligne ne peut pas être à la fois débitrice et créditrice.');
    e.preventDefault();
  }
});
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>

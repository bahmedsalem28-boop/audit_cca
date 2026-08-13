<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit_logger.php';
require_once __DIR__ . '/../../config/database.php';

exigerRole(['ADMIN', 'AVANCE']);
$pdo = Database::getConnexion();
$utilisateur = utilisateurCourant();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: null;
$dossier = ['nom_client' => '', 'exercice' => '', 'date_debut' => '', 'date_fin' => '', 'statut' => 'ouvert', 'utilisateur_id' => $utilisateur['id']];
$erreurs = [];

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM dossiers_audit WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $existant = $stmt->fetch();
    if (!$existant) {
        header('Location: liste.php');
        exit;
    }
    $dossier = $existant;
}

$responsables = $pdo->query("SELECT id, nom, prenom FROM utilisateurs WHERE actif = 1 AND role_id IN (SELECT id FROM roles WHERE code IN ('ADMIN','AVANCE')) ORDER BY nom")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verifier()) {
        $erreurs[] = 'Jeton de sécurité invalide. Merci de réessayer.';
    } else {
        $dossier['nom_client'] = trim((string) ($_POST['nom_client'] ?? ''));
        $dossier['exercice'] = trim((string) ($_POST['exercice'] ?? ''));
        $dossier['date_debut'] = (string) ($_POST['date_debut'] ?? '');
        $dossier['date_fin'] = (string) ($_POST['date_fin'] ?? '');
        $dossier['statut'] = (string) ($_POST['statut'] ?? 'ouvert');
        $dossier['utilisateur_id'] = filter_input(INPUT_POST, 'utilisateur_id', FILTER_VALIDATE_INT);

        if ($dossier['nom_client'] === '' || mb_strlen($dossier['nom_client']) > 150) {
            $erreurs[] = 'Le nom du client est obligatoire (150 caractères max).';
        }
        if (!preg_match('/^\d{4}$/', $dossier['exercice'])) {
            $erreurs[] = 'L\'exercice doit être une année sur 4 chiffres (ex : 2025).';
        }
        $dDebut = DateTime::createFromFormat('Y-m-d', $dossier['date_debut']);
        $dFin = DateTime::createFromFormat('Y-m-d', $dossier['date_fin']);
        if (!$dDebut) {
            $erreurs[] = 'Date de début invalide.';
        }
        if (!$dFin) {
            $erreurs[] = 'Date de fin invalide.';
        }
        if ($dDebut && $dFin && $dFin < $dDebut) {
            $erreurs[] = 'La date de fin doit être postérieure à la date de début.';
        }
        if (!in_array($dossier['statut'], ['ouvert', 'en_cours', 'cloture'], true)) {
            $erreurs[] = 'Statut invalide.';
        }
        if (!$dossier['utilisateur_id']) {
            $erreurs[] = 'Veuillez sélectionner un responsable.';
        }

        if (empty($erreurs)) {
            if ($id) {
                $stmt = $pdo->prepare(
                    'UPDATE dossiers_audit SET nom_client=:nc, exercice=:ex, date_debut=:dd, date_fin=:df, statut=:st, utilisateur_id=:uid WHERE id=:id'
                );
                $stmt->execute([
                    'nc' => $dossier['nom_client'], 'ex' => $dossier['exercice'], 'dd' => $dossier['date_debut'],
                    'df' => $dossier['date_fin'], 'st' => $dossier['statut'], 'uid' => $dossier['utilisateur_id'], 'id' => $id,
                ]);
                journaliser($utilisateur['id'], 'MODIFICATION_DOSSIER', 'dossiers_audit', (string) $id, $dossier['nom_client']);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO dossiers_audit (nom_client, exercice, date_debut, date_fin, statut, utilisateur_id) VALUES (:nc,:ex,:dd,:df,:st,:uid)'
                );
                $stmt->execute([
                    'nc' => $dossier['nom_client'], 'ex' => $dossier['exercice'], 'dd' => $dossier['date_debut'],
                    'df' => $dossier['date_fin'], 'st' => $dossier['statut'], 'uid' => $dossier['utilisateur_id'],
                ]);
                $id = (int) $pdo->lastInsertId();
                journaliser($utilisateur['id'], 'CREATION_DOSSIER', 'dossiers_audit', (string) $id, $dossier['nom_client']);
            }
            header('Location: liste.php');
            exit;
        }
    }
}

$titrePage = $id ? 'Modifier un dossier' : 'Nouveau dossier';
$pageActive = 'dossiers';
require __DIR__ . '/../../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../../includes/sidebar.php'; ?>
  <div class="contenu">
    <div class="topbar">
      <h1 style="margin:0;font-size:22px;"><?= $id ? 'Modifier le dossier' : 'Nouveau dossier d\'audit' ?></h1>
    </div>

    <?php foreach ($erreurs as $e): ?>
      <div class="alerte alerte-erreur"><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endforeach; ?>

    <div class="carte" style="max-width:640px;">
      <form method="post" action="" id="formDossier" novalidate>
        <?= csrf_champ() ?>
        <div class="champ">
          <label for="nom_client">Nom du client</label>
          <input type="text" name="nom_client" id="nom_client" required maxlength="150"
                 value="<?= htmlspecialchars($dossier['nom_client'], ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="champ">
          <label for="exercice">Exercice (année)</label>
          <input type="text" name="exercice" id="exercice" required pattern="\d{4}" maxlength="4"
                 value="<?= htmlspecialchars($dossier['exercice'], ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div style="display:flex;gap:14px;">
          <div class="champ" style="flex:1;">
            <label for="date_debut">Date de début</label>
            <input type="date" name="date_debut" id="date_debut" required value="<?= htmlspecialchars($dossier['date_debut'], ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="champ" style="flex:1;">
            <label for="date_fin">Date de fin</label>
            <input type="date" name="date_fin" id="date_fin" required value="<?= htmlspecialchars($dossier['date_fin'], ENT_QUOTES, 'UTF-8') ?>">
          </div>
        </div>
        <div class="champ">
          <label for="statut">Statut</label>
          <select name="statut" id="statut">
            <option value="ouvert" <?= $dossier['statut'] === 'ouvert' ? 'selected' : '' ?>>Ouvert</option>
            <option value="en_cours" <?= $dossier['statut'] === 'en_cours' ? 'selected' : '' ?>>En cours</option>
            <option value="cloture" <?= $dossier['statut'] === 'cloture' ? 'selected' : '' ?>>Clôturé</option>
          </select>
        </div>
        <div class="champ">
          <label for="utilisateur_id">Responsable</label>
          <select name="utilisateur_id" id="utilisateur_id" required>
            <?php foreach ($responsables as $r): ?>
              <option value="<?= (int) $r['id'] ?>" <?= (int) $dossier['utilisateur_id'] === (int) $r['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($r['prenom'] . ' ' . $r['nom'], ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn"><?= $id ? 'Enregistrer les modifications' : 'Créer le dossier' ?></button>
        <a href="liste.php" class="btn btn-secondaire">Annuler</a>
      </form>
    </div>
  </div>
</div>
<script>
document.getElementById('formDossier').addEventListener('submit', function (e) {
  var debut = document.getElementById('date_debut').value;
  var fin = document.getElementById('date_fin').value;
  if (debut && fin && fin < debut) {
    alert('La date de fin doit être postérieure à la date de début.');
    e.preventDefault();
  }
});
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>

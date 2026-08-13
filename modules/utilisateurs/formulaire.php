<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit_logger.php';
require_once __DIR__ . '/../../config/database.php';

exigerRole(['ADMIN']);
$pdo = Database::getConnexion();
$moi = utilisateurCourant();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: null;
$user = ['nom' => '', 'prenom' => '', 'email' => '', 'role_id' => '', 'actif' => 1];
$erreurs = [];

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM utilisateurs WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $existant = $stmt->fetch();
    if (!$existant) {
        header('Location: liste.php');
        exit;
    }
    $user = $existant;
}

$roles = $pdo->query('SELECT id, libelle FROM roles ORDER BY id')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verifier()) {
        $erreurs[] = 'Jeton de sécurité invalide. Merci de réessayer.';
    } else {
        $user['nom'] = trim((string) ($_POST['nom'] ?? ''));
        $user['prenom'] = trim((string) ($_POST['prenom'] ?? ''));
        $user['email'] = trim((string) ($_POST['email'] ?? ''));
        $user['role_id'] = filter_input(INPUT_POST, 'role_id', FILTER_VALIDATE_INT);
        $user['actif'] = isset($_POST['actif']) ? 1 : 0;
        $motDePasse = (string) ($_POST['mot_de_passe'] ?? '');

        if ($user['nom'] === '' || $user['prenom'] === '') {
            $erreurs[] = 'Nom et prénom sont obligatoires.';
        }
        if (!filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
            $erreurs[] = 'Adresse email invalide.';
        } else {
            $stmtDoublon = $pdo->prepare('SELECT id FROM utilisateurs WHERE email = :email AND id <> :id');
            $stmtDoublon->execute(['email' => $user['email'], 'id' => $id ?: 0]);
            if ($stmtDoublon->fetch()) {
                $erreurs[] = 'Cette adresse email est déjà utilisée par un autre compte.';
            }
        }
        if (!$user['role_id']) {
            $erreurs[] = 'Veuillez sélectionner un rôle.';
        }
        if (!$id && $motDePasse === '') {
            $erreurs[] = 'Le mot de passe est obligatoire à la création.';
        }
        if ($motDePasse !== '' && mb_strlen($motDePasse) < 8) {
            $erreurs[] = 'Le mot de passe doit contenir au moins 8 caractères.';
        }
        if ($id && (int) $id === (int) $moi['id'] && !$user['actif']) {
            $erreurs[] = 'Vous ne pouvez pas désactiver votre propre compte.';
        }

        if (empty($erreurs)) {
            if ($id) {
                if ($motDePasse !== '') {
                    $stmt = $pdo->prepare('UPDATE utilisateurs SET nom=:n, prenom=:p, email=:e, role_id=:r, actif=:a, mot_de_passe_hash=:h WHERE id=:id');
                    $stmt->execute(['n' => $user['nom'], 'p' => $user['prenom'], 'e' => $user['email'], 'r' => $user['role_id'], 'a' => $user['actif'], 'h' => password_hash($motDePasse, PASSWORD_BCRYPT), 'id' => $id]);
                } else {
                    $stmt = $pdo->prepare('UPDATE utilisateurs SET nom=:n, prenom=:p, email=:e, role_id=:r, actif=:a WHERE id=:id');
                    $stmt->execute(['n' => $user['nom'], 'p' => $user['prenom'], 'e' => $user['email'], 'r' => $user['role_id'], 'a' => $user['actif'], 'id' => $id]);
                }
                journaliser($moi['id'], 'MODIFICATION_UTILISATEUR', 'utilisateurs', (string) $id, $user['email']);
            } else {
                $stmt = $pdo->prepare('INSERT INTO utilisateurs (nom, prenom, email, mot_de_passe_hash, role_id, actif) VALUES (:n,:p,:e,:h,:r,:a)');
                $stmt->execute(['n' => $user['nom'], 'p' => $user['prenom'], 'e' => $user['email'], 'h' => password_hash($motDePasse, PASSWORD_BCRYPT), 'r' => $user['role_id'], 'a' => $user['actif']]);
                $id = (int) $pdo->lastInsertId();
                journaliser($moi['id'], 'CREATION_UTILISATEUR', 'utilisateurs', (string) $id, $user['email']);
            }
            header('Location: liste.php');
            exit;
        }
    }
}

$titrePage = $id ? 'Modifier un utilisateur' : 'Nouvel utilisateur';
$pageActive = 'utilisateurs';
require __DIR__ . '/../../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../../includes/sidebar.php'; ?>
  <div class="contenu">
    <div class="topbar">
      <h1 style="margin:0;font-size:22px;"><?= $id ? 'Modifier l\'utilisateur' : 'Nouvel utilisateur' ?></h1>
    </div>

    <?php foreach ($erreurs as $e): ?>
      <div class="alerte alerte-erreur"><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endforeach; ?>

    <div class="carte" style="max-width:640px;">
      <form method="post" action="" id="formUtilisateur" novalidate>
        <?= csrf_champ() ?>
        <div style="display:flex;gap:14px;">
          <div class="champ" style="flex:1;">
            <label for="prenom">Prénom</label>
            <input type="text" name="prenom" id="prenom" required value="<?= htmlspecialchars($user['prenom'], ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="champ" style="flex:1;">
            <label for="nom">Nom</label>
            <input type="text" name="nom" id="nom" required value="<?= htmlspecialchars($user['nom'], ENT_QUOTES, 'UTF-8') ?>">
          </div>
        </div>
        <div class="champ">
          <label for="email">Email</label>
          <input type="email" name="email" id="email" required value="<?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="champ">
          <label for="mot_de_passe">Mot de passe <?= $id ? '(laisser vide pour ne pas changer)' : '' ?></label>
          <input type="password" name="mot_de_passe" id="mot_de_passe" <?= $id ? '' : 'required' ?> minlength="8" autocomplete="new-password">
          <div class="erreur-champ" id="erreur-mdp-form"></div>
        </div>
        <div class="champ">
          <label for="role_id">Rôle</label>
          <select name="role_id" id="role_id" required>
            <option value="">— Sélectionner —</option>
            <?php foreach ($roles as $r): ?>
              <option value="<?= (int) $r['id'] ?>" <?= (int) $user['role_id'] === (int) $r['id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['libelle'], ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="champ">
          <label style="display:flex;align-items:center;gap:8px;">
            <input type="checkbox" name="actif" value="1" <?= $user['actif'] ? 'checked' : '' ?> style="width:auto;">
            Compte actif
          </label>
        </div>
        <button type="submit" class="btn"><?= $id ? 'Enregistrer les modifications' : 'Créer l\'utilisateur' ?></button>
        <a href="liste.php" class="btn btn-secondaire">Annuler</a>
      </form>
    </div>
  </div>
</div>
<script>
document.getElementById('formUtilisateur').addEventListener('submit', function (e) {
  var mdp = document.getElementById('mot_de_passe');
  var erreur = document.getElementById('erreur-mdp-form');
  erreur.textContent = '';
  if (mdp.value !== '' && mdp.value.length < 8) {
    erreur.textContent = 'Le mot de passe doit contenir au moins 8 caractères.';
    e.preventDefault();
  }
});
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>

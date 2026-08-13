<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

// Déjà connecté ? on redirige directement vers le tableau de bord
if (utilisateurConnecte()) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$erreur = $_SESSION['erreur_session'] ?? null;
unset($_SESSION['erreur_session']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verifier()) {
        $erreur = 'Jeton de sécurité invalide. Merci de réessayer.';
    } else {
        $email = trim((string) filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL));
        $motDePasse = (string) ($_POST['mot_de_passe'] ?? '');

        if ($email === '' || $motDePasse === '') {
            $erreur = 'Veuillez renseigner votre email et votre mot de passe.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erreur = 'Adresse email invalide.';
        } else {
            $resultat = tenterConnexion($email, $motDePasse);
            if ($resultat['succes']) {
                header('Location: ' . BASE_URL . '/dashboard.php');
                exit;
            }
            $erreur = $resultat['message'];
        }
    }
}

$titrePage = 'Connexion';
require __DIR__ . '/includes/header.php';
?>
<div class="page-connexion">
  <div class="carte-connexion">
    <div class="sceau">A</div>
    <h1>Registre d'Audit CAAT</h1>
    <div class="sous-titre">Plateforme d'audit assisté par analyse de données</div>

    <?php if ($erreur): ?>
      <div class="alerte alerte-erreur"><?= htmlspecialchars($erreur, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post" action="" id="formConnexion" novalidate>
      <?= csrf_champ() ?>
      <div class="champ">
        <label for="email">Adresse email</label>
        <input type="email" id="email" name="email" required autocomplete="username"
               value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        <div class="erreur-champ" id="erreur-email"></div>
      </div>
      <div class="champ">
        <label for="mot_de_passe">Mot de passe</label>
        <input type="password" id="mot_de_passe" name="mot_de_passe" required autocomplete="current-password">
        <div class="erreur-champ" id="erreur-mdp"></div>
      </div>
      <button type="submit" class="btn" style="width:100%;">Se connecter</button>
    </form>
  </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/validation.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>

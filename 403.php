<?php
require_once __DIR__ . '/includes/session.php';
$titrePage = 'Accès refusé';
require __DIR__ . '/includes/header.php';
?>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;flex-direction:column;">
  <h1 style="font-size:48px;color:var(--encre-900);margin-bottom:6px;">403</h1>
  <p style="color:var(--texte-att);margin-bottom:18px;">Votre profil ne dispose pas des droits nécessaires pour accéder à cette page.</p>
  <a class="btn" href="<?= BASE_URL ?>/dashboard.php">Retour au tableau de bord</a>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

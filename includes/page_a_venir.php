<?php
/**
 * page_a_venir.php — Gabarit affiché pour les modules non encore développés.
 * Variables attendues avant inclusion : $titrePage, $pageActive, $descriptionModule
 */
require __DIR__ . '/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/sidebar.php'; ?>
  <div class="contenu">
    <div class="topbar">
      <h1 style="margin:0;font-size:22px;"><?= htmlspecialchars($titrePage, ENT_QUOTES, 'UTF-8') ?></h1>
    </div>
    <div class="carte">
      <div class="alerte alerte-info">
        Module en cours de construction — prochaine étape du projet.
      </div>
      <p style="color:var(--texte-att);"><?= htmlspecialchars($descriptionModule ?? '', ENT_QUOTES, 'UTF-8') ?></p>
    </div>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>

<?php
/**
 * sidebar.php — Nécessite $pageActive (string) et un utilisateur connecté.
 */
$u = utilisateurCourant();
$role = $u['role_code'];

function lienNav(string $cle, string $href, string $texte, string $pageActive): void
{
    $classe = ($cle === $pageActive) ? 'lien actif' : 'lien';
    echo '<a class="' . $classe . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($texte, ENT_QUOTES, 'UTF-8') . '</a>';
}
?>
<div class="sidebar">
  <div class="brand">
    <div class="brand-mark">A</div>
    <div class="brand-titre">Registre d'Audit<br>Assisté par Données</div>
  </div>
  <nav>
    <div class="groupe-titre">Tableau de bord</div>
    <?php lienNav('dashboard', BASE_URL . '/dashboard.php', 'Vue d\'ensemble', $pageActive); ?>

    <div class="groupe-titre">Dossiers &amp; écritures</div>
    <?php lienNav('dossiers', BASE_URL . '/modules/dossiers/liste.php', 'Dossiers d\'audit', $pageActive); ?>
    <?php if (in_array($role, ['ADMIN', 'AVANCE'], true)): ?>
      <?php lienNav('import', BASE_URL . '/modules/import/import_fec.php', 'Import FEC', $pageActive); ?>
    <?php endif; ?>
    <?php lienNav('ecritures', BASE_URL . '/modules/ecritures/liste.php', 'Écritures', $pageActive); ?>

    <div class="groupe-titre">Tests CAAT</div>
    <?php if (in_array($role, ['ADMIN', 'AVANCE'], true)): ?>
      <?php lienNav('tests', BASE_URL . '/modules/tests/lancer.php', 'Lancer les tests', $pageActive); ?>
    <?php endif; ?>
    <?php lienNav('benford', BASE_URL . '/modules/tests/benford.php', 'Test de Benford', $pageActive); ?>
    <?php lienNav('saisisseurs', BASE_URL . '/modules/tests/saisisseurs.php', 'Top saisisseurs', $pageActive); ?>
    <?php lienNav('fin_periode', BASE_URL . '/modules/tests/fin_periode.php', 'Concentration fin de période', $pageActive); ?>
    <?php lienNav('scoring', BASE_URL . '/modules/tests/scoring.php', 'Scoring de risque', $pageActive); ?>
    <?php lienNav('analytique', BASE_URL . '/modules/comptes/analytique.php', 'Revue analytique N/N-1', $pageActive); ?>
    <?php lienNav('soldes', BASE_URL . '/modules/comptes/soldes.php', 'Soldes anormaux', $pageActive); ?>
    <?php lienNav('anomalies', BASE_URL . '/modules/anomalies/liste.php', 'Anomalies détectées', $pageActive); ?>
    <?php lienNav('rapport', BASE_URL . '/modules/rapport/rapport.php', 'Rapport d\'audit', $pageActive); ?>

    <?php if ($role === 'ADMIN'): ?>
      <div class="groupe-titre">Administration</div>
      <?php lienNav('utilisateurs', BASE_URL . '/modules/utilisateurs/liste.php', 'Utilisateurs', $pageActive); ?>
      <?php lienNav('journal', BASE_URL . '/modules/journal/liste.php', 'Journal d\'audit', $pageActive); ?>
    <?php endif; ?>
  </nav>
  <div class="pied-utilisateur">
    <div><?= htmlspecialchars($u['prenom'] . ' ' . $u['nom'], ENT_QUOTES, 'UTF-8') ?></div>
    <span class="role-badge"><?= htmlspecialchars($u['role_libelle'], ENT_QUOTES, 'UTF-8') ?></span>
    <div style="margin-top:10px;">
      <a href="<?= BASE_URL ?>/logout.php" style="color:#cfd8e6;font-size:13px;">Se déconnecter</a>
    </div>
  </div>
</div>

<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

exigerConnexion();
$pdo = Database::getConnexion();
$role = utilisateurCourant()['role_code'];

$fRecherche = trim((string) ($_GET['q'] ?? ''));
$fStatut = in_array($_GET['statut'] ?? '', ['ouvert', 'en_cours', 'cloture'], true) ? $_GET['statut'] : null;

$conditions = [];
$params = [];
if ($fRecherche !== '') { $conditions[] = '(d.nom_client LIKE :q OR d.exercice LIKE :q)'; $params['q'] = '%' . $fRecherche . '%'; }
if ($fStatut) { $conditions[] = 'd.statut = :statut'; $params['statut'] = $fStatut; }
$where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

$parPage = 20;
$page = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1);
$offset = ($page - 1) * $parPage;

$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM dossiers_audit d $where");
$stmtTotal->execute($params);
$total = (int) $stmtTotal->fetchColumn();
$totalPages = max(1, (int) ceil($total / $parPage));

$sql = "SELECT d.*, u.nom AS resp_nom, u.prenom AS resp_prenom,
               (SELECT COUNT(*) FROM ecritures e JOIN fichiers_fec f ON f.id = e.fec_id WHERE f.dossier_id = d.id) AS nb_ecritures,
               (SELECT COUNT(*) FROM anomalies a WHERE a.dossier_id = d.id) AS nb_anomalies
        FROM dossiers_audit d
        LEFT JOIN utilisateurs u ON u.id = d.utilisateur_id
        $where
        ORDER BY d.nom_client, d.exercice DESC
        LIMIT :limite OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
$stmt->bindValue('limite', $parPage, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$dossiers = $stmt->fetchAll();

function construireUrlPageD(int $p): string
{
    $params = $_GET;
    $params['page'] = $p;
    return '?' . http_build_query($params);
}

$libellesStatut = ['ouvert' => 'Ouvert', 'en_cours' => 'En cours', 'cloture' => 'Clôturé'];
$classeStatut = ['ouvert' => 'badge-moyenne', 'en_cours' => 'badge-elevee', 'cloture' => 'badge-ok'];

$suppressionErreur = $_GET['erreur'] ?? null;

$titrePage = 'Dossiers d\'audit';
$pageActive = 'dossiers';
require __DIR__ . '/../../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../../includes/sidebar.php'; ?>
  <div class="contenu">
    <div class="topbar">
      <h1 style="margin:0;font-size:22px;">Dossiers d'audit (<?= $total ?>)</h1>
      <?php if (in_array($role, ['ADMIN', 'AVANCE'], true)): ?>
        <a class="btn" href="formulaire.php">+ Nouveau dossier</a>
      <?php endif; ?>
    </div>

    <?php if ($suppressionErreur === 'references'): ?>
      <div class="alerte alerte-erreur">Impossible de supprimer ce dossier : des données liées existent encore (FEC, écritures, anomalies).</div>
    <?php endif; ?>

    <div class="carte">
      <form method="get" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
        <div class="champ" style="flex:1;min-width:220px;margin-bottom:0;">
          <label for="q">Recherche (client, exercice)</label>
          <input type="text" name="q" id="q" value="<?= htmlspecialchars($fRecherche, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="champ" style="min-width:160px;margin-bottom:0;">
          <label for="statut">Statut</label>
          <select name="statut" id="statut">
            <option value="">Tous</option>
            <?php foreach ($libellesStatut as $val => $lib): ?>
              <option value="<?= $val ?>" <?= $fStatut === $val ? 'selected' : '' ?>><?= $lib ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn">Filtrer</button>
        <a href="?" class="btn btn-secondaire">Réinitialiser</a>
      </form>
    </div>

    <div class="carte">
      <table class="table-audit">
        <thead>
          <tr><th>Client</th><th>Exercice</th><th>Période</th><th>Responsable</th><th>Écritures</th><th>Anomalies</th><th>Statut</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($dossiers as $d): ?>
            <tr>
              <td><?= htmlspecialchars($d['nom_client'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($d['exercice'], ENT_QUOTES, 'UTF-8') ?></td>
              <td style="font-size:12.5px;white-space:nowrap;"><?= date('d/m/Y', strtotime($d['date_debut'])) ?> – <?= date('d/m/Y', strtotime($d['date_fin'])) ?></td>
              <td style="font-size:12.5px;"><?= $d['resp_nom'] ? htmlspecialchars($d['resp_prenom'] . ' ' . $d['resp_nom'], ENT_QUOTES, 'UTF-8') : '—' ?></td>
              <td class="montant"><?= number_format((int) $d['nb_ecritures'], 0, ',', ' ') ?></td>
              <td class="montant"><?= (int) $d['nb_anomalies'] ?></td>
              <td><span class="badge <?= $classeStatut[$d['statut']] ?>"><?= $libellesStatut[$d['statut']] ?></span></td>
              <td style="white-space:nowrap;">
                <a href="<?= BASE_URL ?>/modules/rapport/rapport.php?dossier_id=<?= (int) $d['id'] ?>" style="font-size:12px;margin-right:8px;">Rapport</a>
                <?php if (in_array($role, ['ADMIN', 'AVANCE'], true)): ?>
                  <a href="formulaire.php?id=<?= (int) $d['id'] ?>" style="font-size:12px;margin-right:8px;">Modifier</a>
                <?php endif; ?>
                <?php if ($role === 'ADMIN'): ?>
                  <form method="post" action="supprimer.php" style="display:inline;" onsubmit="return confirm('Supprimer définitivement ce dossier et TOUTES ses données (écritures, anomalies) ? Cette action est irréversible.');">
                    <?php require_once __DIR__ . '/../../includes/csrf.php'; echo csrf_champ(); ?>
                    <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                    <button type="submit" style="font-size:12px;background:none;border:none;color:var(--risque-critique);cursor:pointer;padding:0;">Supprimer</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($dossiers)): ?>
            <tr><td colspan="8" style="color:var(--texte-att);">Aucun dossier ne correspond aux filtres.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>

      <?php if ($totalPages > 1): ?>
        <div style="display:flex;gap:6px;margin-top:16px;flex-wrap:wrap;">
          <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a href="<?= construireUrlPageD($p) ?>" class="btn <?= $p === $page ? '' : 'btn-secondaire' ?>" style="padding:6px 12px;font-size:13px;"><?= $p ?></a>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../config/database.php';

exigerRole(['ADMIN']);
$pdo = Database::getConnexion();
$moi = utilisateurCourant();

$fRecherche = trim((string) ($_GET['q'] ?? ''));
$fRole = filter_input(INPUT_GET, 'role_id', FILTER_VALIDATE_INT) ?: null;
$fActif = isset($_GET['actif']) && $_GET['actif'] !== '' ? (int) $_GET['actif'] : null;

$conditions = [];
$params = [];
if ($fRecherche !== '') { $conditions[] = '(u.nom LIKE :q OR u.prenom LIKE :q OR u.email LIKE :q)'; $params['q'] = '%' . $fRecherche . '%'; }
if ($fRole) { $conditions[] = 'u.role_id = :role_id'; $params['role_id'] = $fRole; }
if ($fActif !== null) { $conditions[] = 'u.actif = :actif'; $params['actif'] = $fActif; }
$where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

$parPage = 20;
$page = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1);
$offset = ($page - 1) * $parPage;

$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM utilisateurs u $where");
$stmtTotal->execute($params);
$total = (int) $stmtTotal->fetchColumn();
$totalPages = max(1, (int) ceil($total / $parPage));

$sql = "SELECT u.*, r.libelle AS role_libelle, r.code AS role_code
        FROM utilisateurs u JOIN roles r ON r.id = u.role_id
        $where ORDER BY u.nom, u.prenom
        LIMIT :limite OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
$stmt->bindValue('limite', $parPage, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$utilisateurs = $stmt->fetchAll();

$roles = $pdo->query('SELECT id, libelle FROM roles ORDER BY id')->fetchAll();

function construireUrlPageU(int $p): string
{
    $params = $_GET;
    $params['page'] = $p;
    return '?' . http_build_query($params);
}

$erreurGet = $_GET['erreur'] ?? null;

$titrePage = 'Utilisateurs';
$pageActive = 'utilisateurs';
require __DIR__ . '/../../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../../includes/sidebar.php'; ?>
  <div class="contenu">
    <div class="topbar">
      <h1 style="margin:0;font-size:22px;">Utilisateurs (<?= $total ?>)</h1>
      <a class="btn" href="formulaire.php">+ Nouvel utilisateur</a>
    </div>

    <?php if ($erreurGet === 'auto_suppression'): ?>
      <div class="alerte alerte-erreur">Vous ne pouvez pas supprimer ou désactiver votre propre compte.</div>
    <?php elseif ($erreurGet === 'references'): ?>
      <div class="alerte alerte-erreur">Impossible de supprimer cet utilisateur : il est responsable d'au moins un dossier d'audit. Désactivez-le plutôt, ou réassignez ses dossiers.</div>
    <?php endif; ?>

    <div class="carte">
      <form method="get" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
        <div class="champ" style="flex:1;min-width:220px;margin-bottom:0;">
          <label for="q">Recherche (nom, email)</label>
          <input type="text" name="q" id="q" value="<?= htmlspecialchars($fRecherche, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="champ" style="min-width:180px;margin-bottom:0;">
          <label for="role_id">Rôle</label>
          <select name="role_id" id="role_id">
            <option value="">Tous</option>
            <?php foreach ($roles as $r): ?>
              <option value="<?= (int) $r['id'] ?>" <?= $fRole === (int) $r['id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['libelle'], ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="champ" style="min-width:150px;margin-bottom:0;">
          <label for="actif">Statut</label>
          <select name="actif" id="actif">
            <option value="">Tous</option>
            <option value="1" <?= $fActif === 1 ? 'selected' : '' ?>>Actif</option>
            <option value="0" <?= $fActif === 0 ? 'selected' : '' ?>>Désactivé</option>
          </select>
        </div>
        <button type="submit" class="btn">Filtrer</button>
        <a href="?" class="btn btn-secondaire">Réinitialiser</a>
      </form>
    </div>

    <div class="carte">
      <table class="table-audit">
        <thead><tr><th>Nom</th><th>Email</th><th>Rôle</th><th>Dernière connexion</th><th>Statut</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($utilisateurs as $u): ?>
            <tr>
              <td><?= htmlspecialchars($u['prenom'] . ' ' . $u['nom'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><span class="badge badge-moyenne"><?= htmlspecialchars($u['role_libelle'], ENT_QUOTES, 'UTF-8') ?></span></td>
              <td style="font-size:12.5px;"><?= $u['derniere_connexion'] ? date('d/m/Y H:i', strtotime($u['derniere_connexion'])) : '—' ?></td>
              <td><span class="badge <?= $u['actif'] ? 'badge-ok' : 'badge-critique' ?>"><?= $u['actif'] ? 'Actif' : 'Désactivé' ?></span></td>
              <td style="white-space:nowrap;">
                <a href="formulaire.php?id=<?= (int) $u['id'] ?>" style="font-size:12px;margin-right:8px;">Modifier</a>
                <?php if ((int) $u['id'] !== (int) $moi['id']): ?>
                  <form method="post" action="changer_statut.php" style="display:inline;margin-right:8px;">
                    <?= csrf_champ() ?>
                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <input type="hidden" name="nouveau_statut" value="<?= $u['actif'] ? 0 : 1 ?>">
                    <button type="submit" style="font-size:12px;background:none;border:none;color:var(--encre-500);cursor:pointer;padding:0;">
                      <?= $u['actif'] ? 'Désactiver' : 'Activer' ?>
                    </button>
                  </form>
                  <form method="post" action="supprimer.php" style="display:inline;" onsubmit="return confirm('Supprimer définitivement cet utilisateur ?');">
                    <?= csrf_champ() ?>
                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <button type="submit" style="font-size:12px;background:none;border:none;color:var(--risque-critique);cursor:pointer;padding:0;">Supprimer</button>
                  </form>
                <?php else: ?>
                  <span style="font-size:12px;color:var(--texte-att);">(vous)</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($utilisateurs)): ?>
            <tr><td colspan="6" style="color:var(--texte-att);">Aucun utilisateur ne correspond aux filtres.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>

      <?php if ($totalPages > 1): ?>
        <div style="display:flex;gap:6px;margin-top:16px;flex-wrap:wrap;">
          <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a href="<?= construireUrlPageU($p) ?>" class="btn <?= $p === $page ? '' : 'btn-secondaire' ?>" style="padding:6px 12px;font-size:13px;"><?= $p ?></a>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>

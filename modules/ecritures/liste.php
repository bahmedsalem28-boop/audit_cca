<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

exigerConnexion();
$pdo = Database::getConnexion();
$role = utilisateurCourant()['role_code'];

$dossiers = $pdo->query('SELECT id, nom_client, exercice FROM dossiers_audit ORDER BY nom_client')->fetchAll();

$fDossier = filter_input(INPUT_GET, 'dossier_id', FILTER_VALIDATE_INT) ?: ($dossiers[0]['id'] ?? null);
$fJournal = trim((string) ($_GET['journal'] ?? ''));
$fCompte = trim((string) ($_GET['compte'] ?? ''));
$fDateDebut = (string) ($_GET['date_debut'] ?? '');
$fDateFin = (string) ($_GET['date_fin'] ?? '');
$fRecherche = trim((string) ($_GET['q'] ?? ''));

$conditions = ['f.dossier_id = :dossier_id'];
$params = ['dossier_id' => $fDossier];
if ($fJournal !== '') { $conditions[] = 'e.journal_code = :journal'; $params['journal'] = $fJournal; }
if ($fCompte !== '') { $conditions[] = 'e.compte_num LIKE :compte'; $params['compte'] = $fCompte . '%'; }
if ($fDateDebut !== '') { $conditions[] = 'e.ecriture_date >= :date_debut'; $params['date_debut'] = $fDateDebut; }
if ($fDateFin !== '') { $conditions[] = 'e.ecriture_date <= :date_fin'; $params['date_fin'] = $fDateFin; }
if ($fRecherche !== '') {
    $conditions[] = '(e.ecriture_lib LIKE :q OR e.piece_ref LIKE :q OR e.ecriture_num LIKE :q)';
    $params['q'] = '%' . $fRecherche . '%';
}
$where = 'WHERE ' . implode(' AND ', $conditions);

$journauxDisponibles = [];
if ($fDossier) {
    $stmtJ = $pdo->prepare("SELECT DISTINCT e.journal_code FROM ecritures e JOIN fichiers_fec f ON f.id = e.fec_id WHERE f.dossier_id = :d ORDER BY e.journal_code");
    $stmtJ->execute(['d' => $fDossier]);
    $journauxDisponibles = $stmtJ->fetchAll(PDO::FETCH_COLUMN);
}

$parPage = 25;
$page = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1);
$offset = ($page - 1) * $parPage;

$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM ecritures e JOIN fichiers_fec f ON f.id = e.fec_id $where");
$stmtTotal->execute($params);
$total = (int) $stmtTotal->fetchColumn();
$totalPages = max(1, (int) ceil($total / $parPage));

$sql = "SELECT e.* FROM ecritures e JOIN fichiers_fec f ON f.id = e.fec_id
        $where ORDER BY e.ecriture_date DESC, e.id DESC
        LIMIT :limite OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
$stmt->bindValue('limite', $parPage, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$ecritures = $stmt->fetchAll();

function construireUrlPageE(int $p): string
{
    $params = $_GET;
    $params['page'] = $p;
    return '?' . http_build_query($params);
}
function construireUrlExport(): string
{
    $params = $_GET;
    unset($params['page']);
    return 'export_csv.php?' . http_build_query($params);
}

$titrePage = 'Écritures comptables';
$pageActive = 'ecritures';
require __DIR__ . '/../../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../../includes/sidebar.php'; ?>
  <div class="contenu">
    <div class="topbar">
      <h1 style="margin:0;font-size:22px;">Écritures comptables (<?= number_format($total, 0, ',', ' ') ?>)</h1>
      <a class="btn btn-secondaire" href="<?= htmlspecialchars(construireUrlExport(), ENT_QUOTES, 'UTF-8') ?>">Exporter en CSV</a>
    </div>

    <div class="carte">
      <form method="get" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
        <div class="champ" style="min-width:230px;margin-bottom:0;">
          <label for="dossier_id">Dossier</label>
          <select name="dossier_id" id="dossier_id" onchange="this.form.submit()">
            <?php foreach ($dossiers as $d): ?>
              <option value="<?= (int) $d['id'] ?>" <?= (int) $d['id'] === (int) $fDossier ? 'selected' : '' ?>>
                <?= htmlspecialchars($d['nom_client'] . ' (' . $d['exercice'] . ')', ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="champ" style="min-width:130px;margin-bottom:0;">
          <label for="journal">Journal</label>
          <select name="journal" id="journal">
            <option value="">Tous</option>
            <?php foreach ($journauxDisponibles as $j): ?>
              <option value="<?= htmlspecialchars($j, ENT_QUOTES, 'UTF-8') ?>" <?= $fJournal === $j ? 'selected' : '' ?>><?= htmlspecialchars($j, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="champ" style="min-width:130px;margin-bottom:0;">
          <label for="compte">Compte (préfixe)</label>
          <input type="text" name="compte" id="compte" value="<?= htmlspecialchars($fCompte, ENT_QUOTES, 'UTF-8') ?>" placeholder="ex : 606">
        </div>
        <div class="champ" style="margin-bottom:0;">
          <label for="date_debut">Du</label>
          <input type="date" name="date_debut" id="date_debut" value="<?= htmlspecialchars($fDateDebut, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="champ" style="margin-bottom:0;">
          <label for="date_fin">Au</label>
          <input type="date" name="date_fin" id="date_fin" value="<?= htmlspecialchars($fDateFin, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="champ" style="flex:1;min-width:180px;margin-bottom:0;">
          <label for="q">Recherche (libellé, pièce, n°)</label>
          <input type="text" name="q" id="q" value="<?= htmlspecialchars($fRecherche, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <button type="submit" class="btn">Filtrer</button>
        <a href="?dossier_id=<?= (int) $fDossier ?>" class="btn btn-secondaire">Réinitialiser</a>
      </form>
    </div>

    <div class="carte">
      <table class="table-audit">
        <thead>
          <tr><th>Journal</th><th>N° écriture</th><th>Date</th><th>Compte</th><th>Pièce</th><th>Libellé</th><th>Débit</th><th>Crédit</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($ecritures as $e): ?>
            <tr>
              <td><?= htmlspecialchars($e['journal_code'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($e['ecriture_num'], ENT_QUOTES, 'UTF-8') ?></td>
              <td style="white-space:nowrap;"><?= date('d/m/Y', strtotime($e['ecriture_date'])) ?></td>
              <td><?= htmlspecialchars($e['compte_num'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($e['piece_ref'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
              <td style="font-size:12.5px;max-width:220px;"><?= htmlspecialchars($e['ecriture_lib'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
              <td class="montant"><?= $e['debit'] > 0 ? number_format((float) $e['debit'], 2, ',', ' ') : '' ?></td>
              <td class="montant"><?= $e['credit'] > 0 ? number_format((float) $e['credit'], 2, ',', ' ') : '' ?></td>
              <td style="white-space:nowrap;">
                <a href="voir.php?id=<?= (int) $e['id'] ?>" style="font-size:12px;">Voir</a>
                <?php if ($role === 'ADMIN'): ?>
                  · <a href="modifier.php?id=<?= (int) $e['id'] ?>" style="font-size:12px;">Modifier</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($ecritures)): ?>
            <tr><td colspan="9" style="color:var(--texte-att);">Aucune écriture ne correspond aux filtres.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>

      <?php if ($totalPages > 1): ?>
        <div style="display:flex;gap:6px;margin-top:16px;flex-wrap:wrap;">
          <?php
            $debut = max(1, $page - 3); $fin = min($totalPages, $page + 3);
            if ($debut > 1) echo '<a href="' . construireUrlPageE(1) . '" class="btn btn-secondaire" style="padding:6px 12px;font-size:13px;">1</a>';
          ?>
          <?php for ($p = $debut; $p <= $fin; $p++): ?>
            <a href="<?= construireUrlPageE($p) ?>" class="btn <?= $p === $page ? '' : 'btn-secondaire' ?>" style="padding:6px 12px;font-size:13px;"><?= $p ?></a>
          <?php endfor; ?>
          <?php if ($fin < $totalPages) echo '<a href="' . construireUrlPageE($totalPages) . '" class="btn btn-secondaire" style="padding:6px 12px;font-size:13px;">' . $totalPages . '</a>'; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>

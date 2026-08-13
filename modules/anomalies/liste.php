<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../config/database.php';

exigerConnexion();

$pdo = Database::getConnexion();
$role = utilisateurCourant()['role_code'];

$dossiers = $pdo->query('SELECT id, nom_client, exercice FROM dossiers_audit ORDER BY nom_client')->fetchAll();
$typesTests = $pdo->query('SELECT id, code, libelle FROM types_tests ORDER BY libelle')->fetchAll();

// --- Filtres (recherche multi-critères) ---
$fDossier = filter_input(INPUT_GET, 'dossier_id', FILTER_VALIDATE_INT) ?: null;
$fGravite = in_array($_GET['gravite'] ?? '', ['critique', 'elevee', 'moyenne', 'faible'], true) ? $_GET['gravite'] : null;
$fStatut  = in_array($_GET['statut'] ?? '', ['non_traite', 'en_cours', 'traite', 'ecarte'], true) ? $_GET['statut'] : null;
$fType    = filter_input(INPUT_GET, 'type_test_id', FILTER_VALIDATE_INT) ?: null;
$fRecherche = trim((string) ($_GET['q'] ?? ''));

$conditions = [];
$params = [];
if ($fDossier) { $conditions[] = 'a.dossier_id = :dossier_id'; $params['dossier_id'] = $fDossier; }
if ($fGravite) { $conditions[] = 'a.gravite = :gravite'; $params['gravite'] = $fGravite; }
if ($fStatut)  { $conditions[] = 'a.statut_traitement = :statut'; $params['statut'] = $fStatut; }
if ($fType)    { $conditions[] = 'a.type_test_id = :type_test_id'; $params['type_test_id'] = $fType; }
if ($fRecherche !== '') { $conditions[] = 'a.description LIKE :q'; $params['q'] = '%' . $fRecherche . '%'; }

$where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

// --- Pagination (20 résultats par page minimum) ---
$parPage = 20;
$page = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1);
$offset = ($page - 1) * $parPage;

$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM anomalies a $where");
$stmtTotal->execute($params);
$total = (int) $stmtTotal->fetchColumn();
$totalPages = max(1, (int) ceil($total / $parPage));

$sql = "SELECT a.id, a.gravite, a.description, a.date_detection, a.statut_traitement,
               tt.libelle AS test_libelle, tt.code AS test_code,
               e.ecriture_num, e.journal_code, e.compte_num, e.ecriture_date, e.debit, e.credit,
               d.nom_client, d.exercice
        FROM anomalies a
        JOIN types_tests tt ON tt.id = a.type_test_id
        JOIN dossiers_audit d ON d.id = a.dossier_id
        LEFT JOIN ecritures e ON e.id = a.ecriture_id
        $where
        ORDER BY FIELD(a.gravite,'critique','elevee','moyenne','faible'), a.date_detection DESC
        LIMIT :limite OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue('limite', $parPage, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$anomalies = $stmt->fetchAll();

function construireUrlPage(int $p): string
{
    $params = $_GET;
    $params['page'] = $p;
    return '?' . http_build_query($params);
}

$libellesGravite = ['critique' => 'Critique', 'elevee' => 'Élevée', 'moyenne' => 'Moyenne', 'faible' => 'Faible'];
$libellesStatut  = ['non_traite' => 'Non traité', 'en_cours' => 'En cours', 'traite' => 'Traité', 'ecarte' => 'Écarté'];

$titrePage = 'Anomalies détectées';
$pageActive = 'anomalies';
require __DIR__ . '/../../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../../includes/sidebar.php'; ?>
  <div class="contenu">
    <div class="topbar">
      <h1 style="margin:0;font-size:22px;">Registre des anomalies (<?= $total ?>)</h1>
      <a class="btn btn-secondaire" href="export_csv.php?<?= http_build_query($_GET) ?>">Exporter en CSV</a>
    </div>

    <div class="carte">
      <form method="get" action="" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
        <div class="champ" style="min-width:200px;margin-bottom:0;">
          <label for="dossier_id">Dossier</label>
          <select name="dossier_id" id="dossier_id">
            <option value="">Tous les dossiers</option>
            <?php foreach ($dossiers as $d): ?>
              <option value="<?= (int) $d['id'] ?>" <?= $fDossier === (int) $d['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($d['nom_client'] . ' (' . $d['exercice'] . ')', ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="champ" style="min-width:150px;margin-bottom:0;">
          <label for="gravite">Gravité</label>
          <select name="gravite" id="gravite">
            <option value="">Toutes</option>
            <?php foreach ($libellesGravite as $val => $lib): ?>
              <option value="<?= $val ?>" <?= $fGravite === $val ? 'selected' : '' ?>><?= $lib ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="champ" style="min-width:150px;margin-bottom:0;">
          <label for="statut">Statut</label>
          <select name="statut" id="statut">
            <option value="">Tous</option>
            <?php foreach ($libellesStatut as $val => $lib): ?>
              <option value="<?= $val ?>" <?= $fStatut === $val ? 'selected' : '' ?>><?= $lib ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="champ" style="min-width:200px;margin-bottom:0;">
          <label for="type_test_id">Test</label>
          <select name="type_test_id" id="type_test_id">
            <option value="">Tous les tests</option>
            <?php foreach ($typesTests as $t): ?>
              <option value="<?= (int) $t['id'] ?>" <?= $fType === (int) $t['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($t['libelle'], ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="champ" style="flex:1;min-width:200px;margin-bottom:0;">
          <label for="q">Recherche (description)</label>
          <input type="text" name="q" id="q" value="<?= htmlspecialchars($fRecherche, ENT_QUOTES, 'UTF-8') ?>" placeholder="ex : compte 606100">
        </div>
        <button type="submit" class="btn">Filtrer</button>
        <a href="?" class="btn btn-secondaire">Réinitialiser</a>
      </form>
    </div>

    <div class="carte">
      <table class="table-audit">
        <thead>
          <tr>
            <th>Gravité</th><th>Test</th><th>Écriture</th><th>Compte</th><th>Montant</th>
            <th>Description</th><th>Détecté le</th><th>Statut</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($anomalies as $a): ?>
            <tr>
              <td><span class="badge badge-<?= $a['gravite'] ?>"><?= $libellesGravite[$a['gravite']] ?></span></td>
              <td style="font-size:12.5px;"><?= htmlspecialchars($a['test_libelle'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= $a['ecriture_num'] ? htmlspecialchars($a['journal_code'] . ' / ' . $a['ecriture_num'], ENT_QUOTES, 'UTF-8') : '—' ?></td>
              <td><?= htmlspecialchars($a['compte_num'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
              <td class="montant"><?= $a['debit'] !== null ? number_format((float) max($a['debit'], $a['credit']), 2, ',', ' ') : '—' ?></td>
              <td style="max-width:320px;font-size:13px;"><?= htmlspecialchars($a['description'], ENT_QUOTES, 'UTF-8') ?></td>
              <td style="font-size:12.5px;white-space:nowrap;"><?= date('d/m/Y', strtotime($a['date_detection'])) ?></td>
              <td>
                <?php
                  $classeStatut = ['non_traite' => 'badge-critique', 'en_cours' => 'badge-moyenne', 'traite' => 'badge-ok', 'ecarte' => 'badge-faible'][$a['statut_traitement']];
                ?>
                <span class="badge <?= $classeStatut ?>"><?= $libellesStatut[$a['statut_traitement']] ?></span>
              </td>
              <td>
                <?php if (in_array($role, ['ADMIN', 'AVANCE'], true)): ?>
                  <form method="post" action="maj_statut.php" style="display:flex;gap:4px;">
                    <?= csrf_champ() ?>
                    <input type="hidden" name="anomalie_id" value="<?= (int) $a['id'] ?>">
                    <input type="hidden" name="retour" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8') ?>">
                    <select name="nouveau_statut" style="padding:4px 6px;font-size:12px;">
                      <?php foreach ($libellesStatut as $val => $lib): ?>
                        <option value="<?= $val ?>" <?= $a['statut_traitement'] === $val ? 'selected' : '' ?>><?= $lib ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-secondaire" style="padding:4px 9px;font-size:12px;">OK</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($anomalies)): ?>
            <tr><td colspan="9" style="color:var(--texte-att);">Aucune anomalie ne correspond aux filtres sélectionnés.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>

      <?php if ($totalPages > 1): ?>
        <div style="display:flex;gap:6px;margin-top:16px;flex-wrap:wrap;">
          <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a href="<?= construireUrlPage($p) ?>" class="btn <?= $p === $page ? '' : 'btn-secondaire' ?>" style="padding:6px 12px;font-size:13px;"><?= $p ?></a>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>

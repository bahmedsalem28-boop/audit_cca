<?php
/**
 * init_mots_de_passe.php
 * ------------------------------------------------------------------
 * À exécuter UNE SEULE FOIS, après avoir importé 01_schema.sql et
 * 02_donnees_demo.sql dans phpMyAdmin, pour remplacer le marqueur
 * temporaire des comptes de démonstration par de vrais hachages
 * bcrypt générés avec password_hash() (exigence du cahier des charges).
 *
 * Usage : placer ce fichier à la racine du projet (accessible via
 * http://localhost/audit_caat/init_mots_de_passe.php), l'exécuter une
 * fois dans le navigateur, puis LE SUPPRIMER.
 * ------------------------------------------------------------------
 */

$hote   = '127.0.0.1';
$base   = 'audit_caat';
$user   = 'root';
$pass   = '';

$motDePasseDemo = 'Audit@2026'; // mot de passe commun aux 3 comptes de demo

try {
    $pdo = new PDO("mysql:host=$hote;dbname=$base;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    $hash = password_hash($motDePasseDemo, PASSWORD_BCRYPT);

    $stmt = $pdo->prepare(
        "UPDATE utilisateurs SET mot_de_passe_hash = :hash
         WHERE mot_de_passe_hash = 'A_REGENERER_VIA_init_mots_de_passe.php'"
    );
    $stmt->execute(['hash' => $hash]);

    $nb = $stmt->rowCount();

    echo "<h2>Initialisation des mots de passe</h2>";
    echo "<p>$nb compte(s) mis à jour avec un hachage bcrypt valide.</p>";
    echo "<p>Mot de passe de démonstration pour tous les comptes : <strong>$motDePasseDemo</strong></p>";
    echo "<ul>
            <li>admin@audit-caat.sn (Administrateur)</li>
            <li>auditeur@audit-caat.sn (Utilisateur avancé)</li>
            <li>consultant@audit-caat.sn (Utilisateur standard)</li>
          </ul>";
    echo "<p style='color:red;font-weight:bold;'>⚠ Supprimez ce fichier maintenant.</p>";

} catch (PDOException $e) {
    die("Erreur de connexion à la base : " . htmlspecialchars($e->getMessage()));
}

<?php
/**
 * auth.php — Connexion, déconnexion, contrôle des rôles (ADMIN / AVANCE / STANDARD)
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/audit_logger.php';

/**
 * Tente une connexion. Retourne un tableau ['succes'=>bool, 'message'=>string]
 */
function tenterConnexion(string $email, string $motDePasse): array
{
    $pdo = Database::getConnexion();

    $stmt = $pdo->prepare(
        'SELECT u.id, u.nom, u.prenom, u.email, u.mot_de_passe_hash, u.actif,
                r.id AS role_id, r.code AS role_code, r.libelle AS role_libelle
         FROM utilisateurs u
         JOIN roles r ON r.id = u.role_id
         WHERE u.email = :email
         LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $utilisateur = $stmt->fetch();

    // Message volontairement générique (ne pas révéler si l'email existe ou non)
    $messageEchec = 'Identifiants incorrects.';

    if (!$utilisateur) {
        journaliser(null, 'CONNEXION_ECHEC', 'utilisateurs', null, "Tentative avec email inconnu : $email");
        return ['succes' => false, 'message' => $messageEchec];
    }

    if ((int) $utilisateur['actif'] !== 1) {
        journaliser((int) $utilisateur['id'], 'CONNEXION_ECHEC', 'utilisateurs', (string) $utilisateur['id'], 'Compte désactivé');
        return ['succes' => false, 'message' => 'Ce compte est désactivé. Contactez un administrateur.'];
    }

    if (!password_verify($motDePasse, $utilisateur['mot_de_passe_hash'])) {
        journaliser((int) $utilisateur['id'], 'CONNEXION_ECHEC', 'utilisateurs', (string) $utilisateur['id'], 'Mot de passe incorrect');
        return ['succes' => false, 'message' => $messageEchec];
    }

    // Connexion réussie : régénérer l'ID de session (anti session fixation)
    session_regenerate_id(true);

    $_SESSION['utilisateur'] = [
        'id'           => (int) $utilisateur['id'],
        'nom'          => $utilisateur['nom'],
        'prenom'       => $utilisateur['prenom'],
        'email'        => $utilisateur['email'],
        'role_id'      => (int) $utilisateur['role_id'],
        'role_code'    => $utilisateur['role_code'],
        'role_libelle' => $utilisateur['role_libelle'],
    ];
    $_SESSION['derniere_activite'] = time();

    $maj = $pdo->prepare('UPDATE utilisateurs SET derniere_connexion = NOW() WHERE id = :id');
    $maj->execute(['id' => $utilisateur['id']]);

    journaliser((int) $utilisateur['id'], 'CONNEXION', 'utilisateurs', (string) $utilisateur['id'], 'Connexion réussie');

    return ['succes' => true, 'message' => 'Connexion réussie.'];
}

function deconnecter(): void
{
    if (utilisateurConnecte()) {
        journaliser(utilisateurCourant()['id'], 'DECONNEXION', 'utilisateurs', (string) utilisateurCourant()['id'], 'Déconnexion explicite');
    }
    $_SESSION = [];
    session_unset();
    session_destroy();
}

function utilisateurConnecte(): bool
{
    return isset($_SESSION['utilisateur']);
}

function utilisateurCourant(): ?array
{
    return $_SESSION['utilisateur'] ?? null;
}

/** Redirige vers login.php si l'utilisateur n'est pas connecté */
function exigerConnexion(): void
{
    if (!utilisateurConnecte()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

/**
 * Redirige vers login.php si non connecté, ou affiche une page 403
 * si le rôle de l'utilisateur ne fait pas partie des rôles autorisés.
 * Exemple : exigerRole(['ADMIN']); ou exigerRole(['ADMIN','AVANCE']);
 */
function exigerRole(array $rolesAutorises): void
{
    exigerConnexion();
    $role = utilisateurCourant()['role_code'];
    if (!in_array($role, $rolesAutorises, true)) {
        http_response_code(403);
        journaliser(utilisateurCourant()['id'], 'ACCES_REFUSE', null, null, 'Tentative d\'accès à une page hors périmètre du rôle ' . $role);
        require __DIR__ . '/../403.php';
        exit;
    }
}

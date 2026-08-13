<?php
/**
 * audit_logger.php — Trace horodatée des actions sensibles
 * (connexion, déconnexion, import FEC, exécution de tests, export,
 *  création/modification/suppression de comptes, etc.)
 */

require_once __DIR__ . '/../config/database.php';

function journaliser(?int $utilisateurId, string $action, ?string $tableCible = null, ?string $idCible = null, ?string $details = null): void
{
    try {
        $pdo = Database::getConnexion();
        $stmt = $pdo->prepare(
            'INSERT INTO journal_audit_actions (utilisateur_id, action, table_cible, id_cible, details, adresse_ip)
             VALUES (:uid, :action, :table_cible, :id_cible, :details, :ip)'
        );
        $stmt->execute([
            'uid'         => $utilisateurId,
            'action'      => $action,
            'table_cible' => $tableCible,
            'id_cible'    => $idCible,
            'details'     => $details,
            'ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        // Le journal d'audit ne doit jamais faire planter l'application
        error_log('Erreur journal_audit_actions : ' . $e->getMessage());
    }
}

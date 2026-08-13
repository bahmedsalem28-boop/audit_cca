<?php
/**
 * database.php — Connexion PDO unique (singleton) à MySQL
 * Requêtes préparées obligatoires partout dans l'application.
 */

class Database
{
    private static ?PDO $instance = null;

    // Adaptez ces valeurs à votre installation XAMPP si nécessaire
    private const HOTE  = '127.0.0.1';
    private const BASE  = 'audit_caat';
    private const USER  = 'root';
    private const PASS  = '';
    private const CHARSET = 'utf8mb4';

    private function __construct()
    {
    }

    public static function getConnexion(): PDO
    {
        if (self::$instance === null) {
            $dsn = 'mysql:host=' . self::HOTE . ';dbname=' . self::BASE . ';charset=' . self::CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false, // vraies requêtes préparées côté serveur
            ];
            try {
                self::$instance = new PDO($dsn, self::USER, self::PASS, $options);
            } catch (PDOException $e) {
                // Ne jamais exposer les détails de connexion à l'utilisateur final
                error_log('Erreur connexion BDD : ' . $e->getMessage());
                die('Erreur de connexion à la base de données. Contactez l\'administrateur.');
            }
        }
        return self::$instance;
    }
}

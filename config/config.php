<?php
/**
 * config.php — Paramètres généraux de l'application
 */

// Adaptez si votre projet n'est pas servi depuis la racine http://localhost/
define('BASE_URL', '/audit_caat');

define('APP_NAME', 'Plateforme d\'Audit Assisté par Analyse de Données (CAAT)');

// Durée d'inactivité avant expiration automatique de session (en secondes)
define('SESSION_TIMEOUT', 20 * 60); // 20 minutes

// Fuseau horaire
date_default_timezone_set('Africa/Dakar');

// Affichage des erreurs : à mettre à 0 en production
error_reporting(E_ALL);
ini_set('display_errors', 1);

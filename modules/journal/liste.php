<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
exigerRole(['ADMIN']);
$titrePage = 'Journal d\'audit';
$pageActive = 'journal';
$descriptionModule = 'Consultation horodatée des actions sensibles réalisées dans l\'application, avec filtres par utilisateur et par action.';
require __DIR__ . '/../../includes/page_a_venir.php';

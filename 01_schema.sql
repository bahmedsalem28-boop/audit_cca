-- =====================================================================
-- PLATEFORME D'AUDIT ASSISTÉ PAR ANALYSE DE DONNÉES (CAAT)
-- Script de création de la base de données
-- Master CCA - ESP Dakar
-- Moteur : MySQL 5.7+ / MariaDB 10.3+ (XAMPP)
-- =====================================================================

DROP DATABASE IF EXISTS audit_caat;
CREATE DATABASE audit_caat CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE audit_caat;

-- ---------------------------------------------------------------------
-- 1. RÔLES
-- ---------------------------------------------------------------------
CREATE TABLE roles (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    code          VARCHAR(30) NOT NULL UNIQUE,      -- ADMIN, AVANCE, STANDARD
    libelle       VARCHAR(100) NOT NULL,
    description   VARCHAR(255)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. UTILISATEURS
-- ---------------------------------------------------------------------
CREATE TABLE utilisateurs (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    nom                 VARCHAR(80) NOT NULL,
    prenom              VARCHAR(80) NOT NULL,
    email               VARCHAR(150) NOT NULL UNIQUE,
    mot_de_passe_hash   VARCHAR(255) NOT NULL,       -- password_hash() PHP (bcrypt)
    role_id             INT NOT NULL,
    actif               TINYINT(1) NOT NULL DEFAULT 1,
    derniere_connexion  DATETIME NULL,
    date_creation       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_utilisateurs_role FOREIGN KEY (role_id) REFERENCES roles(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. DOSSIERS D'AUDIT (un dossier = une mission / un client / un exercice)
-- ---------------------------------------------------------------------
CREATE TABLE dossiers_audit (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    nom_client      VARCHAR(150) NOT NULL,
    exercice        VARCHAR(9) NOT NULL,             -- ex: 2025
    date_debut      DATE NOT NULL,
    date_fin        DATE NOT NULL,
    statut          ENUM('ouvert','en_cours','cloture') NOT NULL DEFAULT 'ouvert',
    utilisateur_id  INT NOT NULL,                    -- responsable du dossier
    date_creation   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dossiers_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. FICHIERS FEC IMPORTÉS
-- ---------------------------------------------------------------------
CREATE TABLE fichiers_fec (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    dossier_id      INT NOT NULL,
    nom_fichier     VARCHAR(255) NOT NULL,
    chemin_stockage VARCHAR(500),
    date_import     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    nb_lignes       INT DEFAULT 0,
    statut_import   ENUM('en_attente','importe','erreur') NOT NULL DEFAULT 'en_attente',
    message_erreur  VARCHAR(500),
    utilisateur_id  INT NOT NULL,                    -- importateur
    CONSTRAINT fk_fec_dossier FOREIGN KEY (dossier_id) REFERENCES dossiers_audit(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_fec_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. ÉCRITURES COMPTABLES (norme FEC - 18 champs officiels + extensions)
-- ---------------------------------------------------------------------
CREATE TABLE ecritures (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    fec_id          INT NOT NULL,
    journal_code    VARCHAR(10) NOT NULL,
    journal_lib     VARCHAR(100),
    ecriture_num    VARCHAR(30) NOT NULL,
    ecriture_date   DATE NOT NULL,
    compte_num      VARCHAR(20) NOT NULL,
    compte_lib      VARCHAR(150),
    comp_aux_num    VARCHAR(20),
    comp_aux_lib    VARCHAR(150),
    piece_ref       VARCHAR(50),
    piece_date      DATE,
    ecriture_lib    VARCHAR(255),
    debit           DECIMAL(15,2) NOT NULL DEFAULT 0,
    credit          DECIMAL(15,2) NOT NULL DEFAULT 0,
    lettrage        VARCHAR(20),
    date_lettrage   DATE,
    valid_date      DATE,
    montant_devise  DECIMAL(15,2),
    idevise         VARCHAR(10),
    saisi_par       VARCHAR(50),
    date_saisie     DATETIME,
    CONSTRAINT fk_ecritures_fec FOREIGN KEY (fec_id) REFERENCES fichiers_fec(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_ecritures_compte (compte_num),
    INDEX idx_ecritures_date (ecriture_date),
    INDEX idx_ecritures_piece (piece_ref),
    INDEX idx_ecritures_journal_num (journal_code, ecriture_num),
    INDEX idx_ecritures_saisi_par (saisi_par)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 6. CATALOGUE DES TESTS CAAT DISPONIBLES
-- ---------------------------------------------------------------------
CREATE TABLE types_tests (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    code           VARCHAR(40) NOT NULL UNIQUE,     -- BENFORD, DOUBLONS, WEEKEND, ...
    libelle        VARCHAR(150) NOT NULL,
    description    VARCHAR(500),
    gravite_defaut ENUM('faible','moyenne','elevee','critique') NOT NULL DEFAULT 'moyenne',
    actif          TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 7. PARAMÈTRES DE TEST PAR DOSSIER (seuils configurables)
-- ---------------------------------------------------------------------
CREATE TABLE parametres_test (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    dossier_id    INT NOT NULL,
    type_test_id  INT NOT NULL,
    seuil         DECIMAL(10,2),                    -- ex: seuil de materialite, ecart Benford toleré
    actif         TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT fk_param_dossier FOREIGN KEY (dossier_id) REFERENCES dossiers_audit(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_param_test FOREIGN KEY (type_test_id) REFERENCES types_tests(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE KEY uk_param_dossier_test (dossier_id, type_test_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 8. RÉSULTATS DES TESTS PAR ÉCRITURE
-- ---------------------------------------------------------------------
CREATE TABLE resultats_tests (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    ecriture_id     BIGINT NOT NULL,
    type_test_id    INT NOT NULL,
    statut          ENUM('conforme','suspect') NOT NULL DEFAULT 'conforme',
    score_risque    DECIMAL(5,2) NOT NULL DEFAULT 0,  -- 0 à 100
    detail          TEXT,                              -- JSON libre (valeurs, écarts...)
    date_execution  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_resultats_ecriture FOREIGN KEY (ecriture_id) REFERENCES ecritures(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_resultats_test FOREIGN KEY (type_test_id) REFERENCES types_tests(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_resultats_statut (statut)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 9. ANOMALIES (registre hiérarchisé pour le rapport d'audit)
-- ---------------------------------------------------------------------
CREATE TABLE anomalies (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    dossier_id        INT NOT NULL,
    ecriture_id       BIGINT,
    type_test_id      INT NOT NULL,
    gravite           ENUM('faible','moyenne','elevee','critique') NOT NULL DEFAULT 'moyenne',
    description       VARCHAR(500) NOT NULL,
    date_detection    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    statut_traitement ENUM('non_traite','en_cours','traite','ecarte') NOT NULL DEFAULT 'non_traite',
    traite_par        INT,
    date_traitement   DATETIME,
    commentaire       VARCHAR(500),
    CONSTRAINT fk_anomalies_dossier FOREIGN KEY (dossier_id) REFERENCES dossiers_audit(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_anomalies_ecriture FOREIGN KEY (ecriture_id) REFERENCES ecritures(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_anomalies_test FOREIGN KEY (type_test_id) REFERENCES types_tests(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_anomalies_traite_par FOREIGN KEY (traite_par) REFERENCES utilisateurs(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_anomalies_gravite (gravite),
    INDEX idx_anomalies_statut (statut_traitement)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 10. JOURNAL D'AUDIT DES ACTIONS SENSIBLES (traçabilité applicative)
-- ---------------------------------------------------------------------
CREATE TABLE journal_audit_actions (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    utilisateur_id  INT,
    action          VARCHAR(100) NOT NULL,        -- ex: CONNEXION, IMPORT_FEC, EXPORT_PDF, SUPPRESSION_UTILISATEUR
    table_cible     VARCHAR(60),
    id_cible        VARCHAR(30),
    details         VARCHAR(500),
    date_action     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    adresse_ip      VARCHAR(45),
    CONSTRAINT fk_journal_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_journal_date (date_action),
    INDEX idx_journal_utilisateur (utilisateur_id)
) ENGINE=InnoDB;

# Installation de la base de données — Plateforme d'audit CAAT

## Contenu de ce dossier

| Fichier | Rôle |
|---|---|
| `01_schema.sql` | Création de la base `audit_caat` et des 10 tables liées par clés étrangères |
| `02_donnees_demo.sql` | Données de démonstration : rôles, utilisateurs, dossier d'audit, catalogue des 12 tests CAAT, 1 FEC importé, **518 écritures** équilibrées avec anomalies volontairement injectées, résultats de tests et anomalies pré-calculées |
| `init_mots_de_passe.php` | Script à exécuter une fois pour générer les vrais hachages `password_hash()` des comptes de démo |

## Étapes d'installation (XAMPP)

1. Démarrer **Apache** et **MySQL** depuis le panneau XAMPP.
2. Ouvrir **phpMyAdmin** (`http://localhost/phpmyadmin`).
3. Onglet **Importer** → sélectionner `01_schema.sql` → **Exécuter**.
   (Cela crée la base `audit_caat`, inutile de la créer manuellement avant.)
4. Toujours dans **Importer** → sélectionner `02_donnees_demo.sql` → **Exécuter**.
5. Copier `init_mots_de_passe.php` à la racine de votre projet PHP (ex. `C:\xampp\htdocs\audit_caat\`).
6. Dans le navigateur, ouvrir `http://localhost/audit_caat/init_mots_de_passe.php`.
   Cela remplace le marqueur temporaire par un vrai hachage bcrypt pour les 3 comptes de démo.
7. **Supprimer `init_mots_de_passe.php`** après exécution (ne jamais le laisser en production).

## Comptes de démonstration

| Email | Rôle | Mot de passe |
|---|---|---|
| admin@audit-caat.sn | Administrateur | `Audit@2026` |
| auditeur@audit-caat.sn | Utilisateur avancé | `Audit@2026` |
| consultant@audit-caat.sn | Utilisateur standard | `Audit@2026` |

## Anomalies volontairement injectées dans le jeu de données (dossier "STE ATLANTIC SARL", exercice 2025)

Ce jeu de données a été construit pour que chacun de vos algorithmes de test ait effectivement
quelque chose à détecter :

- **4 écritures en doublon** exact (même compte, même montant, même pièce)
- **6 écritures saisies un week-end** (journal OD)
- **8 montants ronds suspects** (multiples de 1 000, journal OD, tous saisis par le même utilisateur)
- **3 écritures avec chronologie inversée** (numéro d'écriture croissant, date qui recule) dans le journal VE
- **3 paires écriture / contre-passation** saisies à J+1 (annulation rapide)
- **18 écritures concentrées sur le 31/12/2025** (pic de fin de période)
- Un utilisateur (**JDUPONT**) saisit volontairement un volume disproportionné d'écritures (~45 % du total), pour tester le classement des top saisisseurs
- Les montants "normaux" sont générés avec une distribution de premier chiffre approximativement conforme à la loi de Benford, ce qui vous permettra de vérifier que votre test Chi²/MAD ne déclenche pas de faux positifs sur les données saines, tout en détectant les injections de montants ronds

## Structure relationnelle (10 tables)

```
roles ──< utilisateurs ──< dossiers_audit ──< fichiers_fec ──< ecritures ──< resultats_tests >── types_tests
                                    │                                │
                                    └──< parametres_test >───────────┘
                                    │
                                    └──< anomalies >── types_tests
utilisateurs ──< journal_audit_actions
```

## Module Import FEC

Le module `modules/import/` est maintenant fonctionnel :
- Formulaire d'upload (`.txt`/`.csv`, 20 Mo max) avec sélection du dossier d'audit
- Détection automatique de l'encodage (UTF-8/ISO-8859-1) et du délimiteur (tabulation, `;`, `,`, `|`)
- Mapping souple des 18 colonnes officielles du FEC (insensible à la casse et aux espaces)
- Contrôle d'équilibre **par écriture** et **global**, avec enregistrement automatique en anomalie (gravité critique) de toute écriture déséquilibrée
- Historique des imports et journalisation de chaque import dans `journal_audit_actions`
- Un fichier d'exemple `assets/exemples/exemple_FEC.txt` (56 lignes, dont une écriture volontairement déséquilibrée de 20) est fourni pour tester le module

**Important** : le dossier `uploads/fec/` doit être accessible en écriture par le serveur web
(sous Linux : `chmod -R 775 uploads/`). Sous XAMPP Windows, cela fonctionne par défaut.

## Lots 2, 3 et 4 — Tests globaux, analyse des comptes, rapport

- **Lot 2** (`includes/tests_caat_globaux.php`) : Benford (méthode MAD de Nigrini), top 10 saisisseurs,
  concentration de fin de période, scoring de risque composite par écriture. Pages dédiées avec graphiques
  Chart.js : `modules/tests/benford.php`, `saisisseurs.php`, `fin_periode.php`, `scoring.php`.
- **Lot 3** (`includes/tests_caat_comptes.php`) : revue analytique N vs N-1, soldes anormaux (sens
  inhabituel selon la classe de compte), comptes sans mouvement. Pages : `modules/comptes/analytique.php`,
  `soldes.php`. **Nécessite le script `03_donnees_n1_demo.sql`** (exercice 2024, dossier n°2) pour être
  démontrable — importez-le après `02_donnees_demo.sql` dans phpMyAdmin (script additif, sans risque).
- **Lot 4** : `modules/rapport/rapport.php` (registre hiérarchisé par gravité + cartographie des risques
  par cycle d'audit) et `modules/rapport/export_pdf.php` (export PDF via FPDF).

### Installation de FPDF (nécessaire pour l'export PDF)

Je n'ai pas d'accès réseau dans mon environnement de préparation du projet, donc **la bibliothèque FPDF
n'est pas incluse**. Voir `vendor/fpdf/README_INSTALLER_FPDF.md` pour les 2 minutes d'installation
(téléchargement d'un unique fichier `fpdf.php` depuis fpdf.org, aucune dépendance Composer requise).
Sans ce fichier, le bouton "Exporter en PDF" affiche un message explicatif au lieu de planter.

## Notes importantes

- Toutes les tables sont en **InnoDB** avec clés étrangères actives (intégrité référentielle garantie).
- Les montants sont en `DECIMAL(15,2)` — ne jamais utiliser `FLOAT` pour des données comptables.
- Le champ `saisi_par` de la table `ecritures` correspond au login du préparateur tel qu'il figure
  dans le FEC d'origine (peut différer des comptes de l'application elle-même).
- Pensez à adapter les identifiants de connexion (`$hote`, `$user`, `$pass`) dans
  `init_mots_de_passe.php` et dans votre futur fichier `config/database.php` si votre
  installation XAMPP diffère des valeurs par défaut (`root` sans mot de passe).

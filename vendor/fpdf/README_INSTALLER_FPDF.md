# Bibliothèque FPDF requise

Ce dossier doit contenir le fichier **`fpdf.php`** de la bibliothèque FPDF
(https://www.fpdf.org), utilisée par `modules/rapport/export_pdf.php` pour
générer le PDF du rapport d'audit.

## Pourquoi ce fichier n'est pas déjà présent

Je n'ai pas d'accès réseau dans l'environnement où j'ai préparé ce projet,
je n'ai donc pas pu télécharger la bibliothèque à votre place.

## Installation (2 minutes, sans Composer)

1. Allez sur http://www.fpdf.org/en/dl.php?v=189 (ou la dernière version stable)
2. Téléchargez l'archive `.zip`
3. Extrayez-en uniquement le fichier `fpdf.php`
4. Placez-le ici : `vendor/fpdf/fpdf.php` (à côté de ce README)
5. Supprimez ce README si vous le souhaitez

Aucune autre configuration n'est nécessaire : `export_pdf.php` fait
`require 'vendor/fpdf/fpdf.php'` et utilise directement la classe `FPDF`.

## Alternative

Si vous préférez DOMPDF ou TCPDF (également autorisés par votre cahier des
charges) et que vous utilisez Composer, vous pouvez adapter `export_pdf.php`
en conséquence — la logique de récupération des données (requêtes SQL) reste
identique, seule la partie mise en page PDF change.

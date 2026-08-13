/**
 * validation.js — Validation côté client (ne remplace jamais la validation serveur)
 */

document.addEventListener('DOMContentLoaded', function () {
  var formConnexion = document.getElementById('formConnexion');
  if (formConnexion) {
    formConnexion.addEventListener('submit', function (e) {
      var email = document.getElementById('email');
      var mdp = document.getElementById('mot_de_passe');
      var erreurEmail = document.getElementById('erreur-email');
      var erreurMdp = document.getElementById('erreur-mdp');
      var valide = true;

      erreurEmail.textContent = '';
      erreurMdp.textContent = '';

      var regexEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!regexEmail.test(email.value.trim())) {
        erreurEmail.textContent = 'Veuillez saisir une adresse email valide.';
        valide = false;
      }
      if (mdp.value.length < 4) {
        erreurMdp.textContent = 'Le mot de passe est requis.';
        valide = false;
      }
      if (!valide) {
        e.preventDefault();
      }
    });
  }

  var formImport = document.getElementById('formImportFec');
  if (formImport) {
    formImport.addEventListener('submit', function (e) {
      var dossier = document.getElementById('dossier_id');
      var fichier = document.getElementById('fichier_fec');
      var erreurFichier = document.getElementById('erreur-fichier');
      var valide = true;

      erreurFichier.textContent = '';

      if (!dossier.value) {
        valide = false;
      }
      if (!fichier.files || fichier.files.length === 0) {
        erreurFichier.textContent = 'Veuillez sélectionner un fichier.';
        valide = false;
      } else {
        var nom = fichier.files[0].name.toLowerCase();
        var extensionValide = nom.endsWith('.txt') || nom.endsWith('.csv');
        if (!extensionValide) {
          erreurFichier.textContent = 'Seuls les fichiers .txt et .csv sont acceptés.';
          valide = false;
        }
        var tailleMax = 20 * 1024 * 1024;
        if (fichier.files[0].size > tailleMax) {
          erreurFichier.textContent = 'Le fichier dépasse 20 Mo.';
          valide = false;
        }
      }

      if (!valide) {
        e.preventDefault();
      }
    });
  }
});

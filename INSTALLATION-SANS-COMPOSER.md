# Installation sans Composer (debutant)

Ce guide permet d'installer l'application sans avoir Composer installe sur le PC.
Le script `install.bat` telecharge automatiquement une version locale de Composer si necessaire.

## 1) Installer Laragon

1. Telecharger et installer Laragon.
2. Ouvrir Laragon.
3. Verifier que:
   - Apache est en version 2.4 ou plus
   - PHP est en version 8.3
4. Si besoin, changer la version de PHP dans Laragon: `Menu > PHP > Version`.

## 2) Copier le projet au bon endroit

1. Fermer l'application si elle tourne.
2. Copier le dossier du projet dans:

   `C:\laragon\www\`

Exemple:

`C:\laragon\www\coupe-n1-app`

## 3) Lancer l'installation automatique

1. Ouvrir le dossier du projet.
2. Double-cliquer sur `install.bat`.
3. Attendre le message de succes.

Le script fait automatiquement:
- detection de PHP 8.3
- installation des dependances PHP (automatique, meme sans Composer global)
- creation de `.env` depuis `.env.example` (si absent)
- nettoyage du cache Symfony
- verification de la console Symfony

Note:
- Une connexion internet est necessaire au premier lancement pour telecharger les dependances.

## 4) Demarrer les services

Dans Laragon:

1. Cliquer sur `Start All` (Apache + MySQL).
2. Ouvrir le site depuis `Menu > www > coupe-n1-app`.

## 5) Si erreur de base de donnees

1. Ouvrir le fichier `.env`.
2. Adapter la ligne `DATABASE_URL` a votre configuration locale MySQL/MariaDB.
3. Relancer `install.bat`.

## Important

- Le projet recupere en ZIP depuis GitHub fonctionne: `install.bat` installe les dependances automatiquement.
- L'utilisateur final n'a pas besoin d'installer Composer manuellement.

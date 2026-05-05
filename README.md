# Coupe N1 App

## Installation simple (sans Composer)

Cette application peut etre installee sur un PC Windows avec Laragon, meme sans connaissance technique.

Voir le guide detaille: `INSTALLATION-SANS-COMPOSER.md`

Resume ultra-court:

1. Installer Laragon (Apache 2.4+ et PHP 8.3).
2. Copier le dossier du projet dans `C:\laragon\www\`.
3. Double-cliquer sur `install.bat`.
4. Demarrer Apache + MySQL dans Laragon.
5. Ouvrir le site depuis le menu Laragon.

## Installation developpeur (avec Composer)

Si Composer est disponible:

```bash
composer install
copy .env.example .env
php bin/console cache:clear
```

## Notes techniques

- Le `DocumentRoot` web doit pointer sur `public/`.
- `mod_rewrite` Apache doit etre actif.
- En production, definir un `APP_SECRET` robuste et `APP_ENV=prod`.

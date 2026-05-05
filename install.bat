@echo off
setlocal enabledelayedexpansion
cd /d "%~dp0"
title Installation Coupe N1 App (sans Composer)

echo ===============================================
echo   Installation automatique Coupe N1 App
echo ===============================================
echo.

set "PHP_BIN="
for /f "delims=" %%i in ('where php 2^>nul') do (
    set "PHP_BIN=%%i"
    goto :php_found
)

if exist "C:\laragon\bin\php" (
    for /f "delims=" %%d in ('dir /b /ad /o-n "C:\laragon\bin\php\php-8.3*" 2^>nul') do (
        if exist "C:\laragon\bin\php\%%d\php.exe" (
            set "PHP_BIN=C:\laragon\bin\php\%%d\php.exe"
            goto :php_found
        )
    )
)

echo [ERREUR] PHP 8.3 introuvable.
echo - Ouvre Laragon
echo - Menu PHP ^> Version ^> choisis une version 8.3
echo - Puis relance ce script
pause
exit /b 1

:php_found
echo [OK] PHP detecte: %PHP_BIN%

if not exist "vendor\autoload.php" (
    echo.
    echo [INFO] Dependances PHP absentes. Installation locale en cours...
    call :install_deps_without_global_composer
    if errorlevel 1 (
        pause
        exit /b 1
    )
)

if not exist ".env" (
    if exist ".env.example" (
        copy /Y ".env.example" ".env" >nul
        echo [OK] Fichier .env cree depuis .env.example
    ) else (
        echo [ATTENTION] .env.example introuvable, creation ignoree.
    )
) else (
    echo [OK] Fichier .env deja present
)

if not exist "var\cache" mkdir "var\cache" >nul 2>&1
if not exist "var\log" mkdir "var\log" >nul 2>&1

echo.
echo Nettoyage du cache Symfony...
"%PHP_BIN%" "bin\console" cache:clear
if errorlevel 1 (
    echo [ERREUR] Echec du cache:clear.
    echo Verifie le fichier .env: DATABASE_URL, APP_ENV, etc.
    pause
    exit /b 1
)

echo.
echo Verification de la console Symfony...
"%PHP_BIN%" "bin\console" --version
if errorlevel 1 (
    echo [ERREUR] La console Symfony ne repond pas.
    pause
    exit /b 1
)

echo.
echo ===============================================
echo Installation terminee avec succes.
echo.
echo Etapes suivantes:
echo 1) Ouvre Laragon et demarre Apache + MySQL
echo 2) Verifie/ajuste DATABASE_URL dans .env
echo 3) Ouvre le site via Laragon (Auto Virtual Hosts)
echo ===============================================
echo.
pause
exit /b 0

:install_deps_without_global_composer
set "COMPOSER_CMD="

for /f "delims=" %%i in ('where composer 2^>nul') do (
    set "COMPOSER_CMD=%%i"
    goto :composer_ready
)

set "TOOLS_DIR=.tools"
set "COMPOSER_PHAR=%TOOLS_DIR%\composer.phar"

if not exist "%TOOLS_DIR%" mkdir "%TOOLS_DIR%" >nul 2>&1

if not exist "%COMPOSER_PHAR%" (
    echo [INFO] Telechargement de Composer en local...
    powershell -NoProfile -ExecutionPolicy Bypass -Command "try { Invoke-WebRequest -UseBasicParsing -Uri 'https://getcomposer.org/composer-stable.phar' -OutFile '%COMPOSER_PHAR%' } catch { exit 1 }"
    if errorlevel 1 (
        echo [ERREUR] Impossible de telecharger Composer.
        echo Verifie la connexion internet, puis relance install.bat.
        exit /b 1
    )
)

set "COMPOSER_CMD=%PHP_BIN% %COMPOSER_PHAR%"

:composer_ready
echo [INFO] Installation des dependances (Composer)...
call %COMPOSER_CMD% install --no-interaction --prefer-dist
if errorlevel 1 (
    echo [ERREUR] Echec de l'installation des dependances.
    echo Verifie la connexion internet et les permissions du dossier.
    exit /b 1
)

if not exist "vendor\autoload.php" (
    echo [ERREUR] Installation Composer terminee mais vendor/autoload.php est introuvable.
    exit /b 1
)

echo [OK] Dependances installees.
exit /b 0

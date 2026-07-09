<?php

declare(strict_types=1);

namespace Matthieuthuetdev\SymfonyDeployer;

final class SymfonyDeployer
{
    public function deploy(): void
    {
        $projectRoot = getcwd() ?: '.';
        $envLocalPath = $projectRoot . DIRECTORY_SEPARATOR . '.env.local';
        $publicDirectory = $projectRoot . DIRECTORY_SEPARATOR . 'public';
        $htaccessContent = <<<'HTACCESS'
<IfModule mod_rewrite.c>
    RewriteEngine On

    RewriteCond %{REQUEST_URI}::$0 ^(/.+)/(.*)::\2$
    RewriteRule .* - [E=BASE:%1]

    RewriteCond %{HTTP:Authorization} .
    RewriteRule ^ - [E=HTTP_AUTHORIZATION:%0]

    RewriteCond %{ENV:REDIRECT_STATUS} ^$
    RewriteRule ^index\.php(?:/(.*)|$) %{ENV:BASE}/$1 [R=301,L]

    RewriteCond %{REQUEST_FILENAME} -f
    RewriteRule ^ - [L]

    RewriteCond %{REQUEST_FILENAME} -d
    RewriteRule ^ - [L]

    RewriteRule ^ %{ENV:BASE}/index.php [L]
</IfModule>

<IfModule !mod_rewrite.c>
    RedirectMatch 307 ^/$ /index.php/
</IfModule>
HTACCESS;
        $readLine = static function (string $prompt): string {
            if (function_exists('readline')) {
                $line = readline($prompt);

                return $line === false ? 'q' : trim($line);
            }

            echo $prompt;

            $line = fgets(STDIN);

            return $line === false ? 'q' : trim($line);
        };
        $writeLine = static function (string $message = ''): void {
            echo $message . PHP_EOL;
        };
        $runCommand = static function (string $command) use ($writeLine): bool {
            $writeLine('');
            $writeLine('> ' . $command);
            passthru($command, $exitCode);
            $writeLine('');

            if ($exitCode !== 0) {
                $writeLine('La commande a echoue avec le code ' . $exitCode . '.');

                return false;
            }

            $writeLine('Commande terminee avec succes.');

            return true;
        };
        $buildDatabaseUrl = static function () use ($readLine, $writeLine): string {
            $databaseName = $readLine('Nom de la base de donnees : ');
            $databaseUser = $readLine('Nom d utilisateur : ');
            $databasePassword = $readLine('Mot de passe : ');
            $databaseHost = $readLine('Host : ');

            $writeLine('Creation de la ligne DATABASE_URL...');

            return sprintf(
                'DATABASE_URL="mysql://%s:%s@%s/%s"',
                rawurlencode($databaseUser),
                rawurlencode($databasePassword),
                $databaseHost,
                rawurlencode($databaseName)
            );
        };
        $normalizeEnvLine = static function (string $input) use ($buildDatabaseUrl): string {
            $trimmedInput = trim($input);
            $lowerInput = strtolower($trimmedInput);

            if ($lowerInput === 'database') {
                return $buildDatabaseUrl();
            }

            if ($lowerInput === 'prod' || $lowerInput === 'env') {
                return 'APP_ENV=' . $lowerInput;
            }

            return $trimmedInput;
        };
        $installLibrary = static function () use ($runCommand, $writeLine): void {
            $writeLine('');
            $writeLine('Installation de la librairie...');

            if (!$runCommand('composer install --optimize-autoloader')) {
                return;
            }

            $runCommand('composer update');
        };
        $addHtaccess = static function () use ($publicDirectory, $htaccessContent, $writeLine): void {
            $writeLine('');
            $writeLine('Ajout du fichier .htaccess...');

            if (!is_dir($publicDirectory) && !mkdir($publicDirectory, 0777, true) && !is_dir($publicDirectory)) {
                $writeLine('Impossible de creer le dossier public.');

                return;
            }

            $htaccessPath = $publicDirectory . DIRECTORY_SEPARATOR . '.htaccess';

            if (file_put_contents($htaccessPath, $htaccessContent . PHP_EOL) === false) {
                $writeLine('Impossible d ecrire le fichier .htaccess.');

                return;
            }

            $writeLine('Le fichier .htaccess a ete cree dans public/.');
        };
        $editEnvLocal = static function () use ($envLocalPath, $normalizeEnvLine, $readLine, $writeLine, $runCommand): void {
            $writeLine('');
            $writeLine('Creation ou modification du fichier .env.local...');

            $lines = is_file($envLocalPath) ? file($envLocalPath, FILE_IGNORE_NEW_LINES) : [];

            if ($lines === false) {
                $writeLine('Impossible de lire le fichier .env.local.');

                return;
            }

            $updatedLines = [];

            if ($lines !== []) {
                $writeLine('Lignes existantes : laissez vide pour conserver la ligne actuelle.');

                foreach ($lines as $index => $line) {
                    $writeLine('');
                    $writeLine('Ligne ' . ($index + 1) . ' actuelle : ' . $line);
                    $input = $readLine('Nouvelle valeur (ou q pour quitter) : ');

                    if (strtolower($input) === 'q') {
                        $writeLine('Retour au menu principal avec enregistrement des modifications en cours.');

                        break;
                    }

                    if ($input === '') {
                        $updatedLines[] = $line;

                        continue;
                    }

                    $updatedLines[] = $normalizeEnvLine($input);
                }
            }

            $writeLine('');
            $writeLine('Ajout de nouvelles lignes : laissez vide pour terminer.');

            $lineNumber = count($updatedLines) + 1;

            while (true) {
                $input = $readLine('Ligne ' . $lineNumber . ' (ou q pour quitter) : ');

                if (strtolower($input) === 'q') {
                    $writeLine('Retour au menu principal avec enregistrement des modifications en cours.');

                    break;
                }

                if ($input === '') {
                    break;
                }

                $updatedLines[] = $normalizeEnvLine($input);
                $lineNumber++;
            }

            $content = implode(PHP_EOL, $updatedLines);

            if ($content !== '') {
                $content .= PHP_EOL;
            }

            if (file_put_contents($envLocalPath, $content) === false) {
                $writeLine('Impossible d ecrire le fichier .env.local.');

                return;
            }

            $writeLine('Le fichier .env.local a ete mis a jour.');

            foreach ($updatedLines as $updatedLine) {
                if (str_starts_with($updatedLine, 'DATABASE_URL=')) {
                    $writeLine('DATABASE_URL detectee, lancement des migrations...');
                    $runCommand('php bin/console doctrine:migrations:migrate --no-interaction');

                    break;
                }
            }
        };
        $runAllFeatures = static function () use ($installLibrary, $addHtaccess, $editEnvLocal): void {
            $installLibrary();
            $addHtaccess();
            $editEnvLocal();
        };

        while (true) {
            $writeLine('');
            $writeLine('=== Symfony Deployer ===');
            $writeLine('1. Install library');
            $writeLine('2. Ajouter un .htaccess');
            $writeLine('3. Creer ou modifier .env.local');
            $writeLine('Entree vide : lancer toutes les fonctionnalites');
            $writeLine('Q : quitter');
            $choice = strtolower($readLine('Votre choix : '));

            if ($choice === 'q') {
                $writeLine('Fermeture du deployer.');

                return;
            }

            if ($choice === '') {
                $runAllFeatures();

                continue;
            }

            if ($choice === '1') {
                $installLibrary();

                continue;
            }

            if ($choice === '2') {
                $addHtaccess();

                continue;
            }

            if ($choice === '3') {
                $editEnvLocal();

                continue;
            }

            $writeLine('Choix invalide. Merci de saisir 1, 2, 3, Entree ou Q.');
        }
    }
}

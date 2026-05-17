# ITGA - Guide de deploiement public immediat

Date de preparation locale: 2026-05-17

Ce document est fait pour etre execute pas a pas. Il ne contient pas les secrets en clair. Les secrets reels sont dans le fichier local ignore par Git:

```text
secrets/ITGA_DEPLOYMENT_SECRETS.local.md
```

Regle absolue: ne jamais envoyer ce fichier dans GitHub, WhatsApp, email, Slack ou a un prestataire non autorise. Si une cle est affichee dans un terminal public ou partagee par erreur, il faut la regenerer.

## 0. Ce qui est deja fait localement

Ces actions ont deja ete faites sur cette machine:

1. Les trois projets ont ete relies a leurs repositories GitHub:
   - Backend/admin Laravel: `https://github.com/Djouko/itga-backend.git`
   - Web Next.js: `https://github.com/Djouko/itga-web.git`
   - Mobile Flutter Android/iOS: `https://github.com/Djouko/itga-mobile.git`
2. Les secrets de deploiement ont ete generes dans `secrets/ITGA_DEPLOYMENT_SECRETS.local.md`.
3. Le fichier `.gitignore` racine ignore `secrets/` et `.deployment/`.
4. Android a un keystore de release local dans:

```text
secrets/itga-upload-keystore.jks
```

5. Android a un fichier de signature local ignore par Git:

```text
chatter_flutter/chatter/android/key.properties
```

6. Les builds Android release signes ont ete generes:

```text
chatter_flutter/chatter/build/app/outputs/flutter-apk/app-release.apk
chatter_flutter/chatter/build/app/outputs/bundle/release/app-release.aab
```

7. Le backend a ete prepare pour fonctionner sans Redis au debut sur Hostinger, en utilisant:
   - cache en base de donnees
   - sessions en base de donnees
   - queue en base de donnees
8. Les migrations backend suivantes ont ete ajoutees:
   - `database/migrations/2026_05_17_000001_create_cache_table.php`
   - `database/migrations/2026_05_17_000002_create_sessions_table.php`
   - `database/migrations/2026_05_17_000003_create_jobs_table.php`
9. La readiness backend en mode production local passe avec les secrets generes.
10. Les tests backend passent localement.
11. Le build web Next.js passe localement avec l'URL API et la cle publique API.
12. Les tests Flutter passent localement.
13. La verification iOS locale possible depuis Windows passe via le script de preflight, mais le vrai build iOS signe doit etre fait sur macOS ou Codemagic.

## 1. Feu vert honnete

Etat honnete: ITGA est pret pour une mise en ligne controlee si les etapes de ce guide sont executees.

Ce que cela veut dire:

- Oui, on peut deployer backend, web et mobile pour de vrais utilisateurs.
- Oui, le backend a des protections minimales de production: secrets, readiness token, cache/session/queue partageables via database, tests, migrations, rate limit deja prevu par Laravel.
- Oui, Android a un APK et un AAB release signes.
- Oui, iOS a une configuration Codemagic preparee.

Ce que cela ne veut pas dire:

- Hostinger seul ne garantit pas des millions de requetes par seconde.
- Le build iOS signe ne peut pas etre valide sur Windows; il faut lancer Codemagic.
- Les performances reelles doivent etre validees apres deploiement par un test de charge.
- Pour une croissance type LinkedIn, il faudra progressivement passer a une architecture avec plusieurs serveurs, Redis, CDN, stockage objet, workers separes, monitoring et autoscaling.

Decision recommandee:

1. Deployer maintenant une version publique controlee.
2. Lancer d'abord un groupe restreint de vrais utilisateurs.
3. Surveiller logs, erreurs, latence, inscriptions, upload media, feed, notifications.
4. Augmenter progressivement le trafic.
5. Migrer vers l'architecture scalable decrite plus bas avant un gros lancement marketing.

## 2. Fichiers importants a ne jamais perdre

### 2.1 Secrets de deploiement

Fichier local:

```text
secrets/ITGA_DEPLOYMENT_SECRETS.local.md
```

Il contient notamment:

- `APP_KEY`
- `API_SECRET_KEY`
- `ADMIN_API_SECRET_KEY`
- `READINESS_TOKEN`
- `NEXT_PUBLIC_API_URL`
- `NEXT_PUBLIC_API_KEY`
- `ITGA_API_KEY`
- mots de passe Android keystore

Action obligatoire:

1. Copie ce fichier sur une cle USB chiffree ou dans un coffre-fort de mots de passe.
2. Ne le mets jamais dans GitHub.
3. Ne le colle jamais dans une conversation publique.

### 2.2 Keystore Android

Fichier local:

```text
secrets/itga-upload-keystore.jks
```

Action obligatoire:

1. Sauvegarde ce fichier.
2. Sauvegarde aussi les mots de passe dans `secrets/ITGA_DEPLOYMENT_SECRETS.local.md`.
3. Si tu perds ce fichier, les futures mises a jour Android peuvent devenir tres difficiles ou impossibles selon la configuration Google Play.

## 3. Variables exactes a configurer

Ouvre:

```text
secrets/ITGA_DEPLOYMENT_SECRETS.local.md
```

Tu vas copier les valeurs dans les endroits indiques. Ne change pas les noms des variables.

### 3.1 Backend Hostinger

Dans le fichier `.env` du backend sur Hostinger, ajoute ou remplace:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://itga.kekottech.com
APP_KEY=<copier APP_KEY depuis secrets/ITGA_DEPLOYMENT_SECRETS.local.md>

API_SECRET_KEY=<copier API_SECRET_KEY depuis secrets/ITGA_DEPLOYMENT_SECRETS.local.md>
ADMIN_API_SECRET_KEY=<copier ADMIN_API_SECRET_KEY depuis secrets/ITGA_DEPLOYMENT_SECRETS.local.md>
READINESS_TOKEN=<copier READINESS_TOKEN depuis secrets/ITGA_DEPLOYMENT_SECRETS.local.md>

CACHE_DRIVER=database
SESSION_DRIVER=database
QUEUE_CONNECTION=database
LOG_LEVEL=error
```

Garde aussi les variables deja presentes et correctes sur le serveur:

```env
DB_CONNECTION=mysql
DB_HOST=<valeur Hostinger>
DB_PORT=3306
DB_DATABASE=<valeur Hostinger>
DB_USERNAME=<valeur Hostinger>
DB_PASSWORD=<valeur Hostinger>

MAIL_MAILER=<valeur existante>
MAIL_HOST=<valeur existante>
MAIL_PORT=<valeur existante>
MAIL_USERNAME=<valeur existante>
MAIL_PASSWORD=<valeur existante>
MAIL_ENCRYPTION=<valeur existante>
MAIL_FROM_ADDRESS=<valeur existante>
MAIL_FROM_NAME="ITGA"
```

Si Firebase est utilise par le backend, garde aussi les variables Firebase existantes et le fichier credential deja installe sur le serveur. Ne commit jamais le fichier credential reel.

### 3.2 Web EasyPanel

Dans EasyPanel, onglet Environment du service web:

```env
NEXT_PUBLIC_API_URL=https://itga.kekottech.com/api
NEXT_PUBLIC_API_KEY=<copier API_SECRET_KEY depuis secrets/ITGA_DEPLOYMENT_SECRETS.local.md>
NODE_ENV=production
```

Important: dans Next.js, les variables `NEXT_PUBLIC_*` sont integrees au build. Donc si tu changes `NEXT_PUBLIC_API_KEY`, il faut redeployer/rebuilder le web.

### 3.3 Mobile Android et iOS

Dans les builds Flutter:

```env
ITGA_API_KEY=<copier API_SECRET_KEY depuis secrets/ITGA_DEPLOYMENT_SECRETS.local.md>
```

Android local utilise deja cette cle pendant le build release.

Codemagic devra aussi recevoir:

```env
ITGA_API_KEY=<copier API_SECRET_KEY depuis secrets/ITGA_DEPLOYMENT_SECRETS.local.md>
FIREBASE_IOS_PLIST_B64=<base64 du fichier ios/Runner/GoogleService-Info.plist>
FIREBASE_ANDROID_JSON_B64=<base64 du fichier android/app/google-services.json>
```

## 4. Backend sur Hostinger

Situation actuelle: une ancienne version backend a ete uploadee manuellement sur Hostinger. Elle n'est pas encore connectee proprement a GitHub.

Le risque: si tu remplaces le dossier directement sans backup, tu peux perdre:

- `.env` serveur
- fichier Firebase reel
- fichiers uploades par les utilisateurs
- configuration `.htaccess`
- logs utiles

Donc on fait proprement.

### 4.0 Reponse claire pour le backend Hostinger deja vivant

Oui, on peut mettre a jour proprement le backend deja utilise par les tests web/mobile.

Non, il ne faut pas simplement supprimer l'ancien dossier puis uploader le nouveau dossier en bloc.

Ce qui existe deja en production est separe en 4 familles:

1. Code Laravel: fichiers PHP, routes, controllers, migrations, config.
2. Donnees MySQL: comptes, posts, rooms, comments, tokens, settings.
3. Medias uploades: images/videos/documents deja envoyes par les utilisateurs.
4. Secrets serveur: `.env`, credentials Firebase, cles API, mots de passe DB.

La mise a jour doit remplacer seulement la famille 1, puis executer les migrations pour adapter la famille 2. Elle ne doit pas detruire les familles 3 et 4.

Dans ce projet, les medias locaux sont principalement stockes ici:

```text
storage/app/public/uploads
```

Ils sont servis par le lien Laravel:

```text
public/storage -> storage/app/public
```

Il peut aussi y avoir des fichiers modifies par l'admin ici:

```text
public/asset
```

Donc ces chemins doivent etre sauvegardes avant toute bascule.

### 4.0.1 Est-ce possible de passer par GitHub ?

Oui, mais pas directement n'importe comment.

Option A - Recommandee maintenant, car tu es en urgence:

```text
Archive propre locale -> Upload Hostinger -> Bascule controlee
```

Avantage:

- pas de conflit Git sur le serveur
- tu gardes le controle
- tu ne touches pas brutalement au backend qui fonctionne deja
- tu peux revenir a l'ancien dossier si probleme

Option B - GitHub propre plus tard:

```text
Nouveau dossier vide/staging Hostinger -> clone/pull GitHub -> test -> bascule
```

Avantage:

- les prochaines mises a jour seront plus simples

Mais ne fais pas ceci directement dans le dossier backend actuel sans backup, parce que Hostinger peut refuser un deploy Git dans un dossier non vide ou creer un etat confus entre fichiers uploades manuellement et fichiers Git.

### 4.0.2 Est-ce qu'il y aura des conflits ?

Avec l'option archive propre, il n'y a pas de "conflit Git", car Git ne gere pas le dossier serveur actuel.

Il y a seulement des risques d'ecrasement si tu copies mal les fichiers. Les fichiers a ne jamais ecraser/perdre sont:

```text
.env
storage/app/public/uploads
public/storage
public/asset/apple-app-site-association
public/asset/assetlinks.json
googleCredentials.json ou itga-firebase-prod.json reel
```

La base de donnees ne sera pas effacee par l'upload du code. Elle change uniquement quand tu executes:

```bash
php artisan migrate --force
```

Les migrations ajoutent/modifient des tables. Elles ne doivent pas supprimer tes comptes/posts existants. Mais il faut toujours faire un export SQL avant, parce qu'une migration de production se traite comme une operation serieuse.

### 4.0.3 Methode la plus sure pour ton cas exact

Utilise une bascule en deux dossiers.

Ancien dossier:

```text
itga-backend-current
```

Nouveau dossier:

```text
itga-backend-new
```

Principe:

1. Tu gardes l'ancien backend intact.
2. Tu prepares le nouveau backend a cote.
3. Tu copies dedans seulement les secrets et medias necessaires.
4. Tu testes.
5. Tu renommes les dossiers pour basculer.
6. Si probleme, tu reviens a l'ancien dossier.

Cette methode evite le chaos.

### 4.0.4 Procedure exacte sans GitHub sur Hostinger

Etape 1 - Faire backup fichiers:

```text
Hostinger File Manager -> dossier backend actuel -> Compress -> Download
```

Etape 2 - Faire backup base de donnees:

```text
Hostinger hPanel -> Databases -> phpMyAdmin -> base ITGA -> Export -> SQL
```

Etape 3 - Noter le chemin exact du backend actuel.

Exemples possibles:

```text
/home/u123456789/domains/itga.kekottech.com/public_html
/home/u123456789/domains/itga.kekottech.com/public_html/api
/home/u123456789/domains/itga.kekottech.com/itga-backend
```

Etape 4 - Dans l'ancien dossier, sauvegarder ces elements:

```text
.env
storage/app/public/uploads
public/storage
public/asset/apple-app-site-association
public/asset/assetlinks.json
itga-firebase-prod.json ou autre credential Firebase reel
```

Etape 5 - Uploader l'archive propre:

```text
.deployment/itga-backend-main.zip
```

Etape 6 - Extraire dans un nouveau dossier:

```text
itga-backend-new
```

Etape 7 - Copier l'ancien `.env` dans `itga-backend-new`.

Etape 8 - Mettre a jour le `.env` de `itga-backend-new` avec les valeurs du fichier local:

```text
secrets/ITGA_DEPLOYMENT_SECRETS.local.md
```

Etape 9 - Copier les credentials Firebase reels dans `itga-backend-new` si le backend en depend.

Etape 10 - Copier ou reconnecter les medias.

Si tu veux copier les medias:

```bash
cp -R /chemin/ancien/storage/app/public/uploads /chemin/nouveau/storage/app/public/
```

Si le dossier est tres gros, prefere `rsync`:

```bash
rsync -av /chemin/ancien/storage/app/public/uploads/ /chemin/nouveau/storage/app/public/uploads/
```

Etape 11 - Refaire le lien storage dans le nouveau dossier:

```bash
cd /chemin/nouveau
php artisan storage:link
```

Etape 12 - Installer les dependances:

```bash
composer install --no-dev --optimize-autoloader
```

Etape 13 - Tester la config sans migrer:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan ops:public-readiness --json
```

Etape 14 - Si readiness OK, lancer les migrations:

```bash
php artisan migrate --force
```

Etape 15 - Tester les endpoints:

```text
https://itga.kekottech.com/api/health
```

Etape 16 - Basculer les dossiers.

Exemple:

```bash
mv itga-backend itga-backend-old
mv itga-backend-new itga-backend
```

Adapte les noms selon ton serveur.

Etape 17 - Tester depuis web/mobile:

- login
- creation compte
- feed
- posts existants
- images/videos existantes
- upload nouveau post
- suggestions rooms

### 4.0.5 Methode avec GitHub plus tard

Quand l'urgence est passee, on peut rendre Hostinger plus propre avec GitHub.

Ne connecte pas GitHub directement au dossier actuel deja vivant.

Fais plutot:

1. Cree un sous-domaine staging:

```text
staging-api.itga.kekottech.com
```

2. Cree un dossier vide:

```text
staging-backend
```

3. Dans Hostinger Git, connecte:

```text
https://github.com/Djouko/itga-backend.git
```

4. Branche:

```text
main
```

5. Deploy dans `staging-backend`.
6. Ajoute un `.env` staging.
7. Connecte une copie de la DB ou la DB production en lecture prudente selon besoin.
8. Teste.
9. Quand staging est bon, tu peux basculer proprement.

Apres cela, les futures mises a jour pourront etre:

```bash
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Mais cette methode doit venir apres avoir stabilise le dossier serveur, pas pendant une urgence avec un backend manuel deja utilise.

### 4.1 Faire une sauvegarde avant tout

Dans Hostinger hPanel:

1. Va dans `Websites`.
2. Clique `Manage` sur le site ITGA.
3. Ouvre `File Manager`.
4. Va dans le dossier ou le backend Laravel est installe.
5. Selectionne le dossier backend actuel.
6. Clique `Compress` ou `Archive` si l'option existe.
7. Telecharge l'archive sur ton ordinateur.

Ensuite sauvegarde la base de donnees:

1. Va dans hPanel.
2. Ouvre `Databases`.
3. Ouvre `phpMyAdmin`.
4. Selectionne la base ITGA.
5. Clique `Export`.
6. Choisis export rapide SQL.
7. Telecharge le fichier `.sql`.

Ne continue pas sans ces deux backups.

### 4.2 Recuperer les valeurs serveur actuelles

Sur Hostinger, ouvre le fichier `.env` actuel du backend.

Copie dans un bloc-notes temporaire:

```env
DB_HOST=
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=
MAIL_HOST=
MAIL_USERNAME=
MAIL_PASSWORD=
```

Ne les mets pas dans GitHub.

### 4.3 Option recommandee pour cette urgence: upload propre par archive

Comme le serveur actuel a ete uploade manuellement, l'option la moins risquee pour cette urgence est:

1. Creer une archive backend propre depuis le repo local.
2. Uploader cette archive sur Hostinger.
3. Replacer les fichiers sans ecraser les secrets.
4. Executer Composer et Artisan.

L'archive propre locale est creee dans:

```text
.deployment/itga-backend-main.zip
```

Si elle n'existe pas encore, depuis la racine locale du workspace, lance:

```powershell
git -C chatter_backend archive --format=zip --output ..\.deployment\itga-backend-main.zip HEAD
```

Cette archive ne contient pas `.env`, pas `vendor/`, pas `secrets/`.

Sur Hostinger:

1. Va dans `File Manager`.
2. Ouvre le dossier du backend.
3. Uploade `.deployment/itga-backend-main.zip`.
4. Extrais l'archive dans un nouveau dossier temporaire, par exemple:

```text
itga-backend-new
```

5. Verifie que le dossier extrait contient:

```text
artisan
composer.json
app/
bootstrap/
config/
database/
public/
routes/
storage/
```

6. Copie l'ancien `.env` dans le nouveau dossier.
7. Mets a jour le `.env` avec les variables de la section 3.1.
8. Copie aussi les fichiers secrets serveur necessaires, par exemple Firebase, si le backend en a besoin.

Quand le nouveau dossier est pret, tu peux basculer:

1. Renomme l'ancien dossier en `itga-backend-old`.
2. Renomme `itga-backend-new` avec le nom exact attendu par ton domaine ou ton sous-domaine.
3. Si le domaine pointe vers un dossier `public_html`, assure-toi que le document root pointe vers `public/`.

### 4.4 Si Hostinger ne permet pas document root vers public/

Laravel doit servir `public/index.php`.

Si Hostinger pointe vers `public_html` et ne permet pas de choisir `public/`, utilise un `.htaccess` a la racine qui redirige vers `public/`. Hostinger documente une regle de ce type:

```apache
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteRule ^(.*)$ public/$1 [L]
</IfModule>
```

Si ton serveur actuel fonctionne deja avec une autre configuration, garde cette configuration et ne la casse pas.

### 4.5 Commandes SSH Hostinger

Connecte-toi en SSH dans Hostinger.

Va dans le dossier backend Laravel, celui qui contient `artisan`:

```bash
cd /home/<ton_user_hostinger>/domains/<ton_domaine>/public_html
```

Si ton backend est dans un sous-dossier, adapte:

```bash
cd /home/<ton_user_hostinger>/domains/<ton_domaine>/public_html/<dossier_backend>
```

Verifie que tu es au bon endroit:

```bash
ls
```

Tu dois voir:

```text
artisan
composer.json
app
bootstrap
config
database
public
routes
```

Installe les dependances:

```bash
composer install --no-dev --optimize-autoloader
```

Si Hostinger demande `composer2`, utilise:

```bash
composer2 install --no-dev --optimize-autoloader
```

Execute les migrations:

```bash
php artisan migrate --force
```

Nettoie et optimise Laravel:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Cree le lien storage si necessaire:

```bash
php artisan storage:link
```

Lance la verification readiness:

```bash
php artisan ops:public-readiness --json
```

Resultat attendu:

```json
{
  "ok": true
}
```

S'il y a `ok: false`, ne lance pas publiquement. Lis les blockers.

### 4.6 Queue backend sur Hostinger

Le backend est configure pour `QUEUE_CONNECTION=database`. Cela evite de bloquer les requetes utilisateur avec des travaux lourds, mais seulement si un worker tourne.

Commande worker:

```bash
php artisan queue:work --tries=3 --timeout=120
```

Sur un serveur ideal, on lance cela avec Supervisor.

Sur Hostinger mutualise, si Supervisor n'est pas disponible:

1. Va dans hPanel.
2. Ouvre `Advanced`.
3. Ouvre `Cron Jobs`.
4. Ajoute un cron qui execute regulierement:

```bash
/usr/bin/php /home/<ton_user_hostinger>/domains/<ton_domaine>/public_html/artisan queue:work --stop-when-empty --tries=3 --timeout=120
```

Frequence recommandee au depart:

```text
Every minute
```

Si Hostinger refuse chaque minute, mets la frequence minimale disponible.

Ajoute aussi le scheduler Laravel:

```bash
/usr/bin/php /home/<ton_user_hostinger>/domains/<ton_domaine>/public_html/artisan schedule:run
```

Frequence:

```text
Every minute
```

### 4.7 Tests backend apres deploiement

Dans le navigateur:

```text
https://itga.kekottech.com/api/health
```

Si endpoint disponible, il doit repondre sans erreur 500.

Depuis ton ordinateur, avec PowerShell:

```powershell
$token = "<copie READINESS_TOKEN depuis secrets/ITGA_DEPLOYMENT_SECRETS.local.md>"
Invoke-RestMethod -Uri "https://itga.kekottech.com/api/readiness" -Headers @{ "x-readiness-token" = $token }
```

Si cela echoue:

1. Verifie `READINESS_TOKEN` dans `.env`.
2. Verifie `php artisan config:clear`.
3. Refais `php artisan config:cache`.
4. Lis les logs Laravel:

```bash
tail -n 100 storage/logs/laravel.log
```

### 4.7.1 Si readiness affiche `"environment": "local"`

Si tu vois ceci:

```json
"environment": "local"
```

alors le backend n'est pas encore en mode production Laravel, meme si `ok` vaut `true`.

C'est important: en mode `local`, Laravel accepte certaines choses que nous voulons bloquer en production. Donc pour le vrai public, le resultat attendu doit etre:

```json
"environment": "production"
```

Dans le fichier `.env` du serveur, dans le meme dossier que `artisan`, mets exactement:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://itga.kekottech.com
```

Ensuite, dans SSH Hostinger:

```bash
cd /home/<ton_user_hostinger>/domains/<ton_domaine>/public_html
php artisan optimize:clear
php artisan config:clear
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan ops:public-readiness --json
```

Le resultat attendu est:

```json
{
  "ok": true,
  "environment": "production"
}
```

Si cela affiche encore `local`, il y a seulement quelques causes probables:

1. Tu as modifie le mauvais fichier `.env`.
2. Tu n'es pas dans le bon dossier Laravel.
3. Laravel utilise encore un cache de config ancien.
4. Hostinger n'a pas les permissions pour ecrire dans `bootstrap/cache`.

Verifie le dossier courant:

```bash
pwd
ls
```

Tu dois voir:

```text
artisan
composer.json
app
bootstrap
config
database
public
routes
storage
```

Verifie la valeur lue par Artisan:

```bash
php artisan env
```

Verifie le `.env` visible dans ce dossier:

```bash
grep -E "^(APP_ENV|APP_DEBUG|APP_URL|API_SECRET_KEY|ADMIN_API_SECRET_KEY|READINESS_TOKEN|CACHE_DRIVER|SESSION_DRIVER|QUEUE_CONNECTION|FILESYSTEM_DRIVER)=" .env
```

Si `php artisan env` dit encore `local` alors que `.env` contient `APP_ENV=production`, supprime le cache config puis recree-le:

```bash
rm -f bootstrap/cache/config.php
php artisan config:clear
php artisan config:cache
php artisan env
php artisan ops:public-readiness --json
```

Pour Firebase, si ton `.env` contient:

```env
GOOGLE_APPLICATION_CREDENTIALS=itga-firebase-prod.json
GOOGLE_CREDENTIALS_PATH=itga-firebase-prod.json
```

alors le fichier reel doit exister dans le dossier Laravel, au meme niveau que `artisan`:

```bash
ls -l itga-firebase-prod.json
```

Si ce fichier n'existe pas, les notifications ou services Firebase peuvent echouer meme si le reste du backend fonctionne.

### 4.8 Connecter Hostinger a GitHub plus tard

Hostinger permet le deploiement Git depuis hPanel, mais le dossier d'installation doit etre vide au moment de creer le repository. Donc ne le fais pas brutalement sur le dossier actuel en production.

Procedure propre:

1. Creer un sous-domaine de staging, par exemple:

```text
staging-api.itga.kekottech.com
```

2. Creer un dossier vide pour ce staging.
3. Dans hPanel, aller dans Git.
4. Ajouter le repository:

```text
https://github.com/Djouko/itga-backend.git
```

5. Branche:

```text
main
```

6. Install path: dossier staging vide.
7. Deployer.
8. Ajouter `.env` staging.
9. Tester.
10. Quand tout est bon, faire une bascule propre vers production.

## 5. Web sur DigitalOcean avec EasyPanel

Le web est dans le repo:

```text
https://github.com/Djouko/itga-web.git
```

EasyPanel sait deployer depuis GitHub et construire une image Docker. Les docs officielles EasyPanel indiquent que si le repo contient un `Dockerfile`, EasyPanel l'utilise; sinon il tente les buildpacks. Ici on utilise le Dockerfile du projet.

### 5.1 Creer le projet EasyPanel

1. Ouvre EasyPanel.
2. Clique `New`.
3. Cree un projet:

```text
itga
```

4. Dans le projet, clique `+ Service`.
5. Choisis `App`.
6. Nom du service:

```text
itga-web
```

### 5.2 Brancher GitHub

1. Source: `Github repository`.
2. Repository:

```text
Djouko/itga-web
```

3. Branch:

```text
main
```

4. Build method:

```text
Dockerfile
```

5. Dockerfile path:

```text
Dockerfile
```

6. Build context:

```text
.
```

### 5.3 Variables EasyPanel

Dans l'onglet Environment:

```env
NEXT_PUBLIC_API_URL=https://itga.kekottech.com/api
NEXT_PUBLIC_API_KEY=<copier API_SECRET_KEY depuis secrets/ITGA_DEPLOYMENT_SECRETS.local.md>
NODE_ENV=production
```

Sauvegarde.

### 5.4 Domaine et port

Dans EasyPanel, section Domain / Proxy:

1. Ajoute le domaine web, par exemple:

```text
itga.kekottech.com
```

ou

```text
app.itga.kekottech.com
```

2. Proxy port:

```text
3000
```

3. Active HTTPS/Let's Encrypt.

### 5.5 DNS DigitalOcean

Chez ton fournisseur DNS:

Si tu utilises un sous-domaine:

```text
Type: A
Name: app
Value: <IP du serveur DigitalOcean>
TTL: 300
```

Si tu utilises le domaine principal:

```text
Type: A
Name: @
Value: <IP du serveur DigitalOcean>
TTL: 300
```

Attends la propagation DNS.

### 5.6 Deploy web

Dans EasyPanel:

1. Clique `Deploy`.
2. Ouvre les logs.
3. Attends que le build finisse.
4. Va sur le domaine public.
5. Connecte-toi.
6. Verifie:
   - feed
   - suggestion de rooms
   - profil
   - login
   - appels API

Si le web affiche une erreur API:

1. Verifie `NEXT_PUBLIC_API_URL`.
2. Verifie `NEXT_PUBLIC_API_KEY`.
3. Redeploie, car ces variables sont integrees au build.
4. Ouvre DevTools > Network et verifie l'URL appelee.

## 6. Android

### 6.1 Artefacts generes

APK pour partage direct/test:

```text
chatter_flutter/chatter/build/app/outputs/flutter-apk/app-release.apk
```

AAB pour Google Play:

```text
chatter_flutter/chatter/build/app/outputs/bundle/release/app-release.aab
```

Recommandation:

- Pour Google Play Store: utilise le `.aab`.
- Pour envoyer rapidement a un testeur hors Play Store: utilise le `.apk`.

### 6.2 Verifier que l'APK est signe

Commande locale deja executee:

```powershell
apksigner verify --verbose chatter_flutter\chatter\build\app\outputs\flutter-apk\app-release.apk
```

Resultat attendu:

```text
Verifies
Verified using v2 scheme (APK Signature Scheme v2): true
```

### 6.3 Publier en test interne Google Play

1. Ouvre Google Play Console.
2. Cree l'application si elle n'existe pas.
3. Package name attendu:

```text
com.retrytech.chatter
```

4. Va dans `Testing`.
5. Ouvre `Internal testing`.
6. Cree une liste de testeurs.
7. Ajoute les emails Gmail des testeurs.
8. Cree une nouvelle release.
9. Uploade:

```text
chatter_flutter/chatter/build/app/outputs/bundle/release/app-release.aab
```

10. Remplis les notes de version.
11. Sauvegarde.
12. Envoie en review interne.
13. Quand Google valide, partage le lien avec les testeurs.

Important: Google Play prefere le format Android App Bundle pour les nouvelles apps.

### 6.4 Partage APK direct si urgence

Si on doit tester avant Google Play:

1. Envoie uniquement:

```text
chatter_flutter/chatter/build/app/outputs/flutter-apk/app-release.apk
```

2. Le testeur devra autoriser l'installation depuis une source externe.
3. Ce mode est utile pour debug, mais il est moins propre que Google Play.

### 6.5 Regenerer un build Android plus tard

Depuis la racine locale:

```powershell
$apiKey = "<copier API_SECRET_KEY depuis secrets/ITGA_DEPLOYMENT_SECRETS.local.md>"
cd chatter_flutter\chatter
flutter clean
flutter pub get
flutter build appbundle --release --dart-define=ITGA_API_KEY=$apiKey
flutter build apk --release --dart-define=ITGA_API_KEY=$apiKey
```

Les sorties seront:

```text
build/app/outputs/bundle/release/app-release.aab
build/app/outputs/flutter-apk/app-release.apk
```

## 7. iOS avec Codemagic

Point tres important: depuis Windows, on ne peut pas valider totalement un build iOS signe. La bonne solution est Codemagic, qui utilise macOS dans le cloud.

Le repo mobile est:

```text
https://github.com/Djouko/itga-mobile.git
```

Le fichier Codemagic est:

```text
chatter_flutter/chatter/codemagic.yaml
```

Il contient deux workflows:

```text
ios-test-no-codesign
ios-testflight-signed
```

### 7.1 Ce que fait chaque workflow

`ios-test-no-codesign`:

- restaure les fichiers Firebase depuis les variables Codemagic
- installe Flutter dependencies
- lance `flutter analyze`
- lance `flutter test`
- installe les pods iOS
- fait un build iOS release sans signature

Utilise ce workflow en premier.

`ios-testflight-signed`:

- fait les memes validations
- configure la signature iOS via Codemagic
- genere un `.ipa` signe pour TestFlight

Utilise ce workflow seulement apres que `ios-test-no-codesign` passe.

### 7.2 Creer le compte Codemagic

1. Va sur Codemagic.
2. Connecte-toi avec GitHub.
3. Autorise Codemagic a acceder au repository:

```text
Djouko/itga-mobile
```

4. Ajoute l'application dans Codemagic.
5. Choisis configuration:

```text
codemagic.yaml
```

6. Verifie que le working directory correspond a la racine du repo mobile. Comme le repo GitHub `itga-mobile` pointe deja sur le dossier Flutter, le fichier `codemagic.yaml` est a la racine du repo.

### 7.3 Creer les variables Codemagic

Dans Codemagic:

1. Ouvre l'app ITGA.
2. Va dans `App settings`.
3. Ouvre `Environment variables`.
4. Ajoute les variables ci-dessous.
5. Pour chaque variable secrete, coche `Secret`.

Variable 1:

```text
Name: ITGA_API_KEY
Value: copier API_SECRET_KEY depuis secrets/ITGA_DEPLOYMENT_SECRETS.local.md
Secret: yes
```

Variable 2:

```text
Name: FIREBASE_IOS_PLIST_B64
Value: resultat de la commande PowerShell de la section 7.4
Secret: yes
```

Variable 3:

```text
Name: FIREBASE_ANDROID_JSON_B64
Value: resultat de la commande PowerShell de la section 7.5
Secret: yes
```

### 7.4 Generer FIREBASE_IOS_PLIST_B64

Depuis la racine locale du workspace, lance:

```powershell
[Convert]::ToBase64String([IO.File]::ReadAllBytes("chatter_flutter\chatter\ios\Runner\GoogleService-Info.plist")) | Set-Clipboard
```

Cela copie la valeur dans le presse-papier.

Dans Codemagic:

1. Cree `FIREBASE_IOS_PLIST_B64`.
2. Colle la valeur.
3. Coche `Secret`.
4. Sauvegarde.

Si PowerShell dit que le fichier n'existe pas, verifie que Firebase iOS est bien present:

```powershell
Test-Path chatter_flutter\chatter\ios\Runner\GoogleService-Info.plist
```

### 7.5 Generer FIREBASE_ANDROID_JSON_B64

Depuis la racine locale du workspace, lance:

```powershell
[Convert]::ToBase64String([IO.File]::ReadAllBytes("chatter_flutter\chatter\android\app\google-services.json")) | Set-Clipboard
```

Dans Codemagic:

1. Cree `FIREBASE_ANDROID_JSON_B64`.
2. Colle la valeur.
3. Coche `Secret`.
4. Sauvegarde.

### 7.6 Premier test iOS sans signature

Dans Codemagic:

1. Ouvre l'app ITGA.
2. Clique `Start new build`.
3. Branche:

```text
main
```

4. Workflow:

```text
ios-test-no-codesign
```

5. Lance le build.
6. Attends la fin.

Resultat attendu:

```text
Build successful
```

Si cela echoue sur `GoogleService-Info.plist`:

- La variable `FIREBASE_IOS_PLIST_B64` est absente ou mal copiee.

Si cela echoue sur `ITGA_API_KEY`:

- La variable `ITGA_API_KEY` est absente ou non sauvegardee.

Si cela echoue sur CocoaPods:

- Relance une fois.
- Si ca continue, ouvre le log `pod install` et corrige la dependance indiquee.

### 7.7 Configurer Apple Developer pour TestFlight

Tu as besoin:

- un compte Apple Developer actif
- un Bundle ID:

```text
com.retrytech.chatter
```

- une app creee dans App Store Connect
- l'acces Codemagic a App Store Connect

Dans App Store Connect:

1. Ouvre `Certificates, Identifiers & Profiles`.
2. Verifie que le Bundle ID existe:

```text
com.retrytech.chatter
```

3. Ouvre App Store Connect.
4. Cree l'application ITGA si elle n'existe pas.
5. Note le Team ID Apple.

Dans Codemagic:

1. Va dans `Teams` ou `Integrations`.
2. Connecte Apple Developer Portal / App Store Connect.
3. Autorise Codemagic a gerer certificats et profils.
4. Dans l'app ITGA, active automatic code signing si disponible.

Le fichier `codemagic.yaml` utilise:

```yaml
ios_signing:
  distribution_type: app_store
  bundle_identifier: com.retrytech.chatter
```

### 7.8 Build iOS signe pour TestFlight

Seulement apres succes de `ios-test-no-codesign`:

1. Dans Codemagic, clique `Start new build`.
2. Branche:

```text
main
```

3. Workflow:

```text
ios-testflight-signed
```

4. Lance.
5. Si le build reussit, tu obtiens un `.ipa`.
6. Envoie l'app vers TestFlight depuis Codemagic si l'integration App Store Connect est active.

Si signature echoue:

1. Verifie que le Bundle ID Apple est exactement:

```text
com.retrytech.chatter
```

2. Verifie le Team ID.
3. Verifie que Codemagic a acces a App Store Connect.
4. Verifie que le certificat Apple Distribution est valide.

### 7.9 TestFlight

Dans App Store Connect:

1. Ouvre l'app ITGA.
2. Va dans `TestFlight`.
3. Attends que le build apparaisse.
4. Ajoute des testeurs internes.
5. Lance le test.
6. Installe l'app depuis l'application TestFlight sur iPhone.
7. Verifie:
   - ouverture de l'app
   - login
   - feed
   - suggestions de rooms
   - navigation profil
   - notifications si configurees
   - upload media

## 8. Verification fonctionnelle minimale avant public

Fais ces tests avec un compte utilisateur normal et un compte entreprise.

### 8.1 Backend

1. Login.
2. Refresh token/session.
3. Recuperer feed.
4. Recuperer suggestions de rooms.
5. Creer une publication.
6. Commenter.
7. Reagir.
8. Ouvrir profil.
9. Modifier profil.
10. Tester upload image.
11. Tester logout.

### 8.2 Web

1. Ouvrir la page login.
2. Se connecter.
3. Voir feed.
4. Verifier que les suggestions de rooms existent dans le feed.
5. Cliquer une room suggeree.
6. Verifier les etats vide/loading/erreur.
7. Se deconnecter.

### 8.3 Android

1. Installer l'APK ou l'app Play Internal Testing.
2. Ouvrir l'app.
3. Se connecter.
4. Verifier feed.
5. Verifier suggestion des rooms.
6. Changer reseau Wi-Fi/4G.
7. Tester avec mauvaise connexion.
8. Tester retour arriere Android.
9. Tester upload image.
10. Tester notifications si activees.

### 8.4 iOS

1. Installer via TestFlight.
2. Ouvrir l'app.
3. Se connecter.
4. Verifier feed.
5. Verifier suggestion des rooms.
6. Tester retour navigation iOS.
7. Tester permissions photo/camera.
8. Tester notifications si activees.
9. Tester affichage sur petit iPhone et grand iPhone.

## 9. Scalabilite immediate

Objectif court terme: supporter proprement des milliers d'utilisateurs et eviter les crashs simples.

Ce qui est deja en place ou prepare:

- cache backend non-file via database
- sessions non-file via database
- queue non-sync via database
- migrations pour cache/session/jobs
- readiness command protegee par token
- build web production
- builds mobile release

Ce qu'il faut absolument faire cote serveur:

1. Activer HTTPS partout.
2. Mettre Cloudflare devant les domaines si possible.
3. Activer caching statique pour images, CSS, JS.
4. Limiter la taille des uploads.
5. Verifier que `APP_DEBUG=false`.
6. Verifier que `LOG_LEVEL=error`.
7. Faire tourner la queue.
8. Configurer backup base de donnees quotidien.
9. Configurer backup fichiers uploades quotidien.
10. Surveiller les erreurs 500.

### 9.1 Pourquoi Hostinger ne suffit pas pour "millions de requetes par seconde"

Hostinger mutualise ou semi-mutualise peut lancer ITGA, mais ce n'est pas une architecture type LinkedIn.

Pour des millions de requetes par seconde, il faudra:

- plusieurs serveurs applicatifs
- load balancer
- Redis dedie
- base de donnees managée ou clusterisee
- replicas lecture
- stockage objet S3 compatible
- CDN mondial
- workers queue separes
- monitoring temps reel
- autoscaling
- tests de charge progressifs

Donc le plan realiste:

Phase 1 - Maintenant:

- Hostinger backend
- EasyPanel web sur DigitalOcean
- Android internal testing ou APK
- iOS TestFlight
- Cloudflare
- backups
- monitoring manuel

Phase 2 - Premiere traction:

- deplacer backend vers VPS/DigitalOcean ou Laravel Forge-like
- ajouter Redis
- ajouter S3 compatible
- ajouter workers separes
- monitoring Sentry/UptimeRobot
- logs centralises

Phase 3 - Croissance forte:

- load balancer
- plusieurs instances backend
- DB managed avec replicas
- CDN media
- queue workers autoscale
- cache feed
- recherche dediee
- tests de charge automatises

## 10. Monitoring minimum

Avant public:

1. Cree un compte UptimeRobot ou equivalent.
2. Ajoute un monitor HTTP:

```text
https://itga.kekottech.com/api/health
```

3. Intervalle:

```text
5 minutes
```

4. Ajoute ton email et WhatsApp/Telegram si disponible.

5. Sur backend, verifie regulierement:

```bash
tail -n 100 storage/logs/laravel.log
```

6. Dans EasyPanel, ouvre les logs du service web.

7. Dans Google Play Console, lis:
   - Android vitals
   - Pre-launch report
   - crashs

8. Dans App Store Connect, lis:
   - TestFlight feedback
   - crashs

## 11. Checklist finale avant rendre public

Ne pas rendre public tant qu'un item critique est rouge.

### Backend

- [ ] Backup fichiers Hostinger fait.
- [ ] Backup SQL fait.
- [ ] `.env` production mis a jour.
- [ ] `APP_DEBUG=false`.
- [ ] `APP_ENV=production`.
- [ ] `API_SECRET_KEY` configure.
- [ ] `ADMIN_API_SECRET_KEY` configure.
- [ ] `READINESS_TOKEN` configure.
- [ ] `CACHE_DRIVER=database`.
- [ ] `SESSION_DRIVER=database`.
- [ ] `QUEUE_CONNECTION=database`.
- [ ] `composer install --no-dev --optimize-autoloader` passe.
- [ ] `php artisan migrate --force` passe.
- [ ] `php artisan ops:public-readiness --json` retourne `ok: true`.
- [ ] endpoint health OK.
- [ ] queue worker ou cron queue configure.
- [ ] scheduler configure.

### Web

- [ ] EasyPanel connecte au repo `Djouko/itga-web`.
- [ ] Dockerfile utilise.
- [ ] `NEXT_PUBLIC_API_URL` configure.
- [ ] `NEXT_PUBLIC_API_KEY` configure.
- [ ] Proxy port `3000`.
- [ ] HTTPS actif.
- [ ] Login web OK.
- [ ] Feed web OK.
- [ ] Suggestions de rooms web OK.

### Android

- [ ] Keystore sauvegarde.
- [ ] Mots de passe keystore sauvegardes.
- [ ] AAB uploade en internal testing.
- [ ] APK installe sur au moins un telephone test.
- [ ] Login Android OK.
- [ ] Feed Android OK.
- [ ] Suggestions de rooms Android OK.
- [ ] Upload image Android OK.

### iOS

- [ ] Codemagic connecte au repo `Djouko/itga-mobile`.
- [ ] `ITGA_API_KEY` ajoute et marque Secret.
- [ ] `FIREBASE_IOS_PLIST_B64` ajoute et marque Secret.
- [ ] `FIREBASE_ANDROID_JSON_B64` ajoute et marque Secret.
- [ ] Workflow `ios-test-no-codesign` passe.
- [ ] Apple Developer configure.
- [ ] Bundle ID `com.retrytech.chatter` cree.
- [ ] Workflow `ios-testflight-signed` passe.
- [ ] App installee via TestFlight.
- [ ] Login iOS OK.
- [ ] Feed iOS OK.
- [ ] Suggestions de rooms iOS OK.

## 12. Si quelque chose casse

### 12.1 Erreur 500 backend

SSH Hostinger:

```bash
cd /home/<ton_user_hostinger>/domains/<ton_domaine>/public_html
php artisan optimize:clear
php artisan config:cache
tail -n 100 storage/logs/laravel.log
```

Cherche:

- erreur `.env`
- table manquante
- permission storage
- fichier Firebase absent
- mauvais `APP_KEY`

### 12.2 Web ne parle pas au backend

Dans navigateur:

1. F12.
2. Onglet Network.
3. Recharge.
4. Regarde l'appel API en rouge.

Si URL mauvaise:

- corrige `NEXT_PUBLIC_API_URL`
- redeploie EasyPanel

Si 401/403:

- corrige `NEXT_PUBLIC_API_KEY`
- redeploie EasyPanel
- verifie `API_SECRET_KEY` backend

### 12.3 Android/iOS ne parle pas au backend

Cause probable:

- `ITGA_API_KEY` du build mobile ne correspond pas a `API_SECRET_KEY` backend.

Solution:

1. Copie `API_SECRET_KEY` depuis le fichier secrets.
2. Rebuild Android ou relance Codemagic iOS.
3. Redepose l'app aux testeurs.

### 12.4 Feed ou suggestions rooms ne s'affichent pas

Verifier:

1. Backend: endpoint feed repond.
2. Backend: rooms certifiees existent.
3. User ou entreprise a des interests compatibles.
4. Web/mobile envoient bien la cle API.
5. Logs backend ne montrent pas erreur SQL.
6. Cache nettoye si besoin:

```bash
php artisan optimize:clear
php artisan cache:clear
php artisan config:cache
```

## 13. Sources officielles verifiees

Ces sources ont ete consultees pour eviter de deviner les procedures externes:

- Hostinger Git deployment: https://support.hostinger.com/en/articles/1583302-how-to-deploy-a-git-repository-in-hostinger
- Hostinger Laravel deployment: https://support.hostinger.com/en/articles/6152127-how-to-deploy-laravel-8-at-hostinger
- Hostinger Composer: https://support.hostinger.com/en/articles/5792078-how-to-use-composer
- EasyPanel App Service: https://easypanel.io/docs/services/app
- EasyPanel Next.js: https://easypanel.io/docs/quickstarts/nextjs
- Codemagic environment variables: https://docs.codemagic.io/flutter-configuration/env-variables/
- Codemagic iOS signing: https://docs.codemagic.io/yaml-code-signing/signing-ios/
- Flutter Android release: https://docs.flutter.dev/deployment/android
- Flutter iOS release: https://docs.flutter.dev/deployment/ios
- Google Play internal testing: https://support.google.com/googleplay/android-developer/answer/9845334

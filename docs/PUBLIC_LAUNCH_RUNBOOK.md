# ITGA - Runbook public launch complet

Date de preparation: 2026-05-16

Ce document est volontairement tres detaille. Il part du principe que tu veux lancer ITGA pour de vrais utilisateurs, pas faire une demo. Il explique quoi faire depuis le code local jusqu'au backend Hostinger, web EasyPanel/DigitalOcean, Android Play Store/APK, iOS Codemagic/TestFlight, puis les tests de charge.

Important: ne saute pas les etapes. Si une etape dit "STOP", on corrige avant de continuer.

---

## 0. Verdict honnete avant de commencer

### 0.1. Ce qui est deja pret cote code

- Backend Laravel: les tests Unit et Feature ont deja ete executes localement et passent.
- Web Next.js: le build production a deja ete execute localement et passe.
- Feed/rooms: les suggestions de rooms sont corrigees cote backend, mobile et web.
- iOS: les fichiers XML critiques `Info.plist` et `Runner.entitlements` sont valides.
- iOS: `NSAllowsArbitraryLoads=false`, donc iOS n'accepte plus le HTTP non securise par defaut.
- iOS: `aps-environment=production`, necessaire pour TestFlight/App Store.
- Android: `android:usesCleartextTraffic=false` dans le manifeste principal.
- Docker web: un `chatter_web/Dockerfile` existe pour EasyPanel.
- Codemagic: un `codemagic.yaml` existe avec deux workflows iOS.
- Preflight public: `scripts/public-launch-preflight.ps1` existe.
- Preflight iOS/mobile: `scripts/ios-public-preflight.ps1` existe.
- Test de charge: `scripts/load-test-k6.js` existe.

### 0.2. Ce qui n'est pas encore un feu vert public complet

Feu vert public total: NON tant que les points suivants ne sont pas termines.

1. Les IDs AdMob de test sont encore presents:
   - iOS: `ca-app-pub-3940256099942544~1458002511`
   - Android: `ca-app-pub-3940256099942544~3347511713`

   Pour une sortie publique, il faut soit remplacer ces IDs par les vrais App IDs AdMob, soit desactiver completement les publicites. Le preflight public echoue volontairement tant que ces IDs de test restent en place.

2. Le build iOS signe n'a pas ete execute localement, car Windows ne peut pas produire une IPA iOS signee. Il faut faire passer Codemagic.

3. Le build Docker web n'a pas pu etre verifie localement si Docker Desktop n'est pas lance. EasyPanel construira le Dockerfile, mais il faut surveiller le premier build.

4. "Des milliers de requetes par seconde" ne peut pas etre promis avec un simple deploiement mutualise Hostinger. Le code est mieux protege, mais la capacite reelle depend de l'infrastructure: PHP-FPM, base de donnees, Redis, cache, CDN, queues, stockage media et load tests.

### 0.3. Feu vert minimum acceptable

On peut ouvrir a un public controle seulement quand:

- Le script public complet passe sans exception dangereuse.
- Codemagic `ios-test-no-codesign` passe.
- Codemagic `ios-testflight-signed` passe.
- Android AAB est genere avec signature release.
- Web EasyPanel est en HTTPS et connecte au bon backend.
- Backend Hostinger repond a `/api/health` et `/api/readiness`.
- Les tests de charge de base passent sur l'infrastructure reelle.
- Les IDs AdMob de test sont remplaces ou les pubs sont desactivees.

---

## 1. Ce qui a ete corrige pour le feed et les rooms

### 1.1. Nouvelle regle produit

Une room peut etre suggeree dans le feed seulement si:

- la room est publique;
- le compte connecte n'est pas deja membre de la room;
- le createur de la room n'est pas bloque;
- la room est creee par un utilisateur verifie ou une entreprise verifiee;
- au moins un interet de la room correspond aux interets de l'utilisateur connecte ou de l'acteur entreprise;
- aucun fallback aleatoire ne remplit le feed avec des rooms non pertinentes.

### 1.2. Impact par plateforme

Backend:

- La logique est centralisee dans `chatter_backend/app/Services/RoomSuggestionService.php`.
- `PostController::fetchPosts` renvoie les suggestions de rooms quand le feed les demande.
- `RoomController::fetchSuggestedRooms` utilise la meme logique, donc mobile/web/API restent coherents.

Mobile Flutter:

- La section "Suggested rooms" n'attend plus le troisieme post.
- Elle peut apparaitre meme si le feed contient 0, 1 ou 2 posts.
- Un refresh vide les vieilles suggestions et recharge proprement.

Web:

- Le feed web affiche maintenant les rooms suggerees.
- Un clic ouvre la room via `/rooms?openRoom=ID`.

---

## 2. Les fichiers importants ajoutes ou modifies

Backend:

- `chatter_backend/app/Services/RoomSuggestionService.php`
- `chatter_backend/app/Http/Controllers/PostController.php`
- `chatter_backend/app/Http/Controllers/RoomController.php`
- `chatter_backend/app/Http/Kernel.php`
- `chatter_backend/tests/Unit/RoomSuggestionServiceTest.php`

Mobile:

- `chatter_flutter/chatter/lib/screens/feed_screen/feed_screen.dart`
- `chatter_flutter/chatter/lib/screens/feed_screen/feed_screen_controller.dart`
- `chatter_flutter/chatter/ios/Runner/Info.plist`
- `chatter_flutter/chatter/ios/Runner/Runner.entitlements`
- `chatter_flutter/chatter/android/app/src/main/AndroidManifest.xml`

Web:

- `chatter_web/Dockerfile`
- `chatter_web/.dockerignore`
- `chatter_web/next.config.ts`
- `chatter_web/src/app/(app)/feed/page.tsx`
- `chatter_web/src/app/(app)/rooms/page.tsx`
- `chatter_web/src/features/posts/services/post-service.ts`
- `chatter_web/src/features/rooms/types/index.ts`

Scripts et documentation:

- `codemagic.yaml`
- `scripts/public-launch-preflight.ps1`
- `scripts/ios-public-preflight.ps1`
- `scripts/load-test-k6.js`
- `docs/PUBLIC_LAUNCH_RUNBOOK.md`

---

## 3. Preparations obligatoires avant le commit

Ouvre PowerShell a la racine du repo:

```powershell
cd "F:\Workspace\Freelance\IT Girls\Code\chatter\19 decembre\Chatter 19 December 2025\ITGA"
```

Verifie que tu es dans le bon dossier:

```powershell
Get-Location
```

Le chemin affiche doit finir par:

```text
ITGA
```

### 3.1. Etat GitHub deja effectue le 2026-05-16

J'ai prepare et pousse les 3 projets separes vers GitHub.

Repos en ligne verifies:

```text
Backend + admin:
https://github.com/Djouko/itga-backend.git
Branche: main
Commit verifie: 88a2eaf98c98f5c9820bde981fb0558b3afcae91

Web:
https://github.com/Djouko/itga-web.git
Branche: main
Commit verifie: 744eb249d51c4973b9ddd022af7db4f57cadc304

Mobile Android/iOS:
https://github.com/Djouko/itga-mobile.git
Branche: main
Commit verifie: 73ecc734ef482949a258f29f4b65354d777dc1a6
```

Controle realise avant push:

- `.env` reel non publie;
- fichiers Firebase reels non publies;
- `android/local.properties` non publie;
- keystores/certificats non publies;
- logs web non publies;
- backups Xcode non publies;
- backup MySQL local non publie.

Important: chaque dossier a maintenant son propre repo Git local:

```text
chatter_backend           -> itga-backend
chatter_web               -> itga-web
chatter_flutter\chatter   -> itga-mobile
```

Pour verifier toi-meme:

```powershell
git -C chatter_backend status --short
git -C chatter_web status --short
git -C chatter_flutter\chatter status --short
```

Si tout est propre, ces commandes n'affichent rien.

Regarde les fichiers modifies:

```powershell
git status --short
```

Controle les erreurs d'espaces et fins de lignes:

```powershell
git diff --check
```

Si cette commande affiche une erreur, STOP. Il faut corriger avant commit.

---

## 4. Preflight local avant public launch

### 4.1. Preflight iOS/mobile strict

Commande stricte pour vrai public:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\ios-public-preflight.ps1
```

Resultat attendu pour un vrai feu vert:

```text
Public iOS/mobile preflight passed.
```

Etat actuel probable:

- La commande va echouer a cause des IDs AdMob de test.
- C'est normal et voulu.
- Tant que ca echoue, on ne donne pas de feu vert public complet.

### 4.2. Preflight iOS/mobile pour verifier le reste avant remplacement AdMob

Si tu veux verifier tout le reste sans bloquer sur les IDs AdMob de test:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\ios-public-preflight.ps1 -AllowTestAdMob
```

Cette commande est acceptable pour une verification technique interne, mais pas pour dire "pret public".

### 4.3. Preflight complet rapide

Pour une verification rapide:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\public-launch-preflight.ps1 -Fast -AllowTestAdMob
```

Ce que ca fait:

- readiness backend;
- tests unitaires backend;
- syntaxe JS admin;
- TypeScript web;
- lint config web;
- preflight iOS/mobile;
- tests Flutter.

### 4.4. Preflight complet strict avant vraie mise en public

Quand les IDs AdMob sont remplaces ou les pubs desactivees:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\public-launch-preflight.ps1
```

Resultat attendu:

```text
PUBLIC PREFLIGHT PASSED
```

Si ce n'est pas le resultat, STOP.

### 4.5. Si Flutter analyse prend trop de temps sur Windows

Commande ciblee pour le feed:

```powershell
cd chatter_flutter\chatter
flutter analyze lib\screens\feed_screen\feed_screen.dart lib\screens\feed_screen\feed_screen_controller.dart --no-pub --no-fatal-infos
```

Revenir ensuite a la racine:

```powershell
cd ..\..
```

---

## 5. Commit, push et tag

Important: ITGA est maintenant separe en 3 repos GitHub. Ne fais pas un seul `git push` global en croyant publier les 3 projets.

### 5.1. Backend + admin

```powershell
cd "F:\Workspace\Freelance\IT Girls\Code\chatter\19 decembre\Chatter 19 December 2025\ITGA\chatter_backend"
git status --short
```

Si `git status --short` n'affiche rien, le backend/admin local est deja synchronise avec GitHub.

Pour pousser une prochaine modification backend/admin:

```powershell
git add .
git commit -m "Message clair de la modification backend"
git push origin main
```

### 5.2. Web

```powershell
cd "F:\Workspace\Freelance\IT Girls\Code\chatter\19 decembre\Chatter 19 December 2025\ITGA\chatter_web"
git status --short
```

Si `git status --short` n'affiche rien, le web local est deja synchronise avec GitHub.

Pour pousser une prochaine modification web:

```powershell
git add .
git commit -m "Message clair de la modification web"
git push origin main
```

### 5.3. Mobile Android/iOS

```powershell
cd "F:\Workspace\Freelance\IT Girls\Code\chatter\19 decembre\Chatter 19 December 2025\ITGA\chatter_flutter\chatter"
git status --short
```

Si `git status --short` n'affiche rien, le mobile local est deja synchronise avec GitHub.

Pour pousser une prochaine modification mobile:

```powershell
git add .
git commit -m "Message clair de la modification mobile"
git push origin main
```

### 5.4. Tag de release recommande

Quand le backend Hostinger, le web EasyPanel, Android et iOS sont tous valides, creer un tag dans chaque repo:

```powershell
git -C chatter_backend tag public-launch-2026-05-16
git -C chatter_web tag public-launch-2026-05-16
git -C chatter_flutter\chatter tag public-launch-2026-05-16
```

Puis pousser les tags:

```powershell
git -C chatter_backend push origin public-launch-2026-05-16
git -C chatter_web push origin public-launch-2026-05-16
git -C chatter_flutter\chatter push origin public-launch-2026-05-16
```

Ne cree pas le tag avant validation finale de l'infrastructure reelle.

Apres le push, note:

- le hash du commit;
- le nom du tag;
- l'heure exacte du push.

Ces informations servent au rollback.

---

## 6. Backend et admin sur Hostinger

Le backend et l'admin sont ensemble: l'admin n'est pas un projet separe ici. Quand on deploye le backend, on deploye aussi l'admin Laravel/public assets.

### 6.1. Avant de toucher Hostinger

Preparer ces informations:

- URL backend/API actuelle;
- URL admin actuelle;
- acces SSH Hostinger;
- acces hPanel;
- acces phpMyAdmin;
- nom de la base de donnees;
- utilisateur de la base;
- mot de passe de la base;
- branche Git de production;
- ancien tag/commit stable;
- nouveau tag/commit a deployer.

### 6.2. Sauvegarde obligatoire

Dans Hostinger/hPanel:

1. Ouvre hPanel.
2. Va dans Databases.
3. Ouvre phpMyAdmin.
4. Selectionne la base de donnees ITGA.
5. Clique Export.
6. Choisis Quick si tu veux aller vite, Custom si tu veux tout controler.
7. Format: SQL.
8. Clique Export.
9. Garde le fichier `.sql` dans un dossier local nomme:

```text
backup-itga-before-public-launch-2026-05-16
```

Sauvegarde aussi les fichiers:

1. Ouvre File Manager Hostinger ou SFTP.
2. Telecharge le dossier backend actuellement deploye.
3. Garde-le avec le dump SQL.

STOP si tu n'as pas ces sauvegardes.

### 6.3. Variables `.env` backend production

Dans le `.env` du backend Hostinger, verifier au minimum:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://VOTRE-DOMAINE-BACKEND

API_SECRET_KEY=UNE_LONGUE_CLE_SECRETE_IDENTIQUE_WEB_MOBILE
ADMIN_API_SECRET_KEY=UNE_AUTRE_LONGUE_CLE_SECRETE_ADMIN
PUBLIC_READINESS_TOKEN=UN_TOKEN_PRIVE_POUR_READINESS

SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database
CACHE_DRIVER=file
LOG_CHANNEL=stack
```

Regle tres importante:

- `API_SECRET_KEY` va dans le backend, le web et le mobile.
- `ADMIN_API_SECRET_KEY` reste seulement cote backend/admin.
- `PUBLIC_READINESS_TOKEN` reste prive.
- `APP_DEBUG` doit etre `false`.

Si Hostinger propose Redis:

```env
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
```

Redis est fortement recommande pour encaisser plus de trafic, mais il doit etre configure correctement avant de changer ces valeurs.

### 6.4. Deploiement backend par SSH

Se connecter en SSH a Hostinger.

Aller dans le dossier backend:

```bash
cd /chemin/vers/chatter_backend
```

Mettre a jour le code:

```bash
git fetch --all --tags
git checkout main
git pull origin main
```

Installer les dependances production:

```bash
composer install --no-dev --optimize-autoloader
```

Appliquer les migrations:

```bash
php artisan migrate --force
```

Regenerer les caches Laravel:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Verifier le lien storage:

```bash
php artisan storage:link
```

Lancer la readiness:

```bash
php artisan ops:public-readiness --json
```

Resultat attendu:

```json
{
  "ready": true
}
```

Si `ready` vaut `false`, STOP.

### 6.5. Queue et cron backend

Si `QUEUE_CONNECTION=database`, les jobs doivent etre executes. Sans worker, certaines notifications/emails/actions lourdes peuvent rester bloquees.

Option Hostinger simple avec cron toutes les minutes:

```bash
* * * * * cd /chemin/vers/chatter_backend && php artisan schedule:run >> /dev/null 2>&1
```

Si Hostinger permet un cron pour vider la queue:

```bash
* * * * * cd /chemin/vers/chatter_backend && php artisan queue:work --stop-when-empty --tries=3 --timeout=90 >> storage/logs/queue-worker.log 2>&1
```

Option meilleure sur VPS:

- installer Supervisor;
- faire tourner `php artisan queue:work` en continu;
- surveiller memoire, CPU et erreurs.

### 6.6. Tests backend apres deploiement

Depuis ton ordinateur:

```powershell
curl https://VOTRE-DOMAINE-BACKEND/api/health
```

Puis:

```powershell
curl -H "x-readiness-token: VOTRE_PUBLIC_READINESS_TOKEN" https://VOTRE-DOMAINE-BACKEND/api/readiness
```

Si `curl` n'est pas pratique sur Windows, ouvre simplement:

```text
https://VOTRE-DOMAINE-BACKEND/api/health
```

Pour `/api/readiness`, il faut le header prive, donc utiliser curl/Postman/Insomnia.

### 6.7. Tests admin

Ouvrir l'URL admin actuelle.

Tester:

1. Connexion admin.
2. Dashboard charge.
3. Liste utilisateurs charge.
4. Liste entreprises charge.
5. Moderation charge.
6. Verification entreprise/utilisateur.
7. Blocage utilisateur test.
8. Deblocage utilisateur test.
9. Suppression d'un contenu test.
10. Deconnexion admin.

STOP si l'admin affiche `APP_DEBUG` ou des erreurs PHP brutes.

---

## 7. Web Next.js sur DigitalOcean avec EasyPanel

### 7.1. Ce qui existe deja

Un Dockerfile existe:

```text
chatter_web/Dockerfile
```

Next.js est configure avec:

```ts
output: "standalone"
```

Le Dockerfile lance:

```bash
node server.js
```

Port interne:

```text
3000
```

### 7.2. Avant EasyPanel

Preparer:

- URL du repo Git;
- branche a deployer;
- domaine web public;
- URL backend API;
- valeur `API_SECRET_KEY`;
- acces DigitalOcean;
- acces EasyPanel;
- acces DNS du domaine.

### 7.3. Creer l'app EasyPanel

Dans EasyPanel:

1. Se connecter au dashboard EasyPanel.
2. Cliquer sur Project ou creer un projet `itga`.
3. Cliquer sur Create Service.
4. Choisir App.
5. Choisir Git Repository.
6. Connecter GitHub/Git provider si ce n'est pas deja fait.
7. Choisir le repo ITGA.
8. Branch: `main` ou la branche production.
9. Build type: Dockerfile.
10. Root directory / build context:

```text
chatter_web
```

11. Dockerfile path:

```text
Dockerfile
```

12. Internal port:

```text
3000
```

13. Health check path si EasyPanel le demande:

```text
/
```

### 7.4. Variables EasyPanel

Ajouter les variables avant le build:

```env
NEXT_PUBLIC_API_URL=https://VOTRE-DOMAINE-BACKEND/api
NEXT_PUBLIC_API_KEY=MEME_VALEUR_QUE_API_SECRET_KEY
```

Important: `NEXT_PUBLIC_*` est integre au moment du build Next.js. Si tu changes ces variables apres build, redeploie/rebuild.

### 7.5. Domaine et HTTPS

Dans EasyPanel:

1. Va dans Domains.
2. Ajoute le domaine web public.
3. Configure le DNS chez ton registrar:
   - soit un A record vers l'IP DigitalOcean;
   - soit un CNAME selon ce que EasyPanel indique.
4. Attends la propagation DNS.
5. Active HTTPS/SSL.

Verifier:

```text
https://VOTRE-DOMAINE-WEB
```

Le navigateur ne doit pas afficher d'alerte certificat.

### 7.6. Tests web apres deploiement

Sur le web public:

1. Ouvrir `/`.
2. Se connecter comme utilisatrice.
3. Aller sur `/feed`.
4. Verifier que les posts chargent.
5. Verifier que les suggestions de rooms s'affichent si les interets matchent.
6. Cliquer une room suggeree.
7. Verifier que `/rooms?openRoom=ID` ouvre le detail.
8. Creer un post test.
9. Commenter un post test.
10. Reagir a un post test.
11. Se connecter comme entreprise.
12. Verifier feed entreprise.
13. Verifier offres/jobs si activees.
14. Se deconnecter.

STOP si le feed web ne parle pas au bon backend.

### 7.7. Si le build EasyPanel echoue

Cas frequents:

- `npm ci` echoue: verifier `package-lock.json`.
- variable API manquante: ajouter `NEXT_PUBLIC_API_URL` et `NEXT_PUBLIC_API_KEY`.
- port incorrect: mettre `3000`.
- Dockerfile introuvable: verifier build context `chatter_web`.
- erreur memoire: augmenter la taille du droplet ou builder sur une machine plus grande.

---

## 8. Android

### 8.1. Difference entre APK, AAB et Play Store

- APK: pratique pour partager rapidement un fichier installable.
- AAB: format recommande pour Google Play Store.
- Pour un vrai lancement Play Store, utiliser AAB.

### 8.2. Verifications Android avant build

Depuis la racine:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\ios-public-preflight.ps1 -AllowTestAdMob
```

Pour vrai public, il faudra:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\ios-public-preflight.ps1
```

Si ca echoue sur AdMob, remplacer les IDs ou desactiver les pubs.

### 8.3. Remplacer l'ID AdMob Android

Fichier:

```text
chatter_flutter/chatter/android/app/src/main/AndroidManifest.xml
```

Chercher:

```xml
android:name="com.google.android.gms.ads.APPLICATION_ID"
android:value="ca-app-pub-3940256099942544~3347511713"
```

Remplacer la valeur par le vrai App ID Android AdMob.

Format attendu:

```text
ca-app-pub-XXXXXXXXXXXXXXXX~YYYYYYYYYY
```

Ne mets pas un Ad Unit ID ici. Il faut l'App ID.

### 8.4. Signature Android release

Si tu as deja une cle Play Store, ne la remplace pas.

Si c'est la premiere sortie:

1. Ouvre PowerShell.
2. Va dans le projet mobile:

```powershell
cd chatter_flutter\chatter
```

3. Cree une keystore:

```powershell
keytool -genkeypair -v -keystore android\app\itga-upload-keystore.jks -keyalg RSA -keysize 2048 -validity 10000 -alias itga-upload
```

4. Note le mot de passe dans un gestionnaire de mots de passe.
5. Ne perds jamais ce fichier `.jks`.

Creer ou verifier:

```text
chatter_flutter/chatter/android/key.properties
```

Contenu exemple:

```properties
storePassword=VOTRE_MOT_DE_PASSE
keyPassword=VOTRE_MOT_DE_PASSE
keyAlias=itga-upload
storeFile=app/itga-upload-keystore.jks
```

Ne commit jamais les mots de passe ni la keystore dans Git.

### 8.5. Tests Android local

Dans `chatter_flutter/chatter`:

```powershell
flutter clean
flutter pub get
flutter test --no-pub
flutter analyze --no-pub --no-fatal-infos
```

Si `flutter analyze` est trop long, au minimum:

```powershell
flutter analyze lib\screens\feed_screen\feed_screen.dart lib\screens\feed_screen\feed_screen_controller.dart --no-pub --no-fatal-infos
```

### 8.6. Build APK partageable

```powershell
flutter build apk --release --dart-define=ITGA_API_KEY=VOTRE_API_SECRET_KEY
```

APK genere:

```text
chatter_flutter/chatter/build/app/outputs/flutter-apk/app-release.apk
```

Utilise cette APK pour test interne seulement.

### 8.7. Build AAB Play Store

```powershell
flutter build appbundle --release --dart-define=ITGA_API_KEY=VOTRE_API_SECRET_KEY
```

Fichier genere:

```text
chatter_flutter/chatter/build/app/outputs/bundle/release/app-release.aab
```

### 8.8. Google Play Console

1. Ouvrir Google Play Console.
2. Creer l'application ITGA si elle n'existe pas.
3. Remplir:
   - nom;
   - langue;
   - app ou jeu;
   - gratuite ou payante;
   - declarations legales.
4. Aller dans Test and release.
5. Choisir Internal testing.
6. Creer une release.
7. Upload `app-release.aab`.
8. Remplir release notes.
9. Ajouter les testeurs internes.
10. Publier sur Internal testing.
11. Installer via le lien Play Store interne.
12. Tester connexion, feed, rooms, upload, notifications.

Passer en Production seulement apres Internal testing OK.

---

## 9. iOS avec Codemagic, etapes ultra detaillees

### 9.1. Ce qu'il faut comprendre

On ne peut pas valider une sortie iOS complete depuis Windows.

Il faut:

- un build iOS sur macOS;
- une signature Apple;
- un bundle ID Apple;
- un certificat/provisioning profile;
- une app creee dans App Store Connect;
- un upload TestFlight.

Codemagic sert a faire ca sans Mac local.

### 9.2. Ce qui est deja dans le repo

Fichier:

```text
codemagic.yaml
```

Workflow 1:

```text
ios-test-no-codesign
```

But:

- installer les dependances Flutter;
- analyser le projet;
- lancer les tests;
- installer les pods iOS;
- construire iOS sans signature.

Ce workflow prouve que le code compile cote iOS.

Workflow 2:

```text
ios-testflight-signed
```

But:

- faire la meme verification;
- appliquer la signature Apple;
- produire une IPA signee pour TestFlight/App Store.

Ce workflow prouve que l'app peut etre distribuee.

Important pour les repos GitHub separes:

- dans le monorepo local, un `codemagic.yaml` existe a la racine ITGA;
- dans le repo mobile separe `itga-mobile`, un autre `codemagic.yaml` existe a la racine du projet Flutter;
- le repo mobile n'envoie pas les fichiers Firebase reels dans GitHub;
- Codemagic doit les recreer avec des variables secretes base64.

### 9.3. Preflight local iOS avant Codemagic

Depuis la racine:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\ios-public-preflight.ps1 -AllowTestAdMob
```

Ce que le script verifie:

- `ios/Runner/Info.plist` existe;
- `ios/Runner/Runner.entitlements` existe;
- `ios/Podfile` existe;
- `ios/Runner.xcodeproj/project.pbxproj` existe;
- `GoogleService-Info.plist` existe;
- `google-services.json` existe;
- `codemagic.yaml` existe;
- `NSAllowsArbitraryLoads=false`;
- permissions camera/micro/photos/tracking presentes;
- `aps-environment=production`;
- Sign in with Apple present;
- iOS minimum 15.0;
- bundle id `com.retrytech.chatter`;
- workflows Codemagic presents;
- IDs AdMob de test detectes.

Si tu veux le vrai feu vert public:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\ios-public-preflight.ps1
```

Cette commande doit passer sans `-AllowTestAdMob`.

### 9.4. Apple Developer

Il faut un compte Apple Developer actif et paye.

Dans Apple Developer:

1. Ouvre:

```text
https://developer.apple.com/account
```

2. Va dans Certificates, Identifiers & Profiles.
3. Va dans Identifiers.
4. Clique le bouton plus.
5. Choisis App IDs.
6. Choisis App.
7. Description:

```text
ITGA
```

8. Bundle ID:

```text
com.retrytech.chatter
```

9. Active les capabilities necessaires:
   - Push Notifications;
   - Sign in with Apple;
   - Associated Domains si les liens universels sont utilises.
10. Clique Continue.
11. Clique Register.

STOP si le bundle ID existe deja avec un autre compte Apple.

### 9.5. App Store Connect

1. Ouvre:

```text
https://appstoreconnect.apple.com
```

2. Va dans My Apps.
3. Clique le bouton plus.
4. Choisis New App.
5. Platform: iOS.
6. Name:

```text
ITGA
```

7. Primary language: choisir la langue principale.
8. Bundle ID:

```text
com.retrytech.chatter
```

9. SKU:

```text
itga-ios-2026
```

10. User Access: full access ou selon ton equipe.
11. Clique Create.

### 9.6. Firebase iOS

Dans Firebase Console:

1. Ouvre le projet ITGA.
2. Va dans Project settings.
3. Section Your apps.
4. Ajoute une app iOS si elle n'existe pas.
5. Bundle ID:

```text
com.retrytech.chatter
```

6. Telecharge:

```text
GoogleService-Info.plist
```

7. Place le fichier ici:

```text
chatter_flutter/chatter/ios/Runner/GoogleService-Info.plist
```

Le fichier existe deja localement, mais il faut confirmer qu'il correspond au bon projet Firebase public.

### 9.7. Remplacer l'ID AdMob iOS

Fichier:

```text
chatter_flutter/chatter/ios/Runner/Info.plist
```

Chercher:

```xml
<key>GADApplicationIdentifier</key>
<string>ca-app-pub-3940256099942544~1458002511</string>
```

Remplacer par le vrai App ID iOS AdMob.

Format:

```text
ca-app-pub-XXXXXXXXXXXXXXXX~YYYYYYYYYY
```

Ne mets pas un Ad Unit ID a la place de l'App ID.

### 9.8. Creer le compte Codemagic

1. Ouvre:

```text
https://codemagic.io
```

2. Cree un compte ou connecte-toi.
3. Connecte GitHub/Git provider.
4. Clique Add application.
5. Choisis le repo ITGA.
6. Quand Codemagic demande le type de config, choisis YAML / `codemagic.yaml`.
7. Verifie que Codemagic detecte le fichier a la racine:

```text
codemagic.yaml
```

### 9.9. Ajouter les variables secretes dans Codemagic

Dans Codemagic:

1. Ouvre l'application ITGA.
2. Va dans App settings.
3. Va dans Environment variables.
4. Ajoute `ITGA_API_KEY`:

```env
ITGA_API_KEY=MEME_VALEUR_QUE_API_SECRET_KEY_BACKEND
```

5. Coche Secret/Secure si disponible.

Ajouter ensuite les fichiers Firebase en base64.

Depuis PowerShell, a la racine ITGA locale:

```powershell
[Convert]::ToBase64String([IO.File]::ReadAllBytes("chatter_flutter\chatter\ios\Runner\GoogleService-Info.plist")) | Set-Clipboard
```

Dans Codemagic, ajoute une variable:

```env
FIREBASE_IOS_PLIST_B64=COLLER_LA_VALEUR_DU_PRESSE_PAPIER
```

Coche Secret/Secure.

Ensuite, toujours depuis PowerShell:

```powershell
[Convert]::ToBase64String([IO.File]::ReadAllBytes("chatter_flutter\chatter\android\app\google-services.json")) | Set-Clipboard
```

Dans Codemagic, ajoute:

```env
FIREBASE_ANDROID_JSON_B64=COLLER_LA_VALEUR_DU_PRESSE_PAPIER
```

Coche Secret/Secure.

6. Sauvegarde.

Si une de ces variables manque, les workflows echouent volontairement.

Pourquoi on fait comme ca:

- les fichiers Firebase reels sont necessaires pour builder iOS/Android;
- mais on evite de les publier directement dans GitHub;
- Codemagic les recree temporairement pendant le build.

### 9.10. Lancer le premier workflow iOS sans signature

Dans Codemagic:

1. Ouvre l'app ITGA.
2. Clique Start new build.
3. Choisis workflow:

```text
ios-test-no-codesign
```

4. Choisis la branche:

```text
main
```

5. Clique Start build.

Pendant le build, surveille les etapes:

- Verify required API key;
- Install Flutter dependencies;
- Analyze Flutter project;
- Run Flutter tests;
- Install iOS pods;
- Build iOS app without signing.

Resultat attendu:

```text
Build successful
```

Si ce workflow echoue, STOP. Ne configure pas TestFlight tant que ce workflow ne passe pas.

### 9.11. Lire les erreurs Codemagic sans paniquer

Erreur:

```text
ITGA_API_KEY is required
```

Action:

- ajouter la variable `ITGA_API_KEY` dans Codemagic;
- relancer le build.

Erreur:

```text
FIREBASE_IOS_PLIST_B64 is required
```

Action:

- generer la valeur base64 de `GoogleService-Info.plist`;
- l'ajouter dans Codemagic comme variable secrete;
- relancer le build.

Erreur:

```text
FIREBASE_ANDROID_JSON_B64 is required
```

Action:

- generer la valeur base64 de `google-services.json`;
- l'ajouter dans Codemagic comme variable secrete;
- relancer le build.

Erreur:

```text
pod install failed
```

Action:

- lire les 50 dernieres lignes du log;
- verifier le Podfile;
- verifier que `flutter pub get` passe avant `pod install`.

Erreur:

```text
Info.plist error
```

Action:

- relancer localement `scripts\ios-public-preflight.ps1 -AllowTestAdMob`;
- corriger le plist.

Erreur:

```text
No matching provisioning profiles found
```

Action:

- c'est une erreur de signature, donc elle concerne plutot le workflow signe;
- verifier Apple Developer, bundle ID, certificates/profiles dans Codemagic.

### 9.12. Configurer la signature Apple dans Codemagic

Dans App Store Connect:

1. Va dans Users and Access.
2. Va dans Integrations.
3. Va dans App Store Connect API.
4. Clique plus pour creer une API key.
5. Name:

```text
Codemagic ITGA
```

6. Access:

```text
App Manager
```

7. Clique Generate.
8. Telecharge le fichier `.p8`.
9. Note:
   - Key ID;
   - Issuer ID;
   - fichier `.p8`.

Attention: le fichier `.p8` ne peut etre telecharge qu'une seule fois. Sauvegarde-le dans un endroit securise.

Dans Codemagic:

1. Ouvre Team settings.
2. Va dans Team integrations.
3. Ouvre Developer Portal ou Apple Developer Portal.
4. Clique Add key / Manage keys.
5. Nom de la key:

```text
ITGA_APP_STORE_CONNECT
```

6. Renseigne Issuer ID.
7. Renseigne Key ID.
8. Upload le fichier `.p8`.
9. Sauvegarde.

Ensuite, dans Codemagic:

1. Va dans codemagic.yaml settings.
2. Va dans Code signing identities.
3. iOS certificates.
4. Genere ou ajoute un Apple Distribution certificate.
5. iOS provisioning profiles.
6. Fetch ou ajoute un profil App Store pour:

```text
com.retrytech.chatter
```

Le workflow `ios-testflight-signed` utilise:

```yaml
ios_signing:
  distribution_type: app_store
  bundle_identifier: com.retrytech.chatter
```

Donc Codemagic doit pouvoir trouver un certificat et un provisioning profile App Store pour ce bundle ID.

### 9.13. Lancer le workflow signe

Dans Codemagic:

1. Start new build.
2. Workflow:

```text
ios-testflight-signed
```

3. Branch:

```text
main
```

4. Start build.

Etapes attendues:

- Verify required API key;
- Install Flutter dependencies;
- Analyze Flutter project;
- Run Flutter tests;
- Install iOS pods;
- Set up iOS code signing;
- Build signed IPA.

Resultat attendu:

```text
Build successful
```

Artifacts attendus:

```text
build/ios/ipa/*.ipa
build/ios/archive/*.xcarchive
```

### 9.14. Envoyer a TestFlight

Option A, upload automatique Codemagic:

- Configurer `publishing: app_store_connect` dans `codemagic.yaml`;
- utiliser l'integration App Store Connect;
- activer `submit_to_testflight` si necessaire.

Je n'ai pas active automatiquement cette partie dans le YAML actuel pour ne pas casser le premier workflow si l'integration Codemagic n'existe pas encore.

Option B, upload manuel depuis artifact:

1. Telecharger l'IPA depuis Codemagic.
2. Sur un Mac, ouvrir Transporter.
3. Glisser l'IPA dans Transporter.
4. Cliquer Deliver.
5. Attendre le traitement App Store Connect.
6. Aller dans App Store Connect > My Apps > ITGA > TestFlight.
7. Ajouter le build aux testeurs internes.

Si tu n'as pas de Mac, utilise l'upload automatique Codemagic apres avoir configure `publishing`.

### 9.15. Tests iOS TestFlight obligatoires

Sur un vrai iPhone via TestFlight:

1. Installer ITGA.
2. Ouvrir l'app.
3. Accepter/refuser tracking et verifier que l'app ne crash pas.
4. Connexion utilisatrice.
5. Feed.
6. Suggestions de rooms.
7. Ouvrir une room.
8. Rejoindre une room.
9. Creer un post texte.
10. Creer un post avec image.
11. Commenter.
12. Reagir.
13. Notifications push.
14. Upload photo profil.
15. Deconnexion.
16. Connexion entreprise.
17. Feed entreprise.
18. Suggestions pertinentes.
19. Rotation portrait/paysage si supportee.
20. App en background puis retour foreground.

STOP si un crash apparait dans TestFlight ou Firebase Crashlytics.

---

## 10. Capacite: milliers de requetes par seconde

### 10.1. Verite importante

Le code seul ne garantit pas des milliers de requetes par seconde. Il faut le mesurer sur l'infrastructure reelle.

Un backend mutualise Hostinger peut etre suffisant pour un lancement controle, mais pas pour une promesse "comme LinkedIn".

### 10.2. Ce qui a ete mis en place dans le code

- Rate limiting global API branche sur le limiteur nomme `api`.
- Le limiteur nomme utilise mieux l'acteur de requete: token, user id, admin key ou IP.
- Uploads limites plus strictement.
- Writes limites plus strictement.
- Search limite plus strictement.
- Engagement limite separement.
- Request size limits sur endpoints sensibles.
- Suggestions rooms centralisees et limitees.
- Pas de fallback aleatoire inutile sur rooms suggerees.

### 10.3. Minimum infrastructure pour public controle

Backend:

- PHP 8.2+ recommande;
- OPcache active;
- `APP_DEBUG=false`;
- `config:cache`, `route:cache`, `view:cache`;
- HTTPS obligatoire;
- sauvegarde DB automatique quotidienne;
- logs surveilles;
- cron/queue actif.

Base de donnees:

- MySQL/MariaDB stable;
- indexes verifies;
- slow query log active si possible;
- sauvegarde automatique;
- pas de requetes lentes critiques pendant le test de charge.

Web:

- EasyPanel/DigitalOcean avec assez de RAM;
- HTTPS;
- CDN devant les assets si possible.

Mobile:

- API HTTPS;
- pas de cleartext release;
- Crashlytics/Sentry recommande;
- TestFlight/Play internal testing avant production.

### 10.4. Infrastructure recommandee pour milliers RPS

Pour viser des milliers de requetes par seconde de maniere credible:

1. Mettre le backend sur VPS/cloud dedie, pas mutualise.
2. Mettre Nginx devant PHP-FPM.
3. Dimensionner PHP-FPM workers selon CPU/RAM.
4. Activer OPcache.
5. Ajouter Redis pour:
   - cache;
   - queues;
   - sessions si necessaire;
   - rate limiting distribue.
6. Mettre uploads medias sur S3, Cloudflare R2 ou DigitalOcean Spaces.
7. Ajouter CDN pour images/videos/assets.
8. Ajouter queue workers supervises.
9. Ajouter monitoring:
   - uptime;
   - erreurs 5xx;
   - temps de reponse;
   - CPU/RAM;
   - DB connections;
   - queue depth.
10. Ajouter tests de charge reguliers.
11. Ajouter plan rollback.

### 10.5. Installer k6

k6 est l'outil utilise par `scripts/load-test-k6.js`.

Sur Windows:

```powershell
winget install k6.k6
```

Verifier:

```powershell
k6 version
```

Si `winget` n'existe pas, installer k6 depuis:

```text
https://k6.io/docs/get-started/installation/
```

### 10.6. Test de charge health

But: verifier que le backend repond vite sur endpoint leger.

Depuis la racine:

```powershell
k6 run -e BASE_URL=https://VOTRE-DOMAINE-BACKEND -e MODE=health -e RPS=50 -e DURATION=2m scripts/load-test-k6.js
```

Si ca passe, monter progressivement:

```powershell
k6 run -e BASE_URL=https://VOTRE-DOMAINE-BACKEND -e MODE=health -e RPS=100 -e DURATION=5m scripts/load-test-k6.js
k6 run -e BASE_URL=https://VOTRE-DOMAINE-BACKEND -e MODE=health -e RPS=250 -e DURATION=5m scripts/load-test-k6.js
k6 run -e BASE_URL=https://VOTRE-DOMAINE-BACKEND -e MODE=health -e RPS=500 -e DURATION=5m scripts/load-test-k6.js
```

STOP si:

- erreurs > 1%;
- p95 > 500 ms;
- p99 > 1000 ms;
- CPU serveur sature;
- DB sature;
- logs 500.

### 10.7. Test de charge feed authentifie

Il faut:

- un utilisateur de test;
- son `USER_ID`;
- son token auth;
- la valeur API key.

Commande:

```powershell
k6 run `
  -e BASE_URL=https://VOTRE-DOMAINE-BACKEND `
  -e MODE=feed `
  -e RPS=20 `
  -e DURATION=2m `
  -e API_KEY=VOTRE_API_SECRET_KEY `
  -e AUTH_TOKEN=TOKEN_UTILISATEUR_TEST `
  -e USER_ID=ID_UTILISATEUR_TEST `
  scripts/load-test-k6.js
```

Puis monter:

```powershell
k6 run -e BASE_URL=https://VOTRE-DOMAINE-BACKEND -e MODE=feed -e RPS=50 -e DURATION=5m -e API_KEY=VOTRE_API_SECRET_KEY -e AUTH_TOKEN=TOKEN_UTILISATEUR_TEST -e USER_ID=ID_UTILISATEUR_TEST scripts/load-test-k6.js
k6 run -e BASE_URL=https://VOTRE-DOMAINE-BACKEND -e MODE=feed -e RPS=100 -e DURATION=5m -e API_KEY=VOTRE_API_SECRET_KEY -e AUTH_TOKEN=TOKEN_UTILISATEUR_TEST -e USER_ID=ID_UTILISATEUR_TEST scripts/load-test-k6.js
```

Attention: utiliser un seul token a haute intensite declenche volontairement le rate limit. Pour tester des milliers de RPS realistes, il faut plusieurs comptes/tokens et une strategie de test distribuee.

### 10.8. Palier de decision

Lancement controle:

- health 100 RPS pendant 5 min OK;
- feed 20-50 RPS authentifie pendant 5 min OK;
- 0 erreur critique;
- p95 raisonnable;
- aucune saturation DB.

Lancement plus large:

- health 500 RPS OK;
- feed 100 RPS avec plusieurs comptes OK;
- monitoring actif;
- rollback teste.

Promesse milliers RPS:

- tests distribues avec plusieurs machines;
- plusieurs instances backend;
- Redis;
- CDN;
- DB dimensionnee;
- queue workers;
- load balancer;
- rapport de charge archive.

---

## 11. Checklist fonctionnelle finale

### 11.1. Utilisatrice normale

Tester sur web, Android et iOS:

- inscription;
- verification email si activee;
- connexion;
- oubli mot de passe;
- edition profil;
- upload photo profil;
- selection interets;
- feed;
- suggestions rooms pertinentes;
- ouvrir room;
- rejoindre room;
- creer post texte;
- creer post image;
- commenter;
- reagir;
- story si activee;
- reels si active;
- notification;
- recherche;
- deconnexion;
- suppression compte si disponible.

### 11.2. Entreprise

Tester sur web et mobile si disponible:

- inscription entreprise;
- verification email;
- connexion;
- edition profil entreprise;
- feed entreprise;
- suggestions rooms pertinentes;
- creation post entreprise;
- creation offre/job;
- liste candidatures;
- profil public entreprise;
- deconnexion.

### 11.3. Admin

Tester dans l'admin:

- login admin;
- dashboard;
- utilisateurs;
- entreprises;
- moderation contenus;
- signalements;
- verification entreprise;
- verification utilisateur;
- blocage/deblocage;
- suppression contenu test;
- categories/tags/interets;
- parametres;
- deconnexion.

### 11.4. Securite minimum

Verifier:

- `APP_DEBUG=false`;
- HTTPS partout;
- pas de secret dans le repo;
- pas de `ADMIN_API_SECRET_KEY` dans web/mobile;
- pas de test AdMob en public;
- rate limits actifs;
- readiness protegee par token;
- admin protege par secret admin;
- backups actifs;
- logs surveilles.

---

## 12. Rollback

### 12.1. Quand rollback

Rollback si:

- backend retourne beaucoup de 500;
- login impossible;
- feed impossible;
- admin impossible;
- app mobile crash au demarrage;
- migration a casse des donnees;
- web EasyPanel pointe vers mauvaise API.

### 12.2. Rollback web EasyPanel

Option rapide:

1. Ouvrir EasyPanel.
2. Aller dans l'app web ITGA.
3. Ouvrir Deployments.
4. Revenir au dernier deployment stable.

Option Git:

1. Revenir a l'ancien tag.
2. Redeployer.

### 12.3. Rollback backend Hostinger

En SSH:

```bash
cd /chemin/vers/chatter_backend
git fetch --all --tags
git checkout TAG_PRECEDENT
composer install --no-dev --optimize-autoloader
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Si la base a ete modifiee gravement:

1. mettre le site en maintenance;
2. restaurer le dump SQL fait avant lancement;
3. relancer readiness;
4. tester login/feed/admin.

### 12.4. Rollback mobile

Android:

- Play Console > Production/Internal testing > suspendre rollout ou revenir a version precedente si possible.

iOS:

- App Store Connect > TestFlight/Version > retirer le build des testeurs ou stopper la distribution.

---

## 13. Liste STOP absolue

Ne pas ouvrir au public si une seule ligne est vraie:

- `APP_DEBUG=true` en production.
- `/api/health` echoue.
- `/api/readiness` echoue.
- Admin inaccessible.
- Connexion utilisateur impossible.
- Feed vide par bug.
- Suggestions rooms cassent le feed.
- iOS Codemagic no-codesign echoue.
- iOS Codemagic signed echoue.
- Android AAB impossible a generer.
- Web EasyPanel sans HTTPS.
- IDs AdMob de test en build public avec pubs activees.
- Pas de sauvegarde DB.
- Pas de rollback connu.
- Tests de charge minimum non executes.

---

## 14. Sources officielles

- Codemagic Flutter: https://docs.codemagic.io/flutter-configuration/flutter-projects/
- Codemagic iOS signing: https://docs.codemagic.io/yaml-code-signing/signing-ios/
- Codemagic App Store Connect publishing: https://docs.codemagic.io/yaml-publishing/app-store-connect/
- Flutter iOS release: https://docs.flutter.dev/deployment/ios
- Flutter Android release: https://docs.flutter.dev/deployment/android
- Next.js standalone output: https://nextjs.org/docs/app/api-reference/config/next-config-js/output
- EasyPanel docs: https://easypanel.io/docs
- k6 constant arrival rate: https://grafana.com/docs/k6/latest/using-k6/scenarios/executors/constant-arrival-rate/

# Microsoft SSO Hub – VOSVDF

Centrální přihlašovací portál (autentizační služba) pro webové aplikace školního hubu s podporou přihlašování přes účty Microsoft 365 (školní Azure AD / Entra ID). 

Projekt je navržen tak, aby jej bylo možné univerzálně a bezpečně integrovat s dalšími aplikacemi v rámci školy, a to buď pomocí sdílení relací (PHP sessions), nebo bezstavově pomocí kryptograficky podepsaných JWT tokenů.

---

## Hlavní vlastnosti a bezpečnostní prvky

1. **Ochrana proti Open Redirect (Otevřené přesměrování):** 
   Aplikace nepovolí přesměrování na jakoukoliv adresu. V konfiguraci se definuje whitelist domén (`allowed_callback_domains`). Jakýkoliv pokus o přesměrování mimo tyto domény je zablokován a uživateli se zobrazí bezpečnostní varování.
2. **Ochrana proti CSRF (Cross-Site Request Forgery):**
   Při inicializaci přihlášení se generuje unikátní kryptograficky bezpečný parametr `state`. Ten se ukládá do relace a při návratu z Microsoftu se ověřuje. Záznam o toku se po ověření ihned maže, čímž se předchází i útokům typu Replay.
3. **Zabezpečení PHP relací (Sessions):**
   * Automatická ochrana proti **Session Fixation** pomocí regenerace ID relace (`session_regenerate_id(true)`) ihned po úspěšném přihlášení.
   * Session cookies jsou konfigurovány s flagy:
     * `HttpOnly` – zabraňuje čtení cookie pomocí JavaScriptu (ochrana proti XSS).
     * `Secure` – cookie se přenáší pouze po šifrovaném HTTPS.
     * `SameSite=Lax` – ochrana před nechtěným přenosem relace z jiných webů.
4. **JWT Autentizace pro externí aplikace:**
   Možnost předávat informace o uživateli v URL adrese formou kryptograficky podepsaného **JSON Web Tokenu (JWT)** s algoritmem HS256 a krátkou dobou platnosti (např. 60 sekund). Vhodné pro aplikace běžící na jiných serverech či v jiných technologiích (Node.js, Python apod.).

---

## Požadavky na běh

* **PHP 8.0** nebo novější
* **Apache web server** s povoleným modulem `mod_rewrite` (pro zpracování `.htaccess`)
* PHP rozšíření `curl` a `openssl`

---

## Konfigurace v Azure Portálu (Entra ID)

1. V Azure portálu v sekci **App Registrations** zaregistrujte novou aplikaci.
2. Jako typ platformy zvolte **Web** a nastavte **Redirect URI** na adresu callbacku, např.:
   `https://www.skolavdf.cz/hub/auth/callback` (respektive cestu k souboru `callback.php`).
3. V sekci **Certificates & secrets** vygenerujte nový **Client Secret** a poznačte si jeho hodnotu.
4. V sekci **API permissions** přidejte následující oprávnění pro Microsoft Graph:
   * `User.Read` (Delegated) – Základní čtení profilu uživatele.
   * `offline_access` (Delegated) – Pro možnost dlouhodobého přístupu.
   * *Volitelně:* `Directory.Read.All` nebo `GroupMember.Read.All` – Pokud aplikace potřebuje načítat zařazení studentů/učitelů do školních skupin (vyžaduje **Admin Consent** – schválení správcem školního Microsoft 365).

## Struktura projektu

Projekt využívá bezpečné rozdělení souborů. Vnitřní logika a tajné konfigurace jsou uloženy v podsložce `/src`, která je chráněna před přímým přístupem z webu pomocí souboru `src/.htaccess`.

```
auth/
├── src/                     # Vnitřní chráněné soubory
│   ├── .htaccess            # Blokuje přístup z internetu (Require all denied)
│   ├── MsGraphAuth.php      # Autentizační třída Microsoft Graph API
│   ├── config.php           # Základní konfigurace projektu
│   ├── config.local.php     # Lokální konfigurace s tajnými klíči (necommituje se)
│   └── config.local.example.php # Vzor pro vytvoření config.local.php
├── index.php                # Vstupní bod pro přihlášení
├── callback.php             # Návratový bod pro zpracování přihlášení
├── .htaccess                # Apache přesměrování pro callback endpoint
├── .gitignore               # Ignorování config.local.php v Gitu
└── README.md                # Tato dokumentace
```

---

## Instalace a zprovoznění

1. **Naklonujte** tento repozitář do Vaší cílové složky na hostingu (např. `/auth`).
2. Vytvořte lokální konfigurační soubor zkopírováním šablony uvnitř složky `src`:
   ```bash
   cp src/config.local.example.php src/config.local.php
   ```
3. Otevřete `src/config.local.php` a doplňte údaje, které jste získali z Azure Portálu:
   ```php
   return [
       'clientId' => 'VAS_CLIENT_ID',
       'clientSecret' => 'VAS_CLIENT_SECRET',
       'tenantId' => 'VAS_TENANT_ID',
       
       'jwt' => [
           'secret' => 'ZDE_VYGENERUJTE_DLOUHY_NAHODNY_RETEZEC_PRO_PODPIS_JWT'
       ]
   ];
   ```
   *Poznámka: Soubor `src/config.local.php` je zanesen v `.gitignore` a nebude nahrán na GitHub.*
4. V souboru `src/config.php` upravte parametr `allowed_callback_domains` a doplňte domény Vašich školních aplikací, na které se bude moci přihlášení vracet:
   ```php
   'allowed_callback_domains' => [
       'skolavdf.cz',
       'localhost'
   ],
   ```

---

## Struktura dat v PHP SESSION

Po dokončení autentizačního toku se mění stav PHP relace (`$_SESSION`) následovně. 

*Poznámka: Výchozí název klíče je `$_SESSION['user']`. Pokud tento klíč koliduje s Vaším běžícím systémem, můžete si název klíče změnit v konfiguraci `src/config.php` (parametr `session['session_key']`). V ukázkách níže předpokládáme výchozí název `user`.*

### 1. Úspěšné přihlášení
Při úspěšném přihlášení a načtení dat z Microsoft Graph API se do relace ukládá uživatelský profil pod nakonfigurovaným klíčem (výchozí `$_SESSION['user']`) s následující strukturou:

```php
$_SESSION['user'] = [
    'id' => '12345678-abcd-1234-abcd-1234567890ab', // Unikátní ID uživatele v Entra ID
    'displayName' => 'Jan Novák',                    // Celé jméno uživatele
    'mail' => 'novak.jan@skolavdf.cz',              // Hlavní e-mail (nebo UPN jako fallback)
    'userPrincipalName' => 'novakj@skolavdf.cz',     // Přihlašovací login (UPN)
    'department' => 'IT oddělení',                  // Oddělení / Třída
    'jobTitle' => 'Učitel',                          // Funkce / Role (např. Student, Učitel)
    'officeLocation' => 'Kancelář 204',              // Kancelář / Kabinet / Učebna
    'groups' => [                                    // Seznam Microsoft skupin uživatele
        [
            'id' => '87654321-dbca-4321-dbca-9876543210ba',
            'displayName' => 'Učitelé - celá škola'
        ],
        [
            'id' => '09876543-aaaa-bbbb-cccc-111122223333',
            'displayName' => 'Sekce IT'
        ]
    ]
];
```

*Poznámka: Pokud Microsoft Graph API některé volitelné pole (např. `department` nebo `officeLocation`) nemá u uživatele vyplněné, bude v poli uložena prázdná hodnota `""` (nikoliv null).*

### 2. Neúspěšné přihlášení / Chyba
Pokud přihlášení selže (chyba Microsoftu, neplatné tokeny, zamítnutí souhlasu uživatelem):
- Služba vypíše chybovou hlášku a ukončí skript (`exit`).
- Relace **neobsahuje** klíč `$_SESSION['user']` (hodnota `isset($_SESSION['user'])` vrátí `false`).
- Pokud existuje aktivní relace z dřívějška, pro bezpečnostní jistotu doporučujeme provést odhlášení/vyčištění relace v klientské aplikaci.

### 3. Chyba přesměrování (Open Redirect protection)
Pokud uživatel přejde na přihlašovací stránku s neplatným parametrem `callback` (nepovolená doména):
- Relace se normálně spustí a vygeneruje se CSRF stav.
- Klíč `$_SESSION['user']` **není nastaven**.
- Na stránce se zobrazí chybové hlášení: *"Chyba zabezpečení: Neplatná doména pro přesměrování."* a tlačítko na přihlášení je sice aktivní, ale po návratu z MS nebude přesměrování provedeno (uživatel zůstane na debug success stránce).

---

## Integrace s klientskými aplikacemi

### Metoda A: Sdílená relace (Shared Session)
Pokud Vaše klientské aplikace (např. `www.skolavdf.cz/hub/znamky`) běží na stejném serveru jako přihlašovací hub (`www.skolavdf.cz/hub/auth/`) a sdílejí PHP sessions.

1. **Přesměrování na přihlášení:**
   Nasměrujte nepřihlášeného uživatele na:
   `https://www.skolavdf.cz/hub/auth/?callback=https://www.skolavdf.cz/hub/znamky/dashboard.php`
2. **Čtení relace v klientské aplikaci:**
   Ujistěte se, že máte nastavenou stejnou doménu session cookies (s tečkou na začátku, např. `.skolavdf.cz`):
   ```php
   <?php
   session_set_cookie_params([
       'lifetime' => 0,
       'path' => '/',
       'domain' => '.skolavdf.cz',
       'secure' => true,
       'httponly' => true,
       'samesite' => 'Lax'
   ]);
   session_start();

   if (!isset($_SESSION['user'])) {
       // Přesměrování na auth hub
       $callback = urlencode('https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
       header('Location: https://www.skolavdf.cz/hub/auth/?callback=' . $callback);
       exit;
   }

   // Uživatel je přihlášen, data jsou v $_SESSION['user']
   $user = $_SESSION['user'];
   echo "Přihlášen jako: " . htmlspecialchars($user['displayName']);
   ```

### Metoda B: Přesávání identity pomocí JWT (Bezstavové SSO)
Vhodné pro aplikace na jiných serverech nebo běžící v jiném programovacím jazyce.

1. **Přesměrování na přihlášení:**
   Nasměrujte uživatele na:
   `https://www.skolavdf.cz/hub/auth/?callback=https://moje-aplikace.cz/prijem-autentizace.php`
2. **Ověření a dekódování tokenu v přijímači:**
   Cílová aplikace získá token z URL (`?token=...`) a ověří podpis pomocí tajného klíče (který sdílí s auth serverem):
   ```php
   <?php
   $jwtSecret = 'STEJNY_TAJNY_KLIC_JAKO_V_CONFIG_LOCAL';
   $token = $_GET['token'] ?? '';

   if (empty($token)) {
       die('Chyba: Token chybí.');
   }

   // Pomocná funkce pro dekódování base64url
   function base64UrlDecode($input) {
       return base64_decode(str_replace(['-', '_'], ['+', '/'], $input));
   }

   $parts = explode('.', $token);
   if (count($parts) !== 3) {
       die('Chyba: Neplatný formát tokenu.');
   }

   list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;

   // Validace podpisu
   $signatureData = $headerEncoded . '.' . $payloadEncoded;
   $expectedSignature = hash_hmac('sha256', $signatureData, $jwtSecret, true);

   if (!hash_equals(base64UrlDecode($signatureEncoded), $expectedSignature)) {
       die('Chyba: Podpis tokenu nesouhlasí.');
   }

   $payload = json_decode(base64UrlDecode($payloadEncoded), true);
   
   // Kontrola expirace
   if (isset($payload['exp']) && $payload['exp'] < time()) {
       die('Chyba: Token vypršel.');
   }

   // Úspěšně ověřeno, data uživatele jsou v $payload['user']
   session_start();
   $_SESSION['user'] = $payload['user'];
   header('Location: /index.php');
   exit;
   ```

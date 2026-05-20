<?php
$config = require_once 'src/config.php';

// Funkce pro bezpečné ověření callback URL (Open Redirect protection)
function isValidCallback($url, $allowedDomains) {
    if (empty($url)) {
        return false;
    }
    
    $parsedUrl = parse_url($url);
    // Pokud je to relativní cesta (např. /dashboard), je to bezpečné
    if ($parsedUrl === false || !isset($parsedUrl['host'])) {
        return (strpos($url, '/') === 0 && strpos($url, '//') !== 0);
    }

    $host = strtolower($parsedUrl['host']);
    foreach ($allowedDomains as $domain) {
        $domain = strtolower($domain);
        // Povolí přesný zápis domény nebo jakoukoliv subdoménu (např. *.skolavdf.cz)
        if ($host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain) {
            return true;
        }
    }
    return false;
}

// Zabezpečené nastavení PHP Session
$sessionConfig = $config['session'] ?? [];
$cookieParams = [
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || ($_SERVER['SERVER_PORT'] == 443) || ($sessionConfig['cookie_secure'] ?? true),
    'httponly' => $sessionConfig['cookie_httponly'] ?? true,
    'samesite' => $sessionConfig['cookie_samesite'] ?? 'Lax'
];

// Nastavení domény session pouze pokud nejsme na localhostu a doména je v konfiguraci
$host = $_SERVER['HTTP_HOST'] ?? '';
if (strpos($host, 'localhost') === false && !empty($sessionConfig['cookie_domain'])) {
    $cookieParams['domain'] = $sessionConfig['cookie_domain'];
}

session_set_cookie_params($cookieParams);
session_start();

require_once 'src/MsGraphAuth.php';
$auth = new MsGraphAuth($config);
$loginUrl = $auth->getAuthorizationUrl();

// Získání a uložení state parametru pro CSRF ověření
$state = parse_url($loginUrl, PHP_URL_QUERY);
parse_str($state, $queryParams);
$stateValue = $queryParams['state'] ?? '';

// Zpracování a validace callback parametru
$callbackUrl = $_GET['callback'] ?? '';
$isValid = false;
$errorMessage = '';

if (!empty($callbackUrl)) {
    if (isValidCallback($callbackUrl, $config['allowed_callback_domains'] ?? [])) {
        $isValid = true;
    } else {
        $errorMessage = 'Neplatná doména pro přesměrování (Callback URL není povolena).';
    }
}

// Uložení toku do session pod unikátním state
$_SESSION['auth_flows'][$stateValue] = [
    'callback' => $isValid ? $callbackUrl : '',
    'created_at' => time()
];
$_SESSION['oauth_state'] = $stateValue; // Zpětná kompatibilita

// Auto-redirect when valid callback provided — skip intermediate button page
if ($isValid) {
    header('Location: ' . $loginUrl);
    exit;
}
?>
<!DOCTYPE html>
<html lang="cs">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Přihlášení - VOSVDF Hub</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            background-color: #f0f2f5;
            margin: 0;
        }

        .login-container {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .btn-microsoft {
            background-color: #2F2F2F;
            color: white;
            border: none;
            padding: 10px 20px;
            font-size: 16px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            border-radius: 4px;
            margin-top: 20px;
            transition: background 0.3s;
        }

        .btn-microsoft:hover {
            background-color: #000;
        }

        h1 {
            margin-top: 0;
            color: #333;
        }

        .error-message {
            background-color: #fce8e6;
            color: #d93025;
            border: 1px solid #f5c6cb;
            padding: 10px;
            border-radius: 4px;
            margin-top: 15px;
            font-size: 14px;
            text-align: left;
        }
    </style>
</head>

<body>
    <div class="login-container">
        <h1>VOSVDF Hub</h1>
        <p>Pro přístup do aplikace se prosím přihlaste.</p>
        
        <?php if (!empty($errorMessage)): ?>
            <div class="error-message">
                <strong>Chyba zabezpečení:</strong> <?php echo htmlspecialchars($errorMessage); ?>
            </div>
        <?php endif; ?>

        <a href="<?php echo htmlspecialchars($loginUrl); ?>" class="btn-microsoft">
            Přihlásit se přes Microsoft
        </a>
    </div>
</body>

</html>
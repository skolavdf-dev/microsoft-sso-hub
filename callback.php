<?php
$config = require_once 'src/config.php';

// Zabezpečené nastavení PHP Session (musí odpovídat nastavení v index.php)
$sessionConfig = $config['session'] ?? [];
$cookieParams = [
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || ($_SERVER['SERVER_PORT'] == 443) || ($sessionConfig['cookie_secure'] ?? true),
    'httponly' => $sessionConfig['cookie_httponly'] ?? true,
    'samesite' => $sessionConfig['cookie_samesite'] ?? 'Lax'
];

$host = $_SERVER['HTTP_HOST'] ?? '';
if (strpos($host, 'localhost') === false && !empty($sessionConfig['cookie_domain'])) {
    $cookieParams['domain'] = $sessionConfig['cookie_domain'];
}

session_set_cookie_params($cookieParams);
session_start();

require_once 'src/MsGraphAuth.php';

// Kontrola kódu od Microsoftu
if (!isset($_GET['code'])) {
    echo 'Chyba: Kód nebyl vrácen z Microsoft přihlášení. <a href="index.php">Zkusit znovu</a>';
    exit;
}

// 1. Ověření CSRF stavu (state parameter verification)
$state = $_GET['state'] ?? '';
if (empty($state) || !isset($_SESSION['auth_flows'][$state])) {
    echo 'Chyba: Neplatný nebo expirovaný state parametr. Přihlášení mohlo být zneužito (CSRF) nebo vypršela relace. <a href="index.php">Zkusit znovu</a>';
    exit;
}

// Získání toku (callback URL) z relace
$flow = $_SESSION['auth_flows'][$state];
$callbackUrl = $flow['callback'] ?? '';

// Odstranění stavu z relace pro zamezení opakovaného použití (Replay attack)
unset($_SESSION['auth_flows'][$state]);

try {
    $auth = new MsGraphAuth($config);

    // 2. Získání Access Tokenu
    $tokenResponse = $auth->getAccessToken($_GET['code']);

    if (isset($tokenResponse['error'])) {
        echo 'Chyba při získávání tokenu: ' . htmlspecialchars($tokenResponse['error_description'] ?? $tokenResponse['error']);
        echo '<pre>';
        print_r($tokenResponse);
        echo '</pre>';
        exit;
    }

    $accessToken = $tokenResponse['access_token'];

    // 3. Načtení profilu uživatele
    $userProfile = $auth->getUserProfile($accessToken);

    // 4. Načtení skupin uživatele
    $userGroups = $auth->getUserGroups($accessToken);

    // 5. Strukturování uživatelských dat pro relaci
    $userData = [
        'id' => $userProfile['id'] ?? null,
        'displayName' => $userProfile['displayName'] ?? '',
        'mail' => $userProfile['mail'] ?? $userProfile['userPrincipalName'] ?? '',
        'userPrincipalName' => $userProfile['userPrincipalName'] ?? '',
        'department' => $userProfile['department'] ?? '',
        'jobTitle' => $userProfile['jobTitle'] ?? '',
        'officeLocation' => $userProfile['officeLocation'] ?? '',
        'groups' => []
    ];

    if (isset($userGroups['value']) && is_array($userGroups['value'])) {
        foreach ($userGroups['value'] as $group) {
            if (isset($group['id']) && isset($group['displayName'])) {
                $userData['groups'][] = [
                    'id' => $group['id'],
                    'displayName' => $group['displayName']
                ];
            }
        }
    }

    // Ochrana proti Session Fixation - regenerujeme ID relace při změně stavu autentizace
    session_regenerate_id(true);
    $sessionKey = $config['session']['session_key'] ?? 'user';
    $_SESSION[$sessionKey] = $userData;

    // 6. Pokud byl zadán a schválen callback, přesměrujeme uživatele zpět
    if (!empty($callbackUrl)) {
        $redirectUrl = $callbackUrl;
        
        // Pokud je povolen JWT, vygenerujeme token a připojíme ho k URL
        if (($config['jwt']['enabled'] ?? false) === true) {
            $jwtSecret = $config['jwt']['secret'] ?? '';
            $expiration = $config['jwt']['expiration'] ?? 60;
            
            $payload = [
                'iss' => 'VOSVDF_Hub_Auth',
                'sub' => $userData['id'],
                'iat' => time(),
                'exp' => time() + $expiration,
                'user' => $userData
            ];
            
            $base64UrlEncode = function($data) {
                return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
            };
            
            $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
            $base64UrlHeader = $base64UrlEncode($header);
            $base64UrlPayload = $base64UrlEncode(json_encode($payload));
            
            $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $jwtSecret, true);
            $base64UrlSignature = $base64UrlEncode($signature);
            
            $jwt = $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
            
            // Bezpečně připojíme k URL
            $queryGlue = (strpos($redirectUrl, '?') === false) ? '?' : '&';
            $redirectUrl .= $queryGlue . 'token=' . urlencode($jwt);
        }
        
        header('Location: ' . $redirectUrl);
        exit;
    }
    ?>
    <!DOCTYPE html>
    <html lang="cs">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Výsledek přihlášení - VOSVDF Hub</title>
        <style>
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background-color: #f0f2f5;
                color: #333;
                margin: 0;
                padding: 20px;
                line-height: 1.6;
            }

            .container {
                max-width: 1200px;
                margin: 0 auto;
                background: white;
                padding: 30px;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            }

            h1 {
                color: #2F2F2F;
                border-bottom: 2px solid #eee;
                padding-bottom: 10px;
            }

            h2 {
                color: #0078d4;
                margin-top: 0;
            }

            .grid {
                display: grid;
                grid-template-columns: 1fr;
                gap: 30px;
                margin-top: 20px;
            }

            @media (min-width: 768px) {
                .grid {
                    grid-template-columns: 1fr 1fr;
                }
            }

            table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 20px;
                font-size: 14px;
            }

            th,
            td {
                text-align: left;
                padding: 10px;
                border-bottom: 1px solid #ddd;
            }

            th {
                background-color: #f8f9fa;
                font-weight: 600;
                width: 30%;
                vertical-align: top;
            }

            td {
                word-break: break-all;
            }

            ul {
                list-style-type: none;
                padding: 0;
                margin: 0;
            }

            li {
                padding: 8px 0;
                border-bottom: 1px solid #eee;
            }

            li:last-child {
                border-bottom: none;
            }

            .error {
                color: #d93025;
                background: #fce8e6;
                padding: 15px;
                border-radius: 4px;
                border: 1px solid #f5c6cb;
            }

            .debug-section {
                margin-top: 40px;
                padding-top: 20px;
                border-top: 1px solid #ccc;
            }

            pre {
                background: #f4f4f4;
                padding: 15px;
                overflow-x: auto;
                border-radius: 4px;
                font-size: 0.85em;
            }

            .btn {
                display: inline-block;
                background: #0078d4;
                color: white;
                padding: 10px 20px;
                text-decoration: none;
                border-radius: 4px;
                margin-top: 20px;
            }

            .btn:hover {
                background: #005a9e;
            }

            summary {
                cursor: pointer;
                color: #0078d4;
                font-weight: 600;
            }
        </style>
    </head>

    <body>
        <div class="container">
            <h1>Přihlášení úspěšné!</h1>

            <div class="grid">
                <div>
                    <h2>Informace o uživateli</h2>
                    <?php
                    $displayFields = [
                        'displayName' => 'Jméno',
                        'mail' => 'Email',
                        'userPrincipalName' => 'UPN (Login)',
                        'department' => 'Oddělení',
                        'jobTitle' => 'Funkce',
                        'officeLocation' => 'Kancelář',
                        'id' => 'ID Uživatele'
                    ];
                    ?>
                    <table>
                        <?php foreach ($displayFields as $key => $label): ?>
                            <tr>
                                <th><?php echo htmlspecialchars($label); ?></th>
                                <td>
                                    <?php 
                                    $val = $userProfile[$key] ?? null;
                                    if ($val === null || $val === '') {
                                        echo '<em style="color:#999;">(nevyplněno)</em>';
                                    } else {
                                        echo htmlspecialchars($val);
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <!-- Zobrazení ostatních polí, která nejsou v definici (volitelné, pro debug) -->
                        <?php 
                        foreach ($userProfile as $key => $value) {
                            if (array_key_exists($key, $displayFields)) continue;
                            if (strpos($key, '@') === 0) continue; // Skip metadata
                            ?>
                            <tr>
                                <th style="color:#777; font-weight:normal;"><?php echo htmlspecialchars($key); ?></th>
                                <td style="color:#777;">
                                    <?php echo is_array($value) ? json_encode($value) : htmlspecialchars($value); ?>
                                </td>
                            </tr>
                            <?php
                        }
                        ?>
                    </table>
                </div>

                <div>
                    <h2>Skupiny uživatele</h2>
                    <?php if (isset($userGroups['error'])): ?>
                        <div class="error">
                            <strong>Nepodařilo se načíst skupiny.</strong><br>
                            <br>
                            Chyba:
                            <em><?php echo htmlspecialchars($userGroups['error']['message'] ?? $userGroups['error']['code'] ?? 'Unknown error'); ?></em>
                            <br><br>
                            <small>Pravděpodobně aplikace nemá oprávnění <code>GroupMember.Read.All</code> nebo
                                <code>Directory.Read.All</code>. Je vyžadován <strong>Admin Consent</strong>.</small>
                        </div>
                    <?php else: ?>
                        <?php $groups = $userGroups['value'] ?? []; ?>
                        <?php if (empty($groups)): ?>
                            <p>Uživatel není v žádných skupinách (nebo nejsou viditelné).</p>
                        <?php else: ?>
                            <ul>
                                <?php foreach ($groups as $group): ?>
                                    <li>
                                        <strong><?php echo htmlspecialchars($group['displayName'] ?? 'N/A'); ?></strong><br>
                                        <small style="color:#666;"><?php echo htmlspecialchars($group['id'] ?? 'N/A'); ?></small>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="debug-section">
                <details>
                    <summary>Zobrazit technická data (JSON)</summary>
                    <pre><?php print_r(['profile' => $userProfile, 'groups' => $userGroups]); ?></pre>
                </details>
            </div>

            <a href="index.php" class="btn">Zpět na přihlášení</a>
        </div>
    </body>

    </html>
    <?php

} catch (Exception $e) {
    echo '<div style="padding:20px; color:red; font-family:sans-serif;">';
    echo '<h1>Chyba aplikace</h1>';
    echo '<p>Exception: ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '</div>';
}
?>
<?php

$baseConfig = [
    // Tyto klíče doplňte v souboru config.local.php (který se necommituje do gitu)
    'clientId' => '',
    'clientSecret' => '',
    'tenantId' => '',
    
    'redirectUri' => 'https://www.skolavdf.cz/hub/auth/callback',
    'scopes' => 'User.Read offline_access',

    // Bezpečnostní whitelist pro přesměrování (Open Redirect protection)
    // Povolené domény a jejich subdomény (např. 'skolavdf.cz' povolí i 'grades.skolavdf.cz')
    'allowed_callback_domains' => [
        'skolavdf.cz',
        'localhost'
    ],

    // Konfigurace pro bezstavové přihlášení pomocí JWT tokenu
    'jwt' => [
        'enabled' => true,
        'secret' => '', // Doplňte v config.local.php
        'expiration' => 60, // Platnost tokenu v sekundách (doporučeno krátké pro jednorázové přihlášení)
    ],

    // Konfigurace PHP session cookie
    'session' => [
        'session_key' => 'user',           // Název klíče v $_SESSION, kam se uloží profil uživatele
        'cookie_domain' => '.skolavdf.cz', // Tečka na začátku povolí cookie pro všechny subdomény
        'cookie_secure' => true,           // Pouze přes HTTPS
        'cookie_httponly' => true,         // Ochrana proti XSS (nedostupné v JS)
        'cookie_samesite' => 'Lax'         // Ochrana proti CSRF
    ]
];

// Načtení a sloučení lokální tajné konfigurace (pokud existuje)
$localConfigPath = __DIR__ . '/config.local.php';
if (file_exists($localConfigPath)) {
    $localConfig = require $localConfigPath;
    if (is_array($localConfig)) {
        foreach ($localConfig as $key => $value) {
            if (is_array($value) && isset($baseConfig[$key]) && is_array($baseConfig[$key])) {
                $baseConfig[$key] = array_merge($baseConfig[$key], $value);
            } else {
                $baseConfig[$key] = $value;
            }
        }
    }
}

return $baseConfig;

<?php

// Příklad lokální konfigurace.
// Zkopírujte tento soubor jako 'src/config.local.php' a doplňte vlastní údaje z Azure Portálu a tajný klíč pro JWT.
// Soubor 'src/config.local.php' nikdy necommitujte do Gitu!

return [
    'clientId' => 'ID_APLIKACE_Z_AZURE_PORTALU',
    'clientSecret' => 'TAJNY_KLIC_CLIENT_SECRET_Z_AZURE',
    'tenantId' => 'ID_TENANTU_Z_AZURE_PORTALU',
    
    'jwt' => [
        'secret' => 'ZDE_VYGENERUJTE_DLOUHY_NAHODNY_BEZPECNE_TAJNY_RETEZEC'
    ]
];

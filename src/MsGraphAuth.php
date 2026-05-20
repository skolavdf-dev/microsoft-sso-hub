<?php

class MsGraphAuth
{
    private $clientId;
    private $clientSecret;
    private $tenantId;
    private $redirectUri;
    private $scopes;

    public function __construct($config)
    {
        $this->clientId = $config['clientId'];
        $this->clientSecret = $config['clientSecret'];
        $this->tenantId = $config['tenantId'];
        $this->redirectUri = $config['redirectUri'];
        $this->scopes = $config['scopes'];
    }

    public function getAuthorizationUrl()
    {
        $params = [
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri,
            'response_mode' => 'query',
            'scope' => $this->scopes,
            'state' => bin2hex(random_bytes(16)) // Simple CSRF protection
        ];

        return 'https://login.microsoftonline.com/' . $this->tenantId . '/oauth2/v2.0/authorize?' . http_build_query($params);
    }

    public function getAccessToken($code)
    {
        $url = 'https://login.microsoftonline.com/' . $this->tenantId . '/oauth2/v2.0/token';

        $params = [
            'client_id' => $this->clientId,
            'scope' => $this->scopes,
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
            'client_secret' => $this->clientSecret
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new Exception('Curl error: ' . curl_error($ch));
        }

        curl_close($ch);

        return json_decode($response, true);
    }

    public function getUserProfile($accessToken)
    {
        // Select specific fields including jobTitle and department
        $url = 'https://graph.microsoft.com/v1.0/me?$select=id,displayName,userPrincipalName,mail,jobTitle,department,officeLocation,preferredLanguage,onPremisesExtensionAttributes';

        return $this->makeGraphRequest($url, $accessToken);
    }

    public function getUserGroups($accessToken)
    {
        // Try to get group membership (transitive)
        // Warning: May require Directory.Read.All or similar permissions
        $url = 'https://graph.microsoft.com/v1.0/me/transitiveMemberOf?$select=id,displayName,groupTypes';

        return $this->makeGraphRequest($url, $accessToken);
    }

    private function makeGraphRequest($url, $accessToken)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new Exception('Curl error: ' . curl_error($ch));
        }

        curl_close($ch);

        return json_decode($response, true);
    }
}

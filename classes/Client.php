<?php

namespace RecReviews;

if (!defined('ABSPATH')) {
    exit;
}

class Client
{
    const WEBSITE_URL = 'https://recreviews.com/';
    const API_URL = 'https://dashboard.recreviews.com/';

    /**
     * The access token to use to authentify requests
     *
     * @var string
     */
    private $access_token;

    private function __construct()
    {
    }

    public static function getClient()
    {
        static $client = null;
        if ($client === null) {
            $client = new static();
        }
        return $client;
    }

    /**
     * Retrieve Request Handle
     *
     * @param string $access_token
     * @param string $url
     * @param string $method
     * @param array $params
     * @param array $addHeaders
     *
     * @return resource
     */
    private static function getRequestHandle($access_token, $url, $method = 'GET', $params = [], $addHeaders = [])
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-type' => 'application/json',
            'X-Platform' => 'WooCommerce',
        ];
        if (!empty($access_token)) {
            $headers['Authorization'] = 'Bearer ' . $access_token;
        }

        // Combine headers, prevent override of original headers
        $headers = ($headers + $addHeaders);

        if ($method == 'GET') {
            $responseHandle = wp_remote_get(esc_url_raw($url), [
                'timeout' => 15,
                'headers' => $headers,
            ]);
        } elseif ($method == 'POST') {
            $responseHandle = wp_remote_post(esc_url_raw($url), [
                'timeout' => 15,
                'headers' => $headers,
                'body' => json_encode($params),
            ]);
        }

        return $responseHandle;
    }

    /**
     * Retrieve response regarding a query
     *
     * @param string $access_token
     * @param string $path
     * @param array $params
     * @param array $addHeaders
     *
     * @return object
     *
     * @throws Exception
     */
    public static function getResponse($access_token, $path, $method = 'GET', $params = [], $addHeaders = [])
    {
        // Create URL
        $url = self::API_URL . $path;

        if (count($params) && $method == 'GET') {
            $url .= '?' . http_build_query($params);
        }

        $response = $errorNb = $errorMessage = $httpCode = null;

        // Retrieve Request handle
        $requestHandle = self::getRequestHandle($access_token, $url, $method, $params, $addHeaders);
        if (!is_wp_error($requestHandle)) {
            $responseBody = wp_remote_retrieve_body($requestHandle);
            $httpCode = wp_remote_retrieve_response_code($requestHandle);
            $response = json_decode($responseBody);
        } else {
            $errorMessage = $requestHandle->get_error_message();
        }

        if ($httpCode == 200) {
            return $response;
        } elseif ($httpCode == 429) {
            throw new \Exception('Error Processing Request - Too many requests', 2);
        } elseif ($httpCode == 403) {
            return $httpCode;
        } elseif ($httpCode == 419) {
            throw new \Exception('Error Processing CSRF Token Request - Server error', 4);
        } elseif ($httpCode == 500) {
            throw new \Exception('Error Processing Request - Server error', 5);
        } elseif ($httpCode == 504) {
            throw new \Exception('Error Processing Request - Timeout', 6);
        } elseif ($httpCode == 422) {
            throw new \Exception('Incomplete or invalid request', 8);
        } elseif (is_wp_error($requestHandle) || empty($httpCode)) {
            throw new \Exception(sprintf('Error Processing Request - Unknown - %s', $requestHandle->get_error_message()), 7);
        } elseif ($httpCode == 401) {
            // Happen when the Oauth didn't work
            throw new \Exception("Error Processing Request - HTTP Code $httpCode", 1);
        }

        throw new \Exception("Error Processing Request - HTTP Code $httpCode", 1);
    }

    /**
     * Retrieve onboarding data
     *
     * @return object
     */
    public static function getOnboarding()
    {
        $response = self::getResponse(null, 'onboarding-woocommerce?cms=woocommerce&version=' . WC()->version, 'GET', [], [
            'Accept-Language' => get_locale(),
        ]);

        if (!empty($response)) {
            return $response;
        }
        return null;
    }

    /**
     * Update configuration
     *
     * @param string $access_token
     * @param array $configuration
     *
     * @return object
     */
    public static function updateModuleConfiguration($access_token, $configuration)
    {
        $response = self::getResponse($access_token, 'api/module/configuration', 'POST', [
            'configuration' => $configuration,
        ]);

        if (!empty($response)) {
            return $response;
        }
        return null;
    }

    /**
     * get the Api shop
     *
     * @param string $access_token
     *
     * @return object
     */
    public static function getShop($access_token, $data = [])
    {
        if (empty($access_token)) {
            return false;
        }

        $response = self::getResponse($access_token, 'api/shop', 'GET', $data);

        if (!empty($response->result)) {
            return $response->shop;
        }

        return false;
    }

    /**
     * Send data to Gis on update status
     *
     * @param string $access_token
     * @param string $data
     *
     * @return object
     */
    public static function sendUpdateStatus($access_token, $data)
    {
        if (empty($access_token)) {
            return false;
        }

        $response = self::getResponse($access_token, 'api/orders/status', 'POST', $data);

        if (!empty($response->result)) {
            return true;
        }

        return true;
    }

    /**
     * Send data the order data information to handle email scheduler
     *
     * @param string $access_token
     * @param string $data
     *
     * @return object
     */
    public static function sendOrderData($access_token, $data)
    {
        if (empty($access_token)) {
            return false;
        }

        $response = self::getResponse($access_token, 'api/orders', 'POST', $data);

        if (!empty($response->result)) {
            return true;
        }

        return true;
    }

    /**
     * Revoke the access token
     *
     * @param string $access_token
     * @param string $data
     *
     * @return object
     */
    public static function revoke($access_token, $data)
    {
        if (empty($access_token)) {
            return false;
        }

        $response = self::getResponse($access_token, 'api/module/revoke', 'POST', $data);

        if (!empty($response->result)) {
            return true;
        }

        return false;
    }

    /**
     * Generates our code verifier for the oAuth process
     *
     * @param int $length
     *
     * @return string
     */
    public static function codeVerifierGen($length = 128)
    {
        $bytes = openssl_random_pseudo_bytes($length);
        $position = 0;
        $result = '';
        $str = 'abcdefghijkmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ.-_~';
        for ($i = 0; $i < $length; $i++) {
            $position = ($position + ord($bytes[$i])) % strlen($str);
            $result .= $str[$position];
        }

        return $result;
    }

    /**
     * Creates a code challenge based on the provided verifier string
     *
     * @param string $verifier
     *
     * @return string
     */
    public static function createCodeChallengeFromVerifier($verifier)
    {
        return strtr(rtrim(base64_encode(hash('sha256', $verifier, true)), '='), '+/', '-_');
    }

    /**
     * Setter for the access token
     *
     * @param string $access_token
     *
     * @return $this
     */
    public function setAccessToken($access_token)
    {
        $this->access_token = $access_token;

        return $this;
    }

    /**
     * Get the access token
     *
     * @param string $authorizationCode
     * @param string $redirectUri
     * @param string $clientId
     * @param string $codeVerifier
     *
     * @return object|null
     */
    public function getAccessToken($authorizationCode, $redirectUri, $clientId, $codeVerifier)
    {
        $response = $this->getResponse($this->access_token, 'oauth/token', 'POST', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'code_verifier' => $codeVerifier,
            'redirect_uri' => $redirectUri,
            'code' => $authorizationCode,
        ]);

        if (!empty($response) && !empty($response->token_type && !empty($response->access_token))) {
            return $response;
        }
        return null;
    }

    /**
     * Refresh the access token
     *
     * @param string $refreshToken
     * @param string $clientId
     * @param string $codeVerifier
     *
     * @return object|null
     */
    public function getRefreshAccessToken($refreshToken, $clientId, $codeVerifier)
    {
        $response = $this->getResponse($this->access_token, 'oauth/token', 'POST', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'code_verifier' => $codeVerifier,
        ]);

        if (!empty($response) && !empty($response->token_type && !empty($response->access_token))) {
            return $response;
        }
        return null;
    }
}

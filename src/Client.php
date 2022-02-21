<?php

namespace JPCaparas\TradeMeAPI;

use JPCaparas\TradeMeAPI\Concerns\ValidatesRequired;
use JPCaparas\TradeMeAPI\Exceptions\ClientException;
use JPCaparas\TradeMeAPI\Exceptions\RequestException;

/**
 * @todo More methods in tow
 */
class Client
{
    use ValidatesRequired;

    const SCOPE_READ = 'MyTradeMeRead';
    const SCOPE_WRITE = 'MyTradeMeWrite';

    /**
     * @var Request $request An (optional) pre-configured request object
     */
    private $request;

    public function __construct(array $requestOptions = [], ?Request $request = null)
    {
        $this->request = $request ?? new Request($requestOptions);
    }

    /**
     * Sell an item
     *
     * @param array $params
     *
     * @return string
     *
     * @throws RequestException
     */
    public function sellItem(array $params): string
    {
        $uri = 'Selling.json';

        $requiredKeys = [
            'Category',
            'Title',
            'Description',
            'Duration',
            'BuyNowPrice',
            'StartPrice',
            'PaymentMethods',
            'Pickup',
            'ShippingOptions'
        ];

        self::validateRequired($requiredKeys, $params, function (array $requiredKeys) {
            $errorMsg = sprintf(
                'In order to sell an item, you must include specify the following: %s.',
                join(', ', $requiredKeys)
            );

            throw new ClientException($errorMsg);
        });

        return $this->api('POST', $uri, $params);
    }

    /**
     * List selling items
     *
     * @param array  $params
     * @param string $filter
     *
     * @return string
     *
     * @throws RequestException
     */
    public function listSellingItems(array $params = [], string $filter = 'All'): string
    {
        $uri = "SellingItems/$filter.json";

        return $this->api('GET', $uri, $params);
    }

    /**
     * General purpose method for sending API requests.
     *
     * @param $method
     * @param $uri
     * @param $params
     *
     * @return string
     *
     * @throws RequestException
     */
    public function api($method, $uri, $params): string
    {
        return $this->request->api($method, $uri, $params);
    }

    /**
     * Gets temporary access tokens.
     *
     * The access tokens will have to be stored somewhere for future use.
     *
     * @param null|array $scopes Scopes that the token has access to
     *
     * @return array An array containing both the OAuth token and key
     * @throws RequestException
     *
     */
    public function getTemporaryAccessTokens(array $scopes = [self::SCOPE_READ, self::SCOPE_WRITE]): array
    {
        $uri = sprintf('/RequestToken?scope=%s', join(',', $scopes));

        $buildAuthorizationHeader = function () {
            $params = [
                'oauth_consumer_key' => $this->request->getOption('oauth.consumer_key'),
                'oauth_signature_method' => 'PLAINTEXT',
                'oauth_timestamp' => time(),
                'oauth_nonce' => $this->generateNonce(),
                'oauth_version' => '1.0',
                'oauth_signature' =>
                    $this->request->getOption('oauth.consumer_secret') . '&'
            ];

            foreach ($params as $key => $value) {
                $params[$key] = $key . '="' . rawurlencode($value) . '"';
            }

            return ['Authorization', 'OAuth ' . implode(', ', $params)];
        };

        [$authorization, $oauth] = $buildAuthorizationHeader();

        $headers = [];
        $headers[$authorization] = $oauth;

        $response = $this->request->oauth('POST', $uri, [], $headers);

        parse_str($response, $parsed);

        return $parsed;
    }

    /**
     * Generates a nonce
     *
     * @return string
     */
    private function generateNonce(): string
    {
        return substr(sha1(str_shuffle(__METHOD__)), 0, 10);
    }

    /**
     * Generates a URL that will generate a verifier to get the final access tokens.
     *
     * User will have to must authenticate with said URL in order get the verifier code.
     *
     * The verifier code will have to be stored somewhere for future use.
     *
     * From the API docs:
     *
     * The user is asked to sign in (if they haven’t already) and then asked whether to allow or deny your application access.
     * When the user clicks the Allow button, they will be redirected off to the callback address you provided in the first step,
     * with the oauth_token and oauth_verifier as query string parameters.
     *
     * @param string The temporary OAuth access token
     *
     * @return string
     * @see Client::getTemporaryAccessTokens()
     *
     */
    public function getAccessTokenVerifierURL(string $tempAccessToken): string
    {
        return sprintf(
            '%s/Oauth/Authorize?oauth_token=%s',
            'https://secure.' . $this->request->getBaseDomain(),
            urlencode($tempAccessToken)
        );
    }

    /**
     *  Gets final access tokens.
     *
     * Use the temporary access tokens and token verifier
     *
     * @param array $config
     * @return array
     *
     * @throws RequestException
     */
    public function getFinalAccessTokens(array $config): array
    {
        $requiredKeys = ['temp_token', 'temp_token_secret', 'token_verifier'];

        self::validateRequired($requiredKeys, $config, function (array $requiredKeys) {
            $errorMsg = sprintf(
                'To get the final access tokens, specify the following: %s.',
                join(', ', $requiredKeys)
            );

            throw new ClientException($errorMsg);
        });


        $uri = sprintf('/AccessToken?oauth_verifier=%s', urlencode($config['token_verifier']));

        $buildAuthorizationHeader = function () use ($config) {
            $params = [
                'oauth_consumer_key' => $this->request->getOption('oauth.consumer_key'),
                'oauth_token' => $config['temp_token'],
                'oauth_signature_method' => 'PLAINTEXT',
                'oauth_timestamp' => time(),
                'oauth_nonce' => $this->generateNonce(),
                'oauth_token_secret' => $config['temp_token_secret'],
                'oauth_version' => '1.0',
                'oauth_signature' =>
                    $this->request->getOption('oauth.consumer_secret')
                    . '&' .
                    $config['temp_token_secret']
            ];

            foreach ($params as $key => $value) {
                $params[$key] = $key . '="' . rawurlencode($value) . '"';
            }

            return ['Authorization', 'OAuth ' . implode(', ', $params)];
        };

        [$authorization, $oauth] = $buildAuthorizationHeader();

        $headers = [];
        $headers[$authorization] = $oauth;

        $response = $this->request->oauth('POST', $uri, [], $headers);

        parse_str($response, $accessTokens);

        return $accessTokens;
    }
}

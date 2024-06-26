<?php

declare(strict_types=1);

namespace Shawnreid\LaravelQuickbooks;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use QuickBooksOnline\API\Core\OAuth\OAuth2\OAuth2AccessToken;
use QuickBooksOnline\API\DataService\DataService;

class QuickbooksClient
{
    /**
     * Return authentication url
     */
    public function getAuthorizationUrl(): string
    {
        return $this->configureDataService()
            ->getOAuth2LoginHelper()
            ->getAuthorizationCodeURL();
    }

    /**
     * Create new token
     */
    public function createToken(Request $request): void
    {
        $token = $this->configureDataService()
            ->getOAuth2LoginHelper()
            ->exchangeAuthorizationCodeForToken(
                $request->input('code'),
                $request->input('realmId')
            );

        $model = config('laravel-quickbooks.relation.model');
        $model = (new $model())->findOrFail(session()->get('_quickbooksId'));

        $model->quickbooksToken()->delete();
        $model->quickbooksToken()->create($this->parseToken($token));

        session()->forget('_quickbooksId');
    }

    /**
     * Get data service and refresh token
     */
    public function getDataService(Model $model): DataService
    {
        if (! $model->quickbooksToken) {
            throw new \Error('Unable to configure QuickBooks Data Service. No token found.');
        }

        $dataService = $this->configureDataService([
            'accessTokenKey' => $model->quickbooksToken->access_token,
            'refreshTokenKey' => $model->quickbooksToken->refresh_token,
            'QBORealmID' => $model->quickbooksToken->realm_id,
        ]);

        if (config('laravel-quickbooks.logging.enabled')) {
            $dataService->setLogLocation(strval(config('laravel-quickbooks.logging.location')));
            $dataService->enableLog();
            $dataService->throwExceptionOnError(true);
        }

        $token = $dataService->getOAuth2LoginHelper()->refreshToken();

        $model->quickbooksToken()->update($this->parseToken($token));

        return $dataService;
    }

    /**
     * Return data service provider
     */
    public function configureDataService(array $args = []): DataService
    {
        return DataService::Configure([
            'auth_mode' => 'oauth2',
            'scope' => 'com.intuit.quickbooks.accounting',
            'ClientID' => config('laravel-quickbooks.connection.client_id'),
            'ClientSecret' => config('laravel-quickbooks.connection.client_secret'),
            'baseUrl' => config('laravel-quickbooks.connection.base_url'),
            'RedirectURI' => config('laravel-quickbooks.connection.redirect_url'),
            ...$args,
        ]);
    }

    /**
     * Parse token
     */
    public function parseToken(OAuth2AccessToken $token): array
    {
        $accessExpiry = (string) $token->getAccessTokenExpiresAt();
        $refreshExpiry = (string) $token->getRefreshTokenExpiresAt();

        return [
            'realm_id' => $token->getRealmID(),
            'access_token' => $token->getAccessToken(),
            'refresh_token' => $token->getRefreshToken(),
            'access_token_expires_at' => Carbon::parse($accessExpiry),
            'refresh_token_expires_at' => Carbon::parse($refreshExpiry),
        ];
    }
}

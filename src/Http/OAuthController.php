<?php

declare(strict_types=1);

namespace Integrations\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Integrations\Contracts\HasOAuth2;
use Integrations\Events\OAuthCompleted;
use Integrations\Events\OAuthRevoked;
use Integrations\Models\Integration;
use Integrations\Support\Config;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class OAuthController extends Controller
{
    public function authorize(int $integration): RedirectResponse
    {
        $model = Integration::findOrFail($integration);
        $provider = $model->provider();

        if (! $provider instanceof HasOAuth2) {
            throw new BadRequestHttpException('Provider does not support OAuth2.');
        }

        $state = Str::random(40);

        Cache::put(Config::cachePrefix().":oauth:state:{$state}", $model->id, Config::oauthStateTtl());

        $redirectUri = url(Config::oauthRoutePrefix().'/oauth/callback');

        $authUrl = $provider->authorizationUrl($model, $redirectUri, $state);

        return new RedirectResponse($authUrl);
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = $request->query('state');
        $code = $request->query('code');

        if (! is_string($state) || ! is_string($code)) {
            throw new BadRequestHttpException('Missing state or code parameter.');
        }

        $integrationId = Cache::pull(Config::cachePrefix().":oauth:state:{$state}");

        if (! is_int($integrationId) && ! is_string($integrationId)) {
            throw new BadRequestHttpException('Invalid or expired state parameter.');
        }

        $integration = Integration::query()->find($integrationId);

        if (! $integration instanceof Integration) {
            throw new NotFoundHttpException('Integration not found.');
        }

        $provider = $integration->provider();

        if (! $provider instanceof HasOAuth2) {
            throw new BadRequestHttpException('Provider does not support OAuth2.');
        }

        $redirectUri = url(Config::oauthRoutePrefix().'/oauth/callback');

        $tokenData = $provider->exchangeCode($integration, $code, $redirectUri);

        $integration->update([
            'credentials' => array_merge($integration->credentialsArray(), $tokenData),
        ]);

        OAuthCompleted::dispatch($integration);

        return new RedirectResponse(Config::oauthSuccessRedirect());
    }

    public function revoke(int $integration): RedirectResponse
    {
        $model = Integration::findOrFail($integration);
        $provider = $model->provider();

        if (! $provider instanceof HasOAuth2) {
            throw new BadRequestHttpException('Provider does not support OAuth2.');
        }

        $provider->revokeToken($model);

        $credentials = $model->credentialsArray();
        unset($credentials['access_token'], $credentials['refresh_token'], $credentials['token_expires_at']);

        $model->update(['credentials' => $credentials]);

        OAuthRevoked::dispatch($model);

        return new RedirectResponse(Config::oauthSuccessRedirect());
    }
}

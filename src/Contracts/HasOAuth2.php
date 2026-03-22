<?php

declare(strict_types=1);

namespace Integrations\Contracts;

use Integrations\Models\Integration;

interface HasOAuth2
{
    /**
     * Build the authorization URL to redirect the user to.
     */
    public function authorizationUrl(Integration $integration, string $redirectUri, string $state): string;

    /**
     * Exchange an authorization code for tokens.
     *
     * @return array<string, mixed> Credential data to merge (access_token, refresh_token, token_expires_at, etc.)
     */
    public function exchangeCode(Integration $integration, string $code, string $redirectUri): array;

    /**
     * Refresh an expired access token.
     *
     * @return array<string, mixed> Credential data to merge.
     */
    public function refreshToken(Integration $integration): array;

    /**
     * Revoke the current authorization.
     */
    public function revokeToken(Integration $integration): void;

    /**
     * How many seconds before expiry to trigger a refresh.
     */
    public function refreshThreshold(): int;
}

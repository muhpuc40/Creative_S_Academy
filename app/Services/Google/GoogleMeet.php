<?php

namespace App\Services\Google;

use App\Models\Admin;
use App\Models\GoogleAccount;
use App\Models\GoogleSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Meet links for live classes, made with the Google Meet REST API.
 *
 * The Google Cloud OAuth client (Client ID + secret) is saved in System > Google Meet.
 * The academy connects one Google account once there (OAuth "offline" access).
 * Its refresh token is kept; each new meeting gets a fresh access token when needed.
 * A meeting is a Meet "space": a permanent meet.google.com link that anyone with it can join.
 */
class GoogleMeet
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';
    private const SPACES_URL = 'https://meet.googleapis.com/v2/spaces';

    /** email: to show which account is connected. meetings.space.created: create Meet links. */
    private const SCOPES = 'openid email https://www.googleapis.com/auth/meetings.space.created';

    public function client(): ?GoogleSetting
    {
        return GoogleSetting::first();
    }

    /** The Client ID and secret are saved. */
    public function configured(): bool
    {
        return $this->client() !== null;
    }

    /**
     * Saves the Google Cloud client. An empty secret keeps the saved one.
     * A connected account belongs to the client it was made with, so a new Client ID disconnects it.
     * Returns true when an account was disconnected.
     */
    public function saveClient(string $clientId, ?string $secret, Admin $admin): bool
    {
        $client = $this->client() ?? new GoogleSetting();
        $disconnect = $client->exists && $client->client_id !== $clientId && $this->account() !== null;

        if ($disconnect) {
            $this->disconnect();
        }

        $client->fill(['client_id' => $clientId, 'updated_by' => $admin->id]);
        if (filled($secret)) {
            $client->client_secret = $secret;
        }
        $client->save();

        return $disconnect;
    }

    public function account(): ?GoogleAccount
    {
        return GoogleAccount::first();
    }

    /** Ready to make links: set up in .env and an account connected. */
    public function ready(): bool
    {
        return $this->configured() && GoogleAccount::exists();
    }

    /** Must be listed in Google Cloud as an "Authorized redirect URI" exactly like this. */
    public function redirectUri(): string
    {
        return route('admin.google-meet.callback');
    }

    /** Google's sign-in page. "offline" + "consent" so Google always sends a refresh token. */
    public function authUrl(string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => $this->client()?->client_id,
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /** Back from Google's sign-in: swap the code for tokens and keep the account (replaces any earlier one). */
    public function connect(string $code, Admin $admin): GoogleAccount
    {
        $tokens = $this->tokenRequest([
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
        ]);

        if (empty($tokens['refresh_token'])) {
            throw new GoogleMeetException('Google did not give long-term access. Please try connecting again.');
        }
        if (!str_contains($tokens['scope'] ?? '', 'meetings.space.created')) {
            throw new GoogleMeetException('Please allow "create Google Meet meetings" on the Google page, then try again.');
        }

        $email = $this->send(fn() => Http::withToken($tokens['access_token'])->timeout(15)->get(self::USERINFO_URL))->json('email');

        GoogleAccount::query()->delete();

        return GoogleAccount::create([
            'email' => $email ?: 'Google account',
            'refresh_token' => $tokens['refresh_token'],
            'access_token' => $tokens['access_token'],
            'access_token_expires_at' => now()->addSeconds(($tokens['expires_in'] ?? 3600) - 60),
            'connected_by' => $admin->id,
        ]);
    }

    /** Forget the account here and ask Google to cancel the access too (best effort). */
    public function disconnect(): void
    {
        $account = $this->account();
        if (!$account) {
            return;
        }

        try {
            Http::asForm()->timeout(10)->post(self::REVOKE_URL, ['token' => $account->refresh_token]);
        } catch (ConnectionException) {
            // Google not reachable: the account is still removed here
        }

        $account->delete();
    }

    /** A new Google Meet link, e.g. https://meet.google.com/abc-mnop-xyz. Anyone with the link can join. */
    public function createMeetingLink(): string
    {
        $token = $this->accessToken();
        $create = fn(array $body) => $this->send(
            fn() => Http::withToken($token)->timeout(15)->post(self::SPACES_URL, (object) $body),
            failOnError: false,
        );

        $response = $create(['config' => ['accessType' => 'OPEN']]);

        // some accounts may not set who can join: then make it with the account's default
        if ($response->status() === 400) {
            $response = $create([]);
        }

        $link = $response->successful() ? $response->json('meetingUri') : null;
        if (!$link) {
            Log::channel('app_file')
                ->warning('Google Meet link failed', ['status' => $response->status(), 'body' => $response->json() ?? $response->body()]);
            throw new GoogleMeetException($this->explain($response));
        }

        return $link;
    }

    /** The stored access token, or a fresh one when it has expired. */
    private function accessToken(): string
    {
        $account = $this->account();
        if (!$this->configured() || !$account) {
            throw new GoogleMeetException('Google Meet is not connected. An admin can connect it in System > Google Meet.');
        }

        if ($account->access_token && $account->access_token_expires_at?->isFuture()) {
            return $account->access_token;
        }

        try {
            $tokens = $this->tokenRequest(['refresh_token' => $account->refresh_token, 'grant_type' => 'refresh_token']);
        } catch (GoogleMeetException $e) {
            if (str_contains($e->getMessage(), 'invalid_grant')) {
                throw new GoogleMeetException('Google access was removed or has expired. Connect Google again in System > Google Meet.');
            }
            throw $e;
        }

        $account->update([
            'access_token' => $tokens['access_token'],
            'access_token_expires_at' => now()->addSeconds(($tokens['expires_in'] ?? 3600) - 60),
        ]);

        return $tokens['access_token'];
    }

    private function tokenRequest(array $fields): array
    {
        $client = $this->client();
        if (!$client) {
            throw new GoogleMeetException('Save the Google Client ID and secret in System > Google Meet first.');
        }

        $response = $this->send(fn() => Http::asForm()->timeout(15)->post(self::TOKEN_URL, $fields + [
            'client_id' => $client->client_id,
            'client_secret' => $client->client_secret,
        ]), failOnError: false);

        if (!$response->successful() || !$response->json('access_token')) {
            $error = $response->json('error') ?? 'http_' . $response->status();
            Log::channel('app_file')
                ->warning('Google token request failed', ['error' => $error, 'description' => $response->json('error_description')]);
            throw new GoogleMeetException("Google refused the sign-in ({$error}). Check the Client ID, Client secret and redirect URI.");
        }

        return $response->json();
    }

    /** Runs a request; a network failure becomes a readable message. */
    private function send(callable $request, bool $failOnError = true): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException) {
            throw new GoogleMeetException('Could not reach Google. Check the internet connection and try again.');
        }

        if ($failOnError && !$response->successful()) {
            throw new GoogleMeetException($this->explain($response));
        }

        return $response;
    }

    private function explain(Response $response): string
    {
        $message = $response->json('error.message') ?? $response->json('error_description') ?? '';

        return match (true) {
            $response->status() === 401 => 'Google access has expired. Connect Google again in System > Google Meet.',
            $response->status() === 403 && str_contains($message, 'has not been used') => 'The Google Meet REST API is not enabled in the Google Cloud project. Enable it and try again.',
            $response->status() === 403 => 'Google did not allow this: ' . ($message ?: 'permission denied') . '.',
            $response->status() === 429 => 'Too many new meetings in a short time. Wait a minute and try again.',
            default => 'Google could not create the meeting' . ($message ? ": {$message}" : '.') . ' Please try again.',
        };
    }
}

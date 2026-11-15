<?php
namespace App\Services\Auth;
use App\Enums\MessageChannel;
use App\Models\PasswordReset;
use App\Services\Messaging\MessageService;
use App\Support\Mask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB, RateLimiter};
use Illuminate\Support\Str;

class PasswordResetService
{
    public function setting(string $name): int { return (int) config('auth.password_reset.'.$name); }

    public function start(string $type, Request $request): PasswordReset
    {
        $provider = config('auth.password_reset.providers.'.$type);
        abort_unless($provider, 404);
        $model = config('auth.providers.'.$provider.'.model');
        $login = trim((string) $request->input('login'));
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        if ($field === 'email') { $login = strtolower($login); }
        $user = $model::where($field, $login)->first();
        if (!$user?->isActive()) { $user = null; }

        // Only a previously VERIFIED destination may recover the account.
        // A signup verifies one channel; the other contact is not proof of ownership.
        $email = $user?->verified_at && $user->verified_channel === 'email' ? $user->email : null;
        $phone = $user?->verified_at && $user->verified_channel === 'sms' ? $user->phone : null;
        return PasswordReset::create([
            'challenge_id' => Str::random(64), 'user_type' => $type, 'user_id' => $user?->id,
            'password_fingerprint' => $user ? $this->hash($user->password) : null,
            'email' => $email, 'phone' => $phone,
            'challenge_expires_at' => now()->addSeconds($this->setting('challenge_ttl')),
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);
    }

    public function options(PasswordReset $reset): array
    {
        $options = [];
        if ($reset->email) { $options[] = ['channel' => 'email', 'masked' => Mask::email($reset->email)]; }
        if ($reset->phone) { $options[] = ['channel' => 'sms', 'masked' => Mask::phone($reset->phone)]; }
        return $options;
    }

    public function sendCode(PasswordReset $reset, string $channel, MessageService $messages): array
    {
        // The row lock serializes sends, guesses and reset completion for this challenge.
        // No transaction retry around an external send: avoid duplicate messages.
        return DB::transaction(function () use ($reset, $channel, $messages) {
            $reset = PasswordReset::whereKey($reset->id)->lockForUpdate()->firstOrFail();
            if (!$this->pending($reset) || !$this->currentUser($reset)) { return $this->invalid(); }
            $to = $channel === 'email' ? $reset->email : $reset->phone;
            if (!$to) { return ['error' => 'This recovery channel is unavailable.', 'status' => 422]; }
            $next = $reset->code_sent_at?->copy()->addSeconds($this->setting('resend_after'));
            if ($next?->isFuture()) {
                return ['error' => 'Please wait before requesting another code.', 'status' => 429,
                    'retry_after' => (int) ceil(now()->diffInSeconds($next))];
            }
            // Shared across reset challenges, user types and source IPs for the same destination.
            $key = 'reset-to:'.hash('sha256', $channel.'|'.$to);
            $allowed = Cache::lock($key.':lock', 10)->block(3, function () use ($key) {
                if (RateLimiter::tooManyAttempts($key, $this->setting('max_codes_per_hour'))) { return false; }
                RateLimiter::hit($key, 3600); // Failed delivery attempts also cost quota.
                return true;
            });
            if (!$allowed) { return ['error' => 'Too many code requests. Try again later.', 'status' => 429]; }
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $messages->send(MessageChannel::from($channel), $to, 'password-reset-code', [
                'code' => $code, 'name' => $reset->user->name, 'minutes' => intdiv($this->setting('code_ttl'), 60),
            ], 'Your '.config('app.name').' password reset code');
            $reset->update(['channel' => $channel, 'code_hash' => $this->hash($code),
                'code_sent_at' => now(), 'code_expires_at' => now()->addSeconds($this->setting('code_ttl'))]);
            // A resend replaces the code, but does NOT reset the attempt counter.
            return ['message' => 'Code sent.', 'channel' => $channel,
                'sent_to' => $channel === 'email' ? Mask::email($to) : Mask::phone($to),
                'expires_in' => min($this->setting('code_ttl'), max(0, (int) now()->diffInSeconds($reset->challenge_expires_at))),
                'resend_after' => $this->setting('resend_after')];
        });
    }

    public function verifyCode(PasswordReset $reset, string $code): array
    {
        return DB::transaction(function () use ($reset, $code) {
            $reset = PasswordReset::whereKey($reset->id)->lockForUpdate()->firstOrFail();
            if (!$this->pending($reset) || !$this->currentUser($reset)) { return $this->invalid(); }
            if (!$reset->code_hash || !$reset->code_expires_at?->isFuture()) {
                return ['error' => 'Request a new verification code.', 'status' => 422];
            }
            if (!hash_equals($reset->code_hash, $this->hash($code))) {
                $reset->attempts++;
                if ($reset->attempts >= $this->setting('max_attempts')) { $reset->status = 'locked'; }
                $reset->save();
                return ['error' => 'Invalid code or too many attempts.', 'status' => $reset->status === 'locked' ? 429 : 422];
            }
            $token = Str::random(64);
            $reset->update(['status' => 'verified', 'verified_at' => now(), 'code_hash' => null,
                'reset_token_hash' => hash('sha256', $token),
                'reset_token_expires_at' => now()->addSeconds($this->setting('reset_token_ttl'))]);
            return ['reset_token' => $token, 'expires_in' => $this->setting('reset_token_ttl')];
        }, 3);
    }

    public function complete(PasswordReset $reset, string $token, string $password): array
    {
        return DB::transaction(function () use ($reset, $token, $password) {
            $reset = PasswordReset::whereKey($reset->id)->lockForUpdate()->firstOrFail();
            if ($reset->status !== 'verified' || !$reset->reset_token_expires_at?->isFuture() ||
                !$reset->reset_token_hash || !hash_equals($reset->reset_token_hash, hash('sha256', $token))) {
                return $this->invalid();
            }
            $user = $reset->user()->lockForUpdate()->first();
            if (!$this->currentUser($reset, $user)) { return $this->invalid(); }
            $user->password = $password; // Model's hashed cast hashes it once.
            $user->save();
            $user->devices()->get()->each->revoke();
            $user->tokens()->delete();
            $reset->update(['status' => 'used', 'used_at' => now(), 'reset_token_hash' => null]);
            // Other reset/login challenges carry the old password fingerprint and are now invalid.
            return ['success' => true];
        }, 3);
    }

    private function currentUser(PasswordReset $reset, $user = null): bool
    {
        $user ??= $reset->user;
        if (!$user || !$user->isActive() || !$reset->password_fingerprint ||
            !hash_equals($reset->password_fingerprint, $this->hash($user->password))) { return false; }
        $channel = $reset->channel ?? ($reset->email ? 'email' : 'sms');
        return $user->verified_at !== null && $user->verified_channel === $channel &&
            ($channel === 'email' ? $reset->email !== null && $reset->email === $user->email
                : $reset->phone !== null && $reset->phone === $user->phone);
    }
    private function pending(PasswordReset $reset): bool
    {
        return $reset->status === 'pending' && $reset->challenge_expires_at->isFuture()
            && $reset->attempts < $this->setting('max_attempts');
    }
    private function invalid(): array { return ['error' => 'Reset session is invalid or expired. Start again.', 'status' => 422]; }
    private function hash(string $value): string { return hash_hmac('sha256', $value, (string) config('app.key')); }
}

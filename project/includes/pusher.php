<?php
// Pusher Channels — REST API called directly with curl + manual HMAC signing
// (no SDK/Composer, consistent with the rest of this project). See
// https://pusher.com/docs/channels/library_auth_reference/rest-api/ for the
// wire format this implements by hand.
define('PUSHER_APP_ID', '2184568');
define('PUSHER_KEY', '1cb24a8274c1b810b793');
define('PUSHER_SECRET', '802a70614ca46b500eb1');
define('PUSHER_CLUSTER', 'eu');

function pusher_configured(): bool {
    return PUSHER_APP_ID !== '' && PUSHER_KEY !== '' && PUSHER_SECRET !== '';
}

// Publishes $data (any JSON-encodable value) as $event on $channel.
// Failure is non-fatal on purpose — chat still works via plain GET polling
// / page reload if Pusher is unreachable, not configured, or curl isn't even
// available on this hosting. Wrapped in try/catch(\Throwable) — not just
// \Exception — because a missing/disabled curl_* function is a PHP \Error in
// PHP 8 (undefined-function fatals are Throwable, not Exception), and that
// must never leak into the JSON response of whatever endpoint called this
// (e.g. messages.php sending a chat message).
function pusher_trigger(string $channel, string $event, array $data): void {
    if (!pusher_configured() || !function_exists('curl_init')) return;

    try {
        $body = json_encode([
            'name'     => $event,
            'channels' => [$channel],
            'data'     => json_encode($data),
        ]);

        $authParams = [
            'auth_key'       => PUSHER_KEY,
            'auth_timestamp' => time(),
            'auth_version'   => '1.0',
            'body_md5'       => md5($body),
        ];
        ksort($authParams);

        $path = '/apps/' . PUSHER_APP_ID . '/events';
        $stringToSign = "POST\n$path\n" . http_build_query($authParams);
        $authParams['auth_signature'] = hash_hmac('sha256', $stringToSign, PUSHER_SECRET);

        $url = 'https://api-' . PUSHER_CLUSTER . '.pusher.com' . $path . '?' . http_build_query($authParams);

        $ch = curl_init($url);
        if ($ch === false) return;

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
        ]);
        curl_exec($ch);
        curl_close($ch);
    } catch (\Throwable $e) {
        // Swallowed on purpose — see comment above the function.
    }
}

// Signs a private-channel subscription request so only the two participants
// of a conversation can subscribe to its channel. Returns null if Pusher
// isn't configured yet (frontend falls back to polling in that case).
function pusher_channel_auth(string $socketId, string $channelName): ?string {
    if (!pusher_configured()) return null;
    $signature = hash_hmac('sha256', "$socketId:$channelName", PUSHER_SECRET);
    return PUSHER_KEY . ':' . $signature;
}

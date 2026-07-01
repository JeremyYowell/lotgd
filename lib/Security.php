<?php
/**
 * Security alerting — fire-and-forget.
 * Sends an ntfy.sh push notification AND ships an event to the central log.
 * Never throws; silently no-ops if constants are not configured.
 *
 * Required constants (add to config/config.php):
 *   NTFY_TOPIC          — ntfy.sh topic (or self-hosted topic after migration)
 *   NTFY_URL            — base URL, default https://ntfy.sh
 *   LOG_INGEST_TOKEN    — plaintext token for space-monkey.org/log
 *   APP_SLUG            — short app name label for log events
 */

class Security {

    /**
     * Verify a Cloudflare Turnstile challenge response (anti-bot for public forms).
     *
     * Returns true (fail-open) ONLY when no secret is configured, so the site
     * keeps working before keys are installed. Once TURNSTILE_SECRET is set,
     * a missing/invalid token or an unreachable verify endpoint returns false
     * (fail-closed) — registration is non-critical, so blocking on doubt is safe.
     */
    public static function verifyTurnstile(): bool {
        if (!defined('TURNSTILE_SECRET') || TURNSTILE_SECRET === '') {
            return true; // not configured yet — do not block legitimate signups
        }

        $token = $_POST['cf-turnstile-response'] ?? '';
        if ($token === '') {
            return false;
        }

        $resp = @file_get_contents(
            'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            false,
            stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content'       => http_build_query([
                        'secret'   => TURNSTILE_SECRET,
                        'response' => $token,
                        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
                    ]),
                    'timeout'       => 5,
                    'ignore_errors' => true,
                ],
            ])
        );

        if ($resp === false) {
            return false; // verify endpoint unreachable — fail closed
        }

        $data = json_decode($resp, true);
        return !empty($data['success']);
    }

    public static function alert(string $title, string $message, string $priority = 'default'): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $full_message = "$message | IP: $ip";

        // ntfy.sh push
        if (defined('NTFY_TOPIC') && NTFY_TOPIC) {
            $base = defined('NTFY_URL') ? rtrim(NTFY_URL, '/') : 'https://ntfy.sh';
            @file_get_contents($base . '/' . NTFY_TOPIC, false, stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'header'        => "Title: $title\r\nPriority: $priority\r\nTags: security",
                    'content'       => $full_message,
                    'timeout'       => 3,
                    'ignore_errors' => true,
                ]
            ]));
        }

        // Central log ingest
        if (defined('LOG_INGEST_TOKEN') && LOG_INGEST_TOKEN) {
            $payload = json_encode([
                'source'          => defined('APP_SLUG') ? APP_SLUG : 'unknown',
                'environment'     => IS_PROD ? 'production' : 'dev',
                'level'           => 'warning',
                'category'        => 'security',
                'message'         => $title,
                'event_timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                'host'            => $_SERVER['HTTP_HOST'] ?? 'unknown',
                'context'         => [
                    'detail'  => $message,
                    'ip'      => $ip,
                    'ua'      => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
                ],
            ]);
            @file_get_contents('https://www.space-monkey.org/log/api/ingest.php', false, stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'header'        => "Content-Type: application/json\r\nX-Log-Token: " . LOG_INGEST_TOKEN,
                    'content'       => $payload,
                    'timeout'       => 3,
                    'ignore_errors' => true,
                ]
            ]));
        }
    }
}

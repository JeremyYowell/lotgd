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
     * The visitor's real IP address.
     *
     * lotgd.money sits behind the Cloudflare proxy, so REMOTE_ADDR is a
     * Cloudflare edge IP, not the visitor. CF-Connecting-IP carries the real
     * one and is set by the edge on every proxied request. It is only trusted
     * when the connecting peer is actually Cloudflare — otherwise a client
     * could spoof the header by hitting the DreamHost origin IP directly.
     */
    public static function clientIp(): string {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        $cf     = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';

        if ($cf !== '' && self::isCloudflarePeer($remote) && filter_var($cf, FILTER_VALIDATE_IP)) {
            return $cf;
        }

        return $remote;
    }

    /**
     * Is $ip inside a published Cloudflare edge range?
     * Ranges from https://www.cloudflare.com/ips/ — they change rarely; if a
     * new range appears the only effect is falling back to REMOTE_ADDR.
     */
    private static function isCloudflarePeer(string $ip): bool {
        static $v4 = [
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
            '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
            '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        ];
        static $v6 = [
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
            '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
        ];

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        $isV6   = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        foreach ($isV6 ? $v6 : $v4 as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $netPacked = @inet_pton($net);
            if ($netPacked === false) {
                continue;
            }

            $bytes = intdiv((int) $bits, 8);
            $rem   = (int) $bits % 8;

            if ($bytes > 0 && substr($packed, 0, $bytes) !== substr($netPacked, 0, $bytes)) {
                continue;
            }
            if ($rem > 0) {
                $mask = ~((1 << (8 - $rem)) - 1) & 0xFF;
                if ((ord($packed[$bytes]) & $mask) !== (ord($netPacked[$bytes]) & $mask)) {
                    continue;
                }
            }
            return true;
        }

        return false;
    }

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
                        'remoteip' => self::clientIp(),
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
        $ip = self::clientIp() ?: 'unknown';
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

<?php
/**
 * Security helpers. Security::alert() sends fire-and-forget events to obs
 * (helpers at the bottom of this file; needs OBS_URL + OBS_TOKEN in config/config.php).
 * Never throws.
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
        // Security events go to obs (see helpers below). 'high' priority = phone alert.
        obs_security_event('lotgd', $title, ['detail' => $message],
            in_array($priority, ['high', 'urgent', 'max'], true) ? 'error' : 'notice', self::clientIp());
    }
}

// ── Security events → obs (n8n workflow 20; Digital/AI-Agents/OBSERVABILITY_PLAN.md) ──
// Replaced the per-app ntfy topic + space-monkey.org/log ingest on 2026-11-15.
// Privacy: raw IPs, usernames and emails never leave this server. IPs are cut to their
// network (/24 or /48) and identities are masked (j***@gmail.com).
// level 'notice' = weekly digest only; 'error' = also pushes the n8n ntfy topic, at most
// once per 6 hours per kind of event. Config: OBS_URL + OBS_TOKEN (constants or env). Never throws.
if (!function_exists('obs_security_event')) {
    function obs_mask_ip(string $ip): string {
        $bin = @inet_pton($ip);
        if ($bin === false) return 'unknown';
        if (strlen($bin) === 4) return inet_ntop(substr($bin, 0, 3) . "\0") . '/24';
        return inet_ntop(substr($bin, 0, 6) . str_repeat("\0", 10)) . '/48';
    }
    function obs_mask_id(string $s): string {
        $s = trim($s);
        if ($s === '') return '(blank)';
        if (strpos($s, '@') !== false) {
            [$u, $d] = explode('@', $s, 2);
            return substr($u, 0, 1) . '***@' . $d;
        }
        return substr($s, 0, 1) . '***';
    }
    function obs_security_event(string $source, string $title, array $context = [], string $level = 'notice', string $ip = ''): void {
        try {
            $url = defined('OBS_URL') ? OBS_URL : getenv('OBS_URL');
            $tok = defined('OBS_TOKEN') ? OBS_TOKEN : getenv('OBS_TOKEN');
            if (!$url || !$tok) return;
            if ($ip === '') $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
            $context['net'] = obs_mask_ip($ip);
            $context['ua']  = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 120);
            $body = json_encode([
                'source'      => $source,
                'level'       => $level,
                'category'    => 'security',
                'message'     => $title,
                'fingerprint' => "$source|security|$title",
                'host'        => $_SERVER['HTTP_HOST'] ?? null,
                'context'     => $context,
            ]);
            @file_get_contents(rtrim($url, '/') . '/event', false, stream_context_create(['http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nX-Obs-Token: $tok\r\nUser-Agent: $source-security/1",
                'content'       => $body,
                'timeout'       => 3,
                'ignore_errors' => true,
            ]]));
        } catch (\Throwable $e) {
            // alerting must never break the request
        }
    }
}

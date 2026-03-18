<?php
/**
 * lib/Mailer.php — System email sender
 * =====================================
 * Supports two sending methods, configured via the email_driver setting:
 *
 *   'resend'  — Resend.com API (recommended, free tier = 3,000/month)
 *               Set resend_api_key in Admin → Settings
 *
 *   'php'     — PHP mail() fallback (DreamHost built-in)
 *               Works but increasingly unreliable for deliverability
 *
 * To switch to Resend:
 *   1. Sign up at resend.com (free, no credit card)
 *   2. Add and verify lotgd.money as a sending domain
 *   3. Generate an API key
 *   4. In Admin → Settings set:
 *        email_driver   = resend
 *        resend_api_key = re_xxxxxxxxxxxx
 *        email_from_address = noreply@lotgd.money
 */

class Mailer {

    private string $fromAddress;
    private string $fromName;
    private string $driver;
    private string $resendKey;

    public function __construct() {
        $db = Database::getInstance();
        $this->fromAddress = $db->getSetting('email_from_address', 'noreply@example.com');
        $this->fromName    = $db->getSetting('email_from_name',    'Legends of the Green Dollar');
        $this->driver      = $db->getSetting('email_driver',       'php');
        $this->resendKey   = $db->getSetting('resend_api_key',     '');
    }

    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function sendConfirmation(string $toEmail, string $username, string $token): bool {
        $confirmUrl = BASE_URL . '/pages/confirm_email.php?token=' . urlencode($token);
        $expHours   = Database::getInstance()->getSetting('email_confirm_token_hours', 48);
        $subject    = 'Confirm your account at Legends of the Green Dollar';

        $html = $this->wrapHtml($subject, $this->confirmationContent(
            "Welcome to the Realm, " . htmlspecialchars($username) . "!",
            "Your adventurer account has been created. Click the button below to confirm your email address and begin your financial legend.",
            $confirmUrl,
            "Confirm My Account",
            "This link expires in {$expHours} hours. If you did not create this account, you can safely ignore this email."
        ));

        return $this->send($toEmail, $subject, $html);
    }

    public function sendConfirmationResend(string $toEmail, string $username, string $token): bool {
        $confirmUrl = BASE_URL . '/pages/confirm_email.php?token=' . urlencode($token);
        $expHours   = Database::getInstance()->getSetting('email_confirm_token_hours', 48);
        $subject    = 'New confirmation link for your LotGD account';

        $html = $this->wrapHtml($subject, $this->confirmationContent(
            "New Confirmation Link",
            "Here is your new account confirmation link for " . htmlspecialchars($username) . ". Your previous link has been invalidated.",
            $confirmUrl,
            "Confirm My Account",
            "This link expires in {$expHours} hours."
        ));

        return $this->send($toEmail, $subject, $html);
    }

    // =========================================================================
    // PRIVATE: DISPATCH
    // =========================================================================

    private function send(string $toEmail, string $subject, string $htmlBody): bool {
        if ($this->driver === 'resend' && !empty($this->resendKey)) {
            return $this->sendViaResend($toEmail, $subject, $htmlBody);
        }
        return $this->sendViaPhp($toEmail, $subject, $htmlBody);
    }

    // =========================================================================
    // PRIVATE: RESEND.COM API
    // =========================================================================

    private function sendViaResend(string $toEmail, string $subject, string $htmlBody): bool {
        $fromHeader = $this->fromName
            ? $this->fromName . ' <' . $this->fromAddress . '>'
            : $this->fromAddress;

        $payload = json_encode([
            'from'    => $fromHeader,
            'to'      => [$toEmail],
            'subject' => $subject,
            'html'    => $htmlBody,
            'text'    => $this->htmlToPlainText($htmlBody),
        ]);

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->resendKey,
            ],
        ]);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            appLog('error', 'Resend curl error', ['error' => $err, 'to' => $toEmail]);
            return $this->sendViaPhp($toEmail, $subject, $htmlBody); // fallback
        }

        $response = json_decode($raw, true);

        if ($code === 200 || $code === 201) {
            appLog('info', 'Email sent via Resend', [
                'to'      => $toEmail,
                'subject' => $subject,
                'id'      => $response['id'] ?? 'unknown',
            ]);
            return true;
        }

        // Log the error and fall back to PHP mail()
        appLog('error', 'Resend API error', [
            'to'       => $toEmail,
            'subject'  => $subject,
            'http'     => $code,
            'response' => $raw,
        ]);

        appLog('warn', 'Falling back to PHP mail() after Resend failure');
        return $this->sendViaPhp($toEmail, $subject, $htmlBody);
    }

    // =========================================================================
    // PRIVATE: PHP MAIL() FALLBACK
    // =========================================================================

    private function sendViaPhp(string $toEmail, string $subject, string $htmlBody): bool {
        $fromHeader = $this->fromName
            ? '"' . addslashes($this->fromName) . '" <' . $this->fromAddress . '>'
            : $this->fromAddress;

        $plainText = $this->htmlToPlainText($htmlBody);
        $boundary  = 'LotGD_' . md5(uniqid((string)mt_rand(), true));

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $headers .= "From: {$fromHeader}\r\n";
        $headers .= "Reply-To: {$this->fromAddress}\r\n";

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode($plainText) . "\r\n\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode($htmlBody) . "\r\n\r\n";
        $body .= "--{$boundary}--";

        $params = '-f ' . escapeshellarg($this->fromAddress);
        $result = mail($toEmail, $subject, $body, $headers, $params);

        if (!$result) {
            appLog('error', 'PHP mail() failed', ['to' => $toEmail, 'subject' => $subject]);
        } else {
            appLog('info', 'Email sent via PHP mail()', ['to' => $toEmail, 'subject' => $subject]);
        }

        return $result;
    }

    // =========================================================================
    // PRIVATE: HTML HELPERS
    // =========================================================================

    private function confirmationContent(
        string $heading,
        string $body,
        string $url,
        string $btnText,
        string $footnote
    ): string {
        $domain    = parse_url($url, PHP_URL_HOST) ?? 'lotgd.money';
        $queryStr  = parse_url($url, PHP_URL_QUERY) ?? '';

        return '
        <h2 style="color:#f0d980;font-family:Georgia,serif;margin:0 0 1rem;font-size:1.25rem">'
            . $heading . '
        </h2>
        <p style="color:#c8d8e8;font-size:1rem;line-height:1.7;margin:0 0 1.5rem">'
            . $body . '
        </p>
        <div style="text-align:center;margin:2rem 0">
            <a href="' . $url . '"
               style="display:inline-block;background:#d4a017;color:#0a0d14;
                      font-family:Georgia,serif;font-weight:700;font-size:1rem;
                      padding:0.85rem 2.5rem;border-radius:6px;text-decoration:none;
                      letter-spacing:0.05em">'
                . htmlspecialchars($btnText) . '
            </a>
        </div>
        <p style="color:#6b82a0;font-size:0.85rem;line-height:1.5;margin:1.5rem 0 0">'
            . $footnote . '
        </p>
        <p style="color:#3d5070;font-size:0.78rem;margin:1rem 0 0;line-height:1.5">
            Button not working? Visit <strong style="color:#6b82a0">'
                . $domain . '/pages/confirm_email.php</strong>
            and paste your token:<br>
            <span style="font-family:monospace;color:#6b82a0;font-size:0.75rem;word-break:break-all">'
                . htmlspecialchars($queryStr) . '</span>
        </p>';
    }

    private function htmlToPlainText(string $html): string {
        $text = preg_replace('/<br\s*\/?>/i',  "\n",   $html);
        $text = preg_replace('/<\/p>/i',        "\n\n", $text);
        $text = preg_replace('/<\/h[1-6]>/i',   "\n\n", $text);
        $text = preg_replace('/<\/tr>/i',        "\n",   $text);
        $text = preg_replace('/<\/div>/i',       "\n",   $text);
        $text = preg_replace('/<a\s[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/is', '$2 ($1)', $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/',  ' ',    $text);
        $text = preg_replace('/\n{3,}/',  "\n\n", $text);
        return trim($text);
    }

    private function wrapHtml(string $title, string $content): string {
        $appName = htmlspecialchars($this->fromName);
        $year    = date('Y');
        $domain  = parse_url(BASE_URL, PHP_URL_HOST) ?? 'lotgd.money';

        return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>' . htmlspecialchars($title) . '</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:Georgia,\'Times New Roman\',serif">
    <table width="100%" cellpadding="0" cellspacing="0" role="presentation"
           style="background:#f4f4f5;padding:2rem 1rem">
        <tr><td align="center">
            <table width="560" cellpadding="0" cellspacing="0" role="presentation"
                   style="background:#111827;border:1px solid #8a6a1a;
                          border-radius:12px;overflow:hidden;max-width:560px;width:100%">
                <tr>
                    <td style="background:#16213e;padding:1.25rem 2rem;
                               border-bottom:2px solid #d4a017;text-align:center">
                        <span style="color:#f0d980;font-size:1.1rem;font-weight:700;
                                     font-family:Georgia,serif;letter-spacing:0.08em">
                            &#9876; ' . $appName . '
                        </span>
                    </td>
                </tr>
                <tr>
                    <td style="padding:2rem">
                        ' . $content . '
                    </td>
                </tr>
                <tr>
                    <td style="padding:1rem 2rem;border-top:1px solid #2a3a55;text-align:center">
                        <p style="color:#3d5070;font-size:0.75rem;margin:0;line-height:1.5">
                            You received this because an account was registered at
                            <a href="' . BASE_URL . '" style="color:#6b82a0">' . $domain . '</a>
                            &nbsp;&middot;&nbsp; &copy; ' . $year . ' ' . $appName . '
                        </p>
                    </td>
                </tr>
            </table>
        </td></tr>
    </table>
</body>
</html>';
    }
}

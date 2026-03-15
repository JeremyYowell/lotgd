<?php
/**
 * lib/Mailer.php — System email sender
 * =====================================
 * Uses PHP's built-in mail() function which works on DreamHost
 * shared hosting without any additional setup, provided the
 * from address is a real mailbox on your domain.
 *
 * IMPORTANT: Set email_from_address in the settings table to a
 * real mailbox you have created in the DreamHost panel, e.g.:
 *   noreply@yourdomain.com
 *
 * DreamHost requires the envelope sender to be a hosted address
 * or mail will be rejected or flagged as spam.
 */

class Mailer {

    private string $fromAddress;
    private string $fromName;

    public function __construct() {
        $db = Database::getInstance();
        $this->fromAddress = $db->getSetting('email_from_address', 'noreply@example.com');
        $this->fromName    = $db->getSetting('email_from_name',    'Legends of the Green Dollar');
    }

    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    /**
     * Send an email confirmation link to a newly registered user.
     */
    public function sendConfirmation(string $toEmail, string $username, string $token): bool {
        $confirmUrl = BASE_URL . '/pages/confirm_email.php?token=' . urlencode($token);
        $expHours   = Database::getInstance()->getSetting('email_confirm_token_hours', 48);

        $subject = 'Confirm your adventurer account — Legends of the Green Dollar';

        $body = $this->wrapHtml($subject, <<<HTML
        <h2 style="color:#f0d980;font-family:Georgia,serif;margin:0 0 1rem">
            Welcome to the Realm, {$username}!
        </h2>
        <p style="color:#c8d8e8;font-size:1rem;line-height:1.6;margin:0 0 1.5rem">
            Your adventurer account has been created. Click the button below to confirm
            your scroll address and begin your financial legend.
        </p>
        <div style="text-align:center;margin:2rem 0">
            <a href="{$confirmUrl}"
               style="display:inline-block;background:#d4a017;color:#0a0d14;
                      font-family:Georgia,serif;font-weight:700;font-size:1rem;
                      padding:0.85rem 2rem;border-radius:6px;text-decoration:none;
                      letter-spacing:0.05em">
                ⚔ Confirm My Account
            </a>
        </div>
        <p style="color:#6b82a0;font-size:0.85rem;line-height:1.5;margin:0">
            This link expires in {$expHours} hours. If you did not register an account,
            you can safely ignore this email.
        </p>
        <p style="color:#3d5070;font-size:0.78rem;margin:1rem 0 0;word-break:break-all">
            Or copy this link: {$confirmUrl}
        </p>
HTML
        );

        return $this->send($toEmail, $subject, $body);
    }

    /**
     * Send a new confirmation link (resend request).
     */
    public function sendConfirmationResend(string $toEmail, string $username, string $token): bool {
        $confirmUrl = BASE_URL . '/pages/confirm_email.php?token=' . urlencode($token);
        $expHours   = Database::getInstance()->getSetting('email_confirm_token_hours', 48);

        $subject = 'New confirmation link — Legends of the Green Dollar';

        $body = $this->wrapHtml($subject, <<<HTML
        <h2 style="color:#f0d980;font-family:Georgia,serif;margin:0 0 1rem">
            New Confirmation Link, {$username}
        </h2>
        <p style="color:#c8d8e8;font-size:1rem;line-height:1.6;margin:0 0 1.5rem">
            Here is your new account confirmation link. The previous link has been invalidated.
        </p>
        <div style="text-align:center;margin:2rem 0">
            <a href="{$confirmUrl}"
               style="display:inline-block;background:#d4a017;color:#0a0d14;
                      font-family:Georgia,serif;font-weight:700;font-size:1rem;
                      padding:0.85rem 2rem;border-radius:6px;text-decoration:none;
                      letter-spacing:0.05em">
                ⚔ Confirm My Account
            </a>
        </div>
        <p style="color:#6b82a0;font-size:0.85rem;line-height:1.5;margin:0">
            This link expires in {$expHours} hours.
        </p>
        <p style="color:#3d5070;font-size:0.78rem;margin:1rem 0 0;word-break:break-all">
            Or copy this link: {$confirmUrl}
        </p>
HTML
        );

        return $this->send($toEmail, $subject, $body);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Send an HTML email.
     */
    private function send(string $toEmail, string $subject, string $htmlBody): bool {
        $fromHeader = $this->fromName
            ? '"' . addslashes($this->fromName) . '" <' . $this->fromAddress . '>'
            : $this->fromAddress;

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: {$fromHeader}\r\n";
        $headers .= "Reply-To: {$this->fromAddress}\r\n";
        $headers .= "X-Mailer: LotGD/1.0\r\n";

        // DreamHost: use -f flag to set envelope sender, avoids spam flagging
        $params = '-f ' . escapeshellarg($this->fromAddress);

        $result = mail($toEmail, $subject, $htmlBody, $headers, $params);

        if (!$result) {
            appLog('error', 'mail() failed', [
                'to'      => $toEmail,
                'subject' => $subject,
            ]);
        } else {
            appLog('info', 'Email sent', ['to' => $toEmail, 'subject' => $subject]);
        }

        return $result;
    }

    /**
     * Wrap HTML content in a styled email shell.
     */
    private function wrapHtml(string $title, string $content): string {
        $appName = e($this->fromName);
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{$title}</title>
</head>
<body style="margin:0;padding:0;background:#0a0d14;font-family:Georgia,'Times New Roman',serif">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0d14;padding:2rem 1rem">
        <tr><td align="center">
            <table width="560" cellpadding="0" cellspacing="0"
                   style="background:#111827;border:1px solid #8a6a1a;
                          border-radius:12px;overflow:hidden;max-width:560px;width:100%">
                <!-- Header -->
                <tr>
                    <td style="background:linear-gradient(135deg,#1a1a2e,#16213e);
                               padding:1.5rem 2rem;border-bottom:1px solid #8a6a1a;
                               text-align:center">
                        <span style="font-size:1.5rem">⚔</span>
                        <span style="color:#f0d980;font-size:1rem;font-weight:700;
                                     letter-spacing:0.1em;margin-left:0.5rem;
                                     text-transform:uppercase">{$appName}</span>
                    </td>
                </tr>
                <!-- Body -->
                <tr>
                    <td style="padding:2rem">
                        {$content}
                    </td>
                </tr>
                <!-- Footer -->
                <tr>
                    <td style="padding:1rem 2rem;border-top:1px solid #2a3a55;
                               text-align:center">
                        <p style="color:#3d5070;font-size:0.75rem;margin:0">
                            You received this email because an account was created at {$appName}.
                        </p>
                    </td>
                </tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
HTML;
    }
}

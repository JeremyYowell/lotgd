<?php
/**
 * pages/privacy.php — Privacy Policy
 * Publicly accessible — no login required.
 */
require_once __DIR__ . '/../bootstrap.php';

$pageTitle = 'Privacy Policy';
$bodyClass = 'page-static';

ob_start();
?>

<div style="max-width:760px;margin:0 auto;padding:2rem 1rem 4rem">

    <h1 style="font-family:var(--font-heading);color:var(--color-gold);margin-bottom:0.35rem">
        Privacy Policy
    </h1>
    <p class="text-muted" style="font-size:0.88rem;margin-bottom:2.5rem">
        Legends of the Green Dollar &nbsp;·&nbsp; Effective March 25, 2026
    </p>

    <p>
        Legends of the Green Dollar (<strong>"LotGD"</strong>, <strong>"we"</strong>,
        <strong>"us"</strong>) is a small independent game. This policy explains what
        information we collect when you create an account, how we use it, and — most
        importantly — what we will never do with it.
    </p>

    <!-- ------------------------------------------------------------------ -->
    <h2>What we collect</h2>

    <ul>
        <li>
            <strong>Email address</strong> — required at registration. Used only to
            confirm your account, send password-reset links, and occasionally send
            important game-related notices (realm downtime, major updates). We do not
            send marketing email.
        </li>
        <li>
            <strong>Username</strong> — the display name you choose. Visible to other
            players on the leaderboard and in PvP.
        </li>
        <li>
            <strong>Game data</strong> — your character level, class, gold, XP,
            portfolio holdings, adventure history, PvP record, and other in-game
            state. This data exists purely to run the game.
        </li>
        <li>
            <strong>Password</strong> — stored as a one-way bcrypt hash. We cannot
            read your password, only verify it.
        </li>
    </ul>

    <p>
        We do <strong>not</strong> collect your real name, address, phone number,
        payment information, or any financial account details. The portfolio system
        uses fictional in-game currency only.
    </p>

    <!-- ------------------------------------------------------------------ -->
    <h2>How we use your information</h2>

    <ul>
        <li>To create and maintain your game account.</li>
        <li>To send transactional emails (account confirmation, password reset).</li>
        <li>To display your username and game stats to other players where the game
            requires it (leaderboard, PvP challenge screen).</li>
        <li>To troubleshoot bugs and maintain server stability.</li>
    </ul>

    <!-- ------------------------------------------------------------------ -->
    <h2>What we will never do</h2>

    <p>
        We will <strong>never</strong> sell, rent, trade, or otherwise share your
        personal information — including your email address — with any third party
        for commercial, marketing, or any other purpose. Full stop.
    </p>

    <!-- ------------------------------------------------------------------ -->
    <h2>Third-party services</h2>

    <p>
        We use a small number of external services strictly to operate the game:
    </p>

    <ul>
        <li>
            <strong>Resend</strong> (resend.com) — transactional email delivery
            (account confirmation, password reset). Your email address is passed to
            Resend solely to deliver those messages.
            <a href="https://resend.com/legal/privacy-policy" target="_blank"
               rel="noopener" style="color:var(--color-gold)">Resend Privacy Policy ↗</a>
        </li>
        <li>
            <strong>Finnhub</strong> (finnhub.io) — stock price data. No personal
            information is sent to Finnhub; requests contain only ticker symbols.
        </li>
        <li>
            <strong>ElevenLabs</strong> (elevenlabs.io) — text-to-speech for the
            optional Voice Mode feature. No personal information is sent; only
            in-game narrative text is transmitted.
        </li>
        <li>
            <strong>DreamHost</strong> — web hosting and database. Your data resides
            on DreamHost servers in the United States.
            <a href="https://www.dreamhost.com/legal/privacy-policy/" target="_blank"
               rel="noopener" style="color:var(--color-gold)">DreamHost Privacy Policy ↗</a>
        </li>
    </ul>

    <p>
        We do not use advertising networks, tracking pixels, third-party analytics,
        or social media SDKs of any kind.
    </p>

    <!-- ------------------------------------------------------------------ -->
    <h2>Cookies and sessions</h2>

    <p>
        We use a single session cookie to keep you logged in. This cookie contains
        only a random session identifier — no personal data. It expires after two
        hours of inactivity or when you log out. No third-party cookies are set by
        this site.
    </p>

    <!-- ------------------------------------------------------------------ -->
    <h2>Data retention and deletion</h2>

    <p>
        Your account data is retained for as long as your account exists. If you
        would like your account and all associated data deleted, email us at the
        address below and we will remove it promptly.
    </p>

    <!-- ------------------------------------------------------------------ -->
    <h2>Security</h2>

    <p>
        Passwords are hashed with bcrypt. The site is served exclusively over HTTPS.
        We take reasonable precautions to protect your data, though no system is
        perfectly secure and we make no absolute guarantees.
    </p>

    <!-- ------------------------------------------------------------------ -->
    <h2>Children</h2>

    <p>
        LotGD is not directed at children under 13. We do not knowingly collect
        information from anyone under 13.
    </p>

    <!-- ------------------------------------------------------------------ -->
    <h2>Changes to this policy</h2>

    <p>
        If we make material changes to this policy we will update the effective date
        at the top of this page. Continued use of the game after a change constitutes
        acceptance of the revised policy.
    </p>

    <!-- ------------------------------------------------------------------ -->
    <h2>Contact</h2>

    <p>
        Questions or deletion requests:
        <a href="mailto:jeremy@lotgd.money" style="color:var(--color-gold)">jeremy@lotgd.money</a>
    </p>

    <p style="margin-top:2.5rem;border-top:1px solid var(--color-border);padding-top:1.5rem">
        <a href="<?= BASE_URL ?>/index.php" style="color:var(--color-gold)">← Back to Legends of the Green Dollar</a>
    </p>

</div>

<?php
$pageContent = ob_get_clean();
require TPL_PATH . '/layout.php';
?>

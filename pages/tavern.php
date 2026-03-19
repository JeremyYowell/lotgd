<?php
/**
 * pages/tavern.php — The Tavern (community message board)
 */
require_once __DIR__ . '/../bootstrap.php';
Session::requireLogin();

$userModel = new User();
$userId    = Session::userId();
$user      = $userModel->findById($userId);

// =========================================================================
// CONSTANTS
// =========================================================================
define('TAVERN_MAX_LENGTH',    500);
define('TAVERN_PAGE_SIZE',     20);
define('TAVERN_POST_COOLDOWN', 60);   // seconds between posts (anti-spam)

// =========================================================================
// HANDLE POST ACTIONS
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::verifyCsrfPost();

    $action = $_POST['action'] ?? '';

    // --- POST a message ---
    if ($action === 'post') {
        $message = trim($_POST['message'] ?? '');

        if (empty($message)) {
            Session::setFlash('error', 'Your message cannot be empty.');
        } elseif (mb_strlen($message) > TAVERN_MAX_LENGTH) {
            Session::setFlash('error', 'Messages must be ' . TAVERN_MAX_LENGTH . ' characters or fewer.');
        } else {
            // Cooldown check — look at last post time for this user
            $lastPost = $db->fetchValue(
                "SELECT posted_at FROM tavern_messages
                 WHERE user_id = ? AND is_deleted = 0
                 ORDER BY posted_at DESC LIMIT 1",
                [$userId]
            );

            $cooldownPassed = true;
            if ($lastPost) {
                $secondsAgo = time() - strtotime($lastPost);
                if ($secondsAgo < TAVERN_POST_COOLDOWN) {
                    $wait = TAVERN_POST_COOLDOWN - $secondsAgo;
                    Session::setFlash('error', "Slow down, adventurer! Wait {$wait} more second(s) before posting again.");
                    $cooldownPassed = false;
                }
            }

            if ($cooldownPassed) {
                $db->run(
                    "INSERT INTO tavern_messages (user_id, message) VALUES (?, ?)",
                    [$userId, $message]
                );

                // Tavern achievement check
                $postCount = (int) $db->fetchValue(
                    "SELECT COUNT(*) FROM tavern_messages WHERE user_id = ? AND is_deleted = 0",
                    [$userId]
                );
                if ($postCount >= 10) {
                    $userModel->awardAchievement($userId, 'tavern_10');
                }

                Session::setFlash('success', 'Your words echo through the tavern.');
            }
        }
    }

    // --- DELETE own message (or admin deletes any) ---
    if ($action === 'delete') {
        $messageId = (int)($_POST['message_id'] ?? 0);

        $msg = $db->fetchOne(
            "SELECT * FROM tavern_messages WHERE id = ?", [$messageId]
        );

        if (!$msg) {
            Session::setFlash('error', 'Message not found.');
        } elseif ((int)$msg['user_id'] !== $userId && !Session::isAdmin()) {
            Session::setFlash('error', 'You cannot delete someone else\'s message.');
        } else {
            $db->run(
                "UPDATE tavern_messages SET is_deleted = 1 WHERE id = ?",
                [$messageId]
            );
            Session::setFlash('info', 'Message removed.');
        }
    }

    // --- PIN / UNPIN (admin only) ---
    if ($action === 'toggle_pin' && Session::isAdmin()) {
        $messageId = (int)($_POST['message_id'] ?? 0);
        $db->run(
            "UPDATE tavern_messages SET is_pinned = NOT is_pinned WHERE id = ?",
            [$messageId]
        );
    }

    redirect('/pages/tavern.php' . (isset($_GET['page']) ? '?page=' . (int)$_GET['page'] : ''));
}

// =========================================================================
// PAGINATION
// =========================================================================
$page       = max(1, (int)($_GET['page'] ?? 1));
$offset     = ($page - 1) * TAVERN_PAGE_SIZE;

$totalPosts = (int) $db->fetchValue(
    "SELECT COUNT(*) FROM tavern_messages WHERE is_deleted = 0 AND is_pinned = 0"
);
$totalPages = max(1, (int) ceil($totalPosts / TAVERN_PAGE_SIZE));
$page       = min($page, $totalPages);

// =========================================================================
// FETCH MESSAGES
// =========================================================================

// Pinned messages always at top (no pagination)
$pinnedMessages = $db->fetchAll(
    "SELECT m.*, u.username, u.class, u.`level`
     FROM tavern_messages m
     JOIN users u ON u.id = m.user_id
     WHERE m.is_deleted = 0 AND m.is_pinned = 1
     ORDER BY m.posted_at DESC"
);

// Regular messages — paginated, newest first
$messages = $db->fetchAll(
    "SELECT m.*, u.username, u.class, u.`level`
     FROM tavern_messages m
     JOIN users u ON u.id = m.user_id
     WHERE m.is_deleted = 0 AND m.is_pinned = 0
     ORDER BY m.posted_at DESC
     LIMIT ? OFFSET ?",
    [TAVERN_PAGE_SIZE, $offset]
);

// Total post count for display
$totalPostsAll = (int) $db->fetchValue(
    "SELECT COUNT(*) FROM tavern_messages WHERE is_deleted = 0"
);

// Unique posters today
$todayPosters = (int) $db->fetchValue(
    "SELECT COUNT(DISTINCT user_id) FROM tavern_messages
     WHERE is_deleted = 0 AND DATE(posted_at) = CURDATE()"
);

$classIcons = [
    'investor'    => '📈',
    'debt_slayer' => '🗡️',
    'saver'       => '🏦',
    'entrepreneur'=> '🚀',
    'minimalist'  => '🧘',
];

// =========================================================================
// RENDER
// =========================================================================
$pageTitle = 'The Tavern';
$bodyClass = 'page-tavern';
$extraCss  = ['tavern.css'];

ob_start();
?>

<div class="tavern-wrap">

    <!-- HEADER -->
    <div class="tavern-header">
        <div class="tavern-title-block">
            <h1>🍺 The Tavern</h1>
            <p class="text-muted">Gather, share wisdom, and celebrate victories with fellow adventurers.</p>
        </div>
        <div class="tavern-stats">
            <div class="tstat">
                <span class="tstat-val text-gold"><?= num($totalPostsAll) ?></span>
                <span class="tstat-label">Posts</span>
            </div>
            <div class="tstat">
                <span class="tstat-val text-green"><?= $todayPosters ?></span>
                <span class="tstat-label">Active Today</span>
            </div>
        </div>
    </div>

    <div class="tavern-layout">

        <!-- =====================================================
             LEFT: MESSAGE FEED
        ===================================================== -->
        <div class="tavern-feed-col">

            <!-- PINNED MESSAGES -->
            <?php if (!empty($pinnedMessages)): ?>
            <div class="pinned-section">
                <?php foreach ($pinnedMessages as $msg): ?>
                <div class="message-card pinned-card">
                    <div class="pin-label">📌 Pinned</div>
                    <div class="message-header">
                        <span class="msg-avatar"><?= $classIcons[$msg['class']] ?? '⚔️' ?></span>
                        <div class="msg-meta">
                            <a href="<?= BASE_URL ?>/pages/profile.php?user=<?= urlencode($msg['username']) ?>"
                               class="msg-username <?= $msg['user_id'] == $userId ? 'msg-me' : '' ?>">
                                <?= e($msg['username']) ?>
                            </a>
                            <span class="msg-level text-muted">Lvl <?= $msg['level'] ?></span>
                        </div>
                        <span class="msg-time text-muted"><?= timeAgo($msg['posted_at']) ?></span>
                        <?php if (Session::isAdmin()): ?>
                        <form method="POST" class="msg-action-form">
                            <?= Session::csrfField() ?>
                            <input type="hidden" name="action" value="toggle_pin">
                            <input type="hidden" name="message_id" value="<?= $msg['id'] ?>">
                            <button class="msg-action-btn" title="Unpin">📌</button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <div class="message-body"><?= nl2br(e($msg['message'])) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- REGULAR MESSAGES -->
            <?php if (empty($messages) && empty($pinnedMessages)): ?>
            <div class="tavern-empty card">
                <div class="tavern-empty-icon">🍺</div>
                <p>The tavern is quiet. Be the first to raise a toast!</p>
            </div>
            <?php else: ?>

            <div class="message-feed">
                <?php foreach ($messages as $msg):
                    $isOwn = ((int)$msg['user_id'] === $userId);
                ?>
                <div class="message-card <?= $isOwn ? 'own-message' : '' ?>">
                    <div class="message-header">
                        <span class="msg-avatar"><?= $classIcons[$msg['class']] ?? '⚔️' ?></span>
                        <div class="msg-meta">
                            <a href="<?= BASE_URL ?>/pages/profile.php?user=<?= urlencode($msg['username']) ?>"
                               class="msg-username <?= $isOwn ? 'msg-me' : '' ?>">
                                <?= e($msg['username']) ?>
                            </a>
                            <span class="msg-level text-muted">Lvl <?= $msg['level'] ?></span>
                        </div>
                        <span class="msg-time text-muted"><?= timeAgo($msg['posted_at']) ?></span>

                        <!-- Message actions -->
                        <div class="msg-actions">
                            <?php if ($isOwn || Session::isAdmin()): ?>
                            <form method="POST" class="msg-action-form"
                                  onsubmit="return confirm('Delete this message?')">
                                <?= Session::csrfField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="message_id" value="<?= $msg['id'] ?>">
                                <button class="msg-action-btn delete-btn" title="Delete">✕</button>
                            </form>
                            <?php endif; ?>
                            <?php if (Session::isAdmin()): ?>
                            <form method="POST" class="msg-action-form">
                                <?= Session::csrfField() ?>
                                <input type="hidden" name="action" value="toggle_pin">
                                <input type="hidden" name="message_id" value="<?= $msg['id'] ?>">
                                <button class="msg-action-btn pin-btn" title="Pin">📌</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="message-body"><?= nl2br(e($msg['message'])) ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- PAGINATION -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?>" class="page-btn">← Newer</a>
                <?php endif; ?>

                <div class="page-numbers">
                    <?php
                    $start = max(1, $page - 2);
                    $end   = min($totalPages, $page + 2);
                    if ($start > 1): ?>
                        <a href="?page=1" class="page-num">1</a>
                        <?php if ($start > 2): ?><span class="page-ellipsis">…</span><?php endif; ?>
                    <?php endif; ?>
                    <?php for ($p = $start; $p <= $end; $p++): ?>
                        <a href="?page=<?= $p ?>"
                           class="page-num <?= $p === $page ? 'current' : '' ?>"><?= $p ?></a>
                    <?php endfor; ?>
                    <?php if ($end < $totalPages): ?>
                        <?php if ($end < $totalPages - 1): ?><span class="page-ellipsis">…</span><?php endif; ?>
                        <a href="?page=<?= $totalPages ?>" class="page-num"><?= $totalPages ?></a>
                    <?php endif; ?>
                </div>

                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page + 1 ?>" class="page-btn">Older →</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php endif; ?>

        </div><!-- /feed-col -->

        <!-- =====================================================
             RIGHT: POST FORM + SIDEBAR
        ===================================================== -->
        <div class="tavern-sidebar">

            <!-- POST FORM -->
            <div class="card post-card">
                <h3 class="mb-2">📣 Speak Up</h3>
                <p class="text-muted mb-3" style="font-size:0.85rem;">
                    Share a win, ask for advice, or toast a fellow adventurer.
                </p>

                <?= renderFlash() ?>

                <form method="POST" id="post-form">
                    <?= Session::csrfField() ?>
                    <input type="hidden" name="action" value="post">

                    <div class="form-group">
                        <textarea
                            name="message"
                            id="message"
                            rows="4"
                            maxlength="<?= TAVERN_MAX_LENGTH ?>"
                            placeholder="What's on your mind, adventurer?"
                            required
                        ></textarea>
                        <div class="char-counter">
                            <span id="char-count">0</span> / <?= TAVERN_MAX_LENGTH ?>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-full">
                        🍺 Post to Tavern
                    </button>
                </form>
            </div>

            <!-- TAVERN RULES -->
            <div class="card rules-card">
                <h3 class="mb-2">📜 Tavern Code</h3>
                <ul class="rules-list">
                    <li>Keep it respectful — we're all on the same quest</li>
                    <li>Share real wins, real struggles, real tips</li>
                    <li>No spam — <?= TAVERN_POST_COOLDOWN ?>s between posts</li>
                    <li>Max <?= TAVERN_MAX_LENGTH ?> characters per message</li>
                    <li>Admins may remove posts that break the code</li>
                </ul>
            </div>

            <!-- RECENT ACTIVITY (top posters today) -->
            <?php
            $topToday = $db->fetchAll(
                "SELECT u.username, u.class, COUNT(*) AS posts
                 FROM tavern_messages m
                 JOIN users u ON u.id = m.user_id
                 WHERE m.is_deleted = 0 AND DATE(m.posted_at) = CURDATE()
                 GROUP BY m.user_id, u.username, u.class
                 ORDER BY posts DESC
                 LIMIT 5"
            );
            if (!empty($topToday)):
            ?>
            <div class="card sidebar-card">
                <h3 class="mb-2">🔥 Active Today</h3>
                <div class="active-list">
                    <?php foreach ($topToday as $tp): ?>
                    <div class="active-row">
                        <span><?= $classIcons[$tp['class']] ?? '⚔️' ?></span>
                        <a href="<?= BASE_URL ?>/pages/profile.php?user=<?= urlencode($tp['username']) ?>" class="active-name"><?= e($tp['username']) ?></a>
                        <span class="active-posts text-muted"><?= $tp['posts'] ?> post<?= $tp['posts'] != 1 ? 's' : '' ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /sidebar -->

    </div><!-- /layout -->

</div><!-- /tavern-wrap -->

<?php
$pageContent = ob_get_clean();

$extraScripts = <<<JS
<script>
// Character counter
const msgEl    = document.getElementById('message');
const countEl  = document.getElementById('char-count');
const maxLen   = <?= TAVERN_MAX_LENGTH ?>;

if (msgEl && countEl) {
    msgEl.addEventListener('input', () => {
        const len = msgEl.value.length;
        countEl.textContent = len;
        countEl.style.color = len > maxLen * 0.9
            ? (len >= maxLen ? '#ef4444' : '#f59e0b')
            : '';
    });
}
</script>
JS;

require TPL_PATH . '/layout.php';

// =========================================================================
// HELPER: human-readable time ago
// =========================================================================
function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    return match(true) {
        $diff < 60     => 'just now',
        $diff < 3600   => (int)($diff / 60) . 'm ago',
        $diff < 86400  => (int)($diff / 3600) . 'h ago',
        $diff < 604800 => (int)($diff / 86400) . 'd ago',
        default        => date('M j', strtotime($datetime)),
    };
}
?>

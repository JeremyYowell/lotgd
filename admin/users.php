<?php
/**
 * admin/users.php — User Management
 */
require_once __DIR__ . '/../bootstrap.php';
Session::requireAdmin();

// =========================================================================
// HANDLE ACTIONS
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::verifyCsrfPost();

    $action   = $_POST['action']  ?? '';
    $targetId = (int)($_POST['user_id'] ?? 0);

    // Give actions are allowed on any valid user (including self, for testing)
    if ($targetId && $action === 'give_gold') {
        $allowed = [50, 100, 500, 5000];
        $amount  = (int)($_POST['amount'] ?? 0);
        if (in_array($amount, $allowed, true)) {
            $db->run("UPDATE users SET gold = gold + ? WHERE id = ?", [$amount, $targetId]);
            $uname = $db->fetchValue("SELECT username FROM users WHERE id = ?", [$targetId]);
            Session::setFlash('success', number_format($amount) . ' Gold granted to ' . $uname . '.');
        } else {
            Session::setFlash('error', 'Invalid gold amount.');
        }
        redirect('/admin/users.php' . (isset($_GET['page']) ? '?page=' . (int)$_GET['page'] : ''));
    }

    if ($targetId && $action === 'give_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        if ($itemId) {
            $result = Store::adminGiveItem($targetId, $itemId);
            Session::setFlash($result['success'] ? 'success' : 'error', $result['message']);
        } else {
            Session::setFlash('error', 'No item selected.');
        }
        redirect('/admin/users.php' . (isset($_GET['page']) ? '?page=' . (int)$_GET['page'] : ''));
    }

    if ($targetId && $targetId !== Session::userId()) {

        if ($action === 'ban') {
            $reason = trim($_POST['ban_reason'] ?? 'Admin action');
            $db->run("UPDATE users SET is_banned = 1, ban_reason = ? WHERE id = ?", [$reason, $targetId]);
            Session::setFlash('success', 'User banned.');
        }

        if ($action === 'unban') {
            $db->run("UPDATE users SET is_banned = 0, ban_reason = NULL WHERE id = ?", [$targetId]);
            Session::setFlash('success', 'User unbanned.');
        }

        if ($action === 'confirm_email') {
            $db->run("UPDATE users SET email_confirmed = 1, confirm_token = NULL, confirm_token_exp = NULL WHERE id = ?", [$targetId]);
            Session::setFlash('success', 'Email manually confirmed.');
        }

        if ($action === 'delete') {
            $username = $db->fetchValue("SELECT username FROM users WHERE id = ?", [$targetId]);
            $db->run("DELETE FROM users WHERE id = ?", [$targetId]);
            $db->run("DELETE FROM leaderboard_cache WHERE username = ?", [$username]);
            $db->run("DELETE FROM portfolio_leaderboard_cache WHERE username = ?", [$username]);
            Session::setFlash('success', "User '{$username}' deleted.");
        }

        if ($action === 'resend_confirm') {
            $user = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$targetId]);
            if ($user && !$user['email_confirmed']) {
                $token  = bin2hex(random_bytes(32));
                $expiry = date('Y-m-d H:i:s', time() + (48 * 3600));
                $db->run("UPDATE users SET confirm_token = ?, confirm_token_exp = ? WHERE id = ?", [$token, $expiry, $targetId]);
                $mailer = new Mailer();
                $mailer->sendConfirmationResend($user['email'], $user['username'], $token);
                Session::setFlash('success', 'Confirmation email resent.');
            }
        }
    } elseif ($targetId === Session::userId()) {
        Session::setFlash('error', 'You cannot perform that action on your own account.');
    }

    redirect('/admin/users.php' . (isset($_GET['page']) ? '?page=' . (int)$_GET['page'] : ''));
}

// =========================================================================
// DATA
// =========================================================================
$search  = trim($_GET['q'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;

$where  = '';
$params = [];
if ($search) {
    $where    = "WHERE username LIKE ? OR email LIKE ?";
    $params   = ["%{$search}%", "%{$search}%"];
}

$total = (int) $db->fetchValue("SELECT COUNT(*) FROM users {$where}", $params);
$pages = max(1, (int) ceil($total / $perPage));

$users = $db->fetchAll(
    "SELECT id, username, email, class, `level`, xp, gold,
            email_confirmed, is_banned, ban_reason, is_admin,
            created_at, last_login, login_streak
     FROM users {$where}
     ORDER BY created_at DESC
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

// Load store catalog for Give Item dropdown
$store    = new Store();
$catalog  = $store->getCatalog();
$catalogByCategory = [];
foreach ($catalog as $it) {
    $catalogByCategory[$it['category']][] = $it;
}

// Pre-fetch inventory for all users on this page
$userIds = array_column($users, 'id');
$inventoryByUser = [];
if ($userIds) {
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $invRows = $db->fetchAll(
        "SELECT ui.user_id, si.name, si.category, si.slot, ui.quantity
         FROM user_inventory ui
         JOIN store_items si ON si.id = ui.item_id
         WHERE ui.user_id IN ({$placeholders})
         ORDER BY si.category, si.sort_order",
        $userIds
    );
    foreach ($invRows as $row) {
        $inventoryByUser[$row['user_id']][] = $row;
    }
}

$pageTitle = 'User Management';
$bodyClass = 'page-admin';
$extraCss  = ['admin.css'];

ob_start();
?>

<div class="admin-wrap">
    <div class="admin-header">
        <div>
            <a href="<?= BASE_URL ?>/admin/index.php" class="admin-back">← Admin</a>
            <h1>👤 User Management</h1>
        </div>
        <span class="text-muted" style="font-size:0.85rem"><?= num($total) ?> users</span>
    </div>

    <!-- SEARCH -->
    <form method="GET" class="admin-search-form">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search username or email…">
        <button type="submit" class="btn btn-secondary">Search</button>
        <?php if ($search): ?>
            <a href="?" class="btn btn-secondary">Clear</a>
        <?php endif; ?>
    </form>

    <?= renderFlash() ?>

    <!-- USER TABLE -->
    <div class="card" style="padding:0;overflow:hidden">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Email</th>
                    <th>Level</th>
                    <th>Gold</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr class="<?= $u['is_banned'] ? 'row-banned' : '' ?>">
                    <td>
                        <strong><?= e($u['username']) ?></strong>
                        <?php if ($u['is_admin']): ?><span class="admin-tag">admin</span><?php endif; ?>
                        <br><small class="text-muted"><?= ucfirst(str_replace('_',' ',$u['class'])) ?></small>
                    </td>
                    <td>
                        <small><?= e($u['email']) ?></small><br>
                        <?php if (!$u['email_confirmed']): ?>
                            <span class="status-tag tag-warn">Unconfirmed</span>
                        <?php else: ?>
                            <span class="status-tag tag-ok">Confirmed</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $u['level'] ?></td>
                    <td><?= number_format((float)$u['gold'], 0) ?></td>
                    <td>
                        <?php if ($u['is_banned']): ?>
                            <span class="status-tag tag-bad">Banned</span>
                            <small class="text-muted d-block"><?= e($u['ban_reason'] ?? '') ?></small>
                        <?php else: ?>
                            <span class="status-tag tag-ok">Active</span>
                        <?php endif; ?>
                    </td>
                    <td><small class="text-muted"><?= date('M j, Y', strtotime($u['created_at'])) ?></small></td>
                    <td>
                        <?php if ($u['id'] !== Session::userId()): ?>
                        <div class="admin-action-row">
                            <button class="btn-admin-action give-btn"
                                    data-user-id="<?= $u['id'] ?>"
                                    data-username="<?= e($u['username']) ?>"
                                    type="button">🎁 Give</button>
                            <?php if (!$u['email_confirmed']): ?>
                            <form method="POST">
                                <?= Session::csrfField() ?>
                                <input type="hidden" name="action"  value="confirm_email">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <button class="btn-admin-action">✓ Confirm</button>
                            </form>
                            <form method="POST">
                                <?= Session::csrfField() ?>
                                <input type="hidden" name="action"  value="resend_confirm">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <button class="btn-admin-action">📧 Resend</button>
                            </form>
                            <?php endif; ?>

                            <?php if ($u['is_banned']): ?>
                            <form method="POST">
                                <?= Session::csrfField() ?>
                                <input type="hidden" name="action"  value="unban">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <button class="btn-admin-action">Unban</button>
                            </form>
                            <?php else: ?>
                            <form method="POST" onsubmit="return confirm('Ban this user?')">
                                <?= Session::csrfField() ?>
                                <input type="hidden" name="action"     value="ban">
                                <input type="hidden" name="user_id"    value="<?= $u['id'] ?>">
                                <input type="hidden" name="ban_reason" value="Admin ban">
                                <button class="btn-admin-action danger">Ban</button>
                            </form>
                            <?php endif; ?>

                            <form method="POST" onsubmit="return confirm('Permanently delete this user and all their data?')">
                                <?= Session::csrfField() ?>
                                <input type="hidden" name="action"  value="delete">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <button class="btn-admin-action danger">Delete</button>
                            </form>
                        </div>
                        <?php else: ?>
                            <small class="text-muted">You</small>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- PAGINATION -->
    <?php if ($pages > 1): ?>
    <div class="pagination mt-3" style="justify-content:center">
        <?php for ($p = 1; $p <= $pages; $p++): ?>
            <a href="?page=<?= $p ?><?= $search ? '&q=' . urlencode($search) : '' ?>"
               class="page-num <?= $p === $page ? 'current' : '' ?>"><?= $p ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>

</div>

<!-- GIVE MODAL -->
<div id="give-modal-backdrop" class="give-modal-backdrop" style="display:none" role="dialog" aria-modal="true">
    <div class="give-modal">
        <div class="give-modal-header">
            <span id="give-modal-title" class="give-modal-username"></span>
            <button type="button" class="give-modal-close" id="give-modal-close" aria-label="Close">✕</button>
        </div>

        <!-- Inventory -->
        <div class="give-modal-inv-label">Current Inventory</div>
        <div id="give-modal-inventory" class="give-modal-inventory"></div>

        <!-- Tabs -->
        <div class="give-tabs">
            <button type="button" class="give-tab active" data-tab="gold">💰 Gold</button>
            <button type="button" class="give-tab" data-tab="item">🛡️ Item</button>
        </div>

        <!-- Gold panel -->
        <div class="give-panel" id="give-panel-gold">
            <form method="POST" class="give-form">
                <?= Session::csrfField() ?>
                <input type="hidden" name="action"  value="give_gold">
                <input type="hidden" name="user_id" class="modal-user-id" value="">
                <div class="give-row">
                    <select name="amount" class="give-select">
                        <option value="50">50 Gold</option>
                        <option value="100">100 Gold</option>
                        <option value="500">500 Gold</option>
                        <option value="5000">5,000 Gold</option>
                    </select>
                    <button type="submit" class="btn btn-primary give-submit">Grant</button>
                </div>
            </form>
        </div>

        <!-- Item panel -->
        <div class="give-panel" id="give-panel-item" style="display:none">
            <form method="POST" class="give-form">
                <?= Session::csrfField() ?>
                <input type="hidden" name="action"  value="give_item">
                <input type="hidden" name="user_id" class="modal-user-id" value="">
                <div class="give-row">
                    <select name="item_id" class="give-select">
                        <?php foreach ($catalogByCategory as $cat => $items): ?>
                        <optgroup label="<?= ucfirst($cat) ?>">
                            <?php foreach ($items as $it): ?>
                            <option value="<?= $it['id'] ?>"><?= e($it['name']) ?> (Lv<?= $it['level_req'] ?>)</option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary give-submit">Grant</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$pageContent = ob_get_clean();

// Inventory data for JS
$invJs = [];
foreach ($inventoryByUser as $uid => $items) {
    $invJs[$uid] = array_map(fn($r) => [
        'name'     => $r['name'],
        'category' => $r['category'],
        'slot'     => $r['slot'],
        'qty'      => (int)$r['quantity'],
    ], $items);
}

$extraScripts = '<script>
(function () {
    var inv = ' . json_encode($invJs) . ';
    var backdrop = document.getElementById("give-modal-backdrop");
    var closeBtn = document.getElementById("give-modal-close");

    document.querySelectorAll(".give-btn").forEach(function (btn) {
        btn.addEventListener("click", function () {
            var uid      = this.dataset.userId;
            var uname    = this.dataset.username;

            // Set username in header
            document.getElementById("give-modal-title").textContent = "Give to " + uname;

            // Set user_id in all hidden inputs
            backdrop.querySelectorAll(".modal-user-id").forEach(function (el) {
                el.value = uid;
            });

            // Render inventory
            var invEl  = document.getElementById("give-modal-inventory");
            var items  = inv[uid] || [];
            if (items.length === 0) {
                invEl.innerHTML = "<em class=\"give-inv-empty\">No items</em>";
            } else {
                invEl.innerHTML = items.map(function (it) {
                    var label = it.category === "consumable"
                        ? it.name + " ×" + it.qty
                        : it.name + " <span class=\"give-inv-slot\">(" + it.slot + ")</span>";
                    return "<span class=\"give-inv-item\">" + label + "</span>";
                }).join("");
            }

            // Reset to gold tab
            showTab("gold");
            backdrop.style.display = "flex";
        });
    });

    closeBtn.addEventListener("click", closeModal);
    backdrop.addEventListener("click", function (e) {
        if (e.target === backdrop) closeModal();
    });
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") closeModal();
    });

    function closeModal() {
        backdrop.style.display = "none";
    }

    document.querySelectorAll(".give-tab").forEach(function (tab) {
        tab.addEventListener("click", function () {
            showTab(this.dataset.tab);
        });
    });

    function showTab(name) {
        document.querySelectorAll(".give-tab").forEach(function (t) {
            t.classList.toggle("active", t.dataset.tab === name);
        });
        document.getElementById("give-panel-gold").style.display = name === "gold" ? "" : "none";
        document.getElementById("give-panel-item").style.display = name === "item" ? "" : "none";
    }
}());
</script>';

require TPL_PATH . '/layout.php';
?>

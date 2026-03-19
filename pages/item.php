<?php
/**
 * pages/item.php — Public item profile page
 */
require_once __DIR__ . '/../bootstrap.php';
Session::requireLogin();

$itemId = (int)($_GET['id'] ?? 0);
if (!$itemId) {
    redirect('/pages/store.php');
}

$item = $db->fetchOne(
    "SELECT * FROM store_items WHERE id = ? AND is_active = 1",
    [$itemId]
);

if (!$item) {
    Session::setFlash('error', 'Item not found.');
    redirect('/pages/store.php');
}

// How many players currently have this equipped / in inventory
$equippedCount = (int)$db->fetchValue(
    "SELECT COUNT(*) FROM user_inventory ui
     JOIN users u ON u.id = ui.user_id
     WHERE ui.item_id = ? AND ui.equipped = 1 AND u.is_banned = 0",
    [$itemId]
);

// Players who have this item equipped (for non-consumables)
$equippedBy = [];
if ($item['category'] !== 'consumable') {
    $equippedBy = $db->fetchAll(
        "SELECT u.username, u.class, u.`level`
         FROM user_inventory ui
         JOIN users u ON u.id = ui.user_id
         WHERE ui.item_id = ? AND ui.equipped = 1 AND u.is_banned = 0
         ORDER BY u.`level` DESC, u.username ASC
         LIMIT 20",
        [$itemId]
    );
}

// Current user's inventory status
$userId    = Session::userId();
$userOwns  = $db->fetchOne(
    "SELECT * FROM user_inventory WHERE user_id = ? AND item_id = ?",
    [$userId, $itemId]
);
$userEquipped = $userOwns && (int)$userOwns['equipped'] === 1;

$categoryMeta = [
    'shopping'   => ['icon' => '🛒', 'label' => 'Shopping'],
    'work'       => ['icon' => '💼', 'label' => 'Work'],
    'banking'    => ['icon' => '🏦', 'label' => 'Banking'],
    'investing'  => ['icon' => '📈', 'label' => 'Investing'],
    'housing'    => ['icon' => '🏠', 'label' => 'Housing'],
    'daily_life' => ['icon' => '☀️', 'label' => 'Daily Life'],
    null         => ['icon' => '⚔',  'label' => 'All categories'],
];

$categoryIcon  = $categoryMeta[$item['effect_category']] ?? $categoryMeta[null];

$slotLabels = ['tool' => 'Tool', 'armor' => 'Armor', 'weapon' => 'Weapon', 'consumable' => 'Consumable'];
$slotIcons  = ['tool' => '🔧', 'armor' => '🛡️', 'weapon' => '⚔️', 'consumable' => '⚗️'];

$effectDescriptions = [
    'roll_bonus'        => '+' . (int)$item['effect_value'] . ' to ' . ($item['effect_category'] ? ucfirst($item['effect_category']) : 'all') . ' rolls',
    'failure_reduction' => '-' . (int)((float)$item['effect_value'] * 100) . '% Gold penalty on ' . ($item['effect_category'] ? ucfirst($item['effect_category']) . ' ' : '') . 'failures',
    'xp_boost'          => '+' . (int)((float)$item['effect_value'] * 100) . '% XP on ' . ($item['effect_category'] ? ucfirst($item['effect_category']) . ' ' : '') . 'successes',
    'action_restore'    => 'Restores ' . (int)$item['effect_value'] . ' daily adventure actions',
    'roll_boost_once'   => '+' . (int)$item['effect_value'] . ' to your very next adventure roll',
    'reroll_once'       => 'Re-roll most recent failed adventure (once per day)',
];

$effectText = $effectDescriptions[$item['effect_type']] ?? $item['effect_type'];

$classIcons = [
    'investor'    => '📈',
    'debt_slayer' => '🗡️',
    'saver'       => '🏦',
    'entrepreneur'=> '🚀',
    'minimalist'  => '🧘',
];

$pageTitle = e($item['name']);
$bodyClass = 'page-item';
$extraCss  = ['profile.css'];

ob_start();
?>

<div class="profile-wrap">

    <!-- ITEM HEADER -->
    <div class="card profile-header-card item-header-card">
        <div class="item-header-icon">
            <?= $slotIcons[$item['slot']] ?? '⚔' ?>
        </div>
        <div class="profile-identity">
            <h1 class="profile-username"><?= e($item['name']) ?></h1>
            <div class="profile-class">
                <?= $slotLabels[$item['slot']] ?? $item['slot'] ?>
                &nbsp;·&nbsp;
                <?= $categoryIcon['icon'] ?> <?= $item['effect_category'] ? ucfirst($item['effect_category']) . ' bonus' : 'All categories' ?>
            </div>
            <?php if ($item['flavor_text']): ?>
            <div class="item-flavor-profile text-muted">
                "<?= e($item['flavor_text']) ?>"
            </div>
            <?php endif; ?>
        </div>
        <div class="item-header-meta">
            <div class="item-price-large text-gold">
                <?= number_format($item['price']) ?> 🪙
            </div>
            <div class="text-muted" style="font-size:0.8rem;font-family:var(--font-heading);letter-spacing:0.06em">
                Level <?= $item['level_req'] ?>+ required
            </div>
            <?php if ($userEquipped): ?>
            <div class="item-yours-tag">✔ You have this equipped</div>
            <?php elseif ($userOwns): ?>
            <div class="item-yours-tag" style="color:var(--color-green)">✔ In your inventory</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="profile-grid">
        <div class="profile-col-left">

            <!-- ITEM DETAILS -->
            <div class="card profile-section">
                <h3 class="profile-section-title">📋 Item Details</h3>
                <p style="color:var(--color-text);margin-bottom:1.5rem;line-height:1.6">
                    <?= e($item['description']) ?>
                </p>

                <div class="item-detail-grid">
                    <div class="item-detail-row">
                        <span class="item-detail-key text-muted">Effect</span>
                        <span class="item-detail-val text-gold"><?= e($effectText) ?></span>
                    </div>
                    <div class="item-detail-row">
                        <span class="item-detail-key text-muted">Slot</span>
                        <span class="item-detail-val"><?= $slotIcons[$item['slot']] ?> <?= $slotLabels[$item['slot']] ?></span>
                    </div>
                    <div class="item-detail-row">
                        <span class="item-detail-key text-muted">Category</span>
                        <span class="item-detail-val"><?= $categoryIcon['icon'] ?> <?= $item['effect_category'] ? ucfirst($item['effect_category']) : 'All categories' ?></span>
                    </div>
                    <div class="item-detail-row">
                        <span class="item-detail-key text-muted">Price</span>
                        <span class="item-detail-val text-gold"><?= number_format($item['price']) ?> 🪙 Gold</span>
                    </div>
                    <div class="item-detail-row">
                        <span class="item-detail-key text-muted">Level Required</span>
                        <span class="item-detail-val">Level <?= $item['level_req'] ?>+</span>
                    </div>
                    <?php if ($item['category'] === 'consumable'): ?>
                    <div class="item-detail-row">
                        <span class="item-detail-key text-muted">Type</span>
                        <span class="item-detail-val">Consumable — one-time use, replenishes daily</span>
                    </div>
                    <?php else: ?>
                    <div class="item-detail-row">
                        <span class="item-detail-key text-muted">Type</span>
                        <span class="item-detail-val">Permanent — replaces current <?= $item['slot'] ?> slot item</span>
                    </div>
                    <?php endif; ?>
                </div>

                <div style="margin-top:1.5rem">
                    <a href="<?= BASE_URL ?>/pages/store.php" class="btn btn-primary">
                        🏪 Visit the Store
                    </a>
                </div>
            </div>

        </div>

        <div class="profile-col-right">

            <!-- WHO HAS THIS EQUIPPED -->
            <div class="card profile-section">
                <h3 class="profile-section-title">
                    👥 Equipped By
                    <span class="achievement-count text-muted"><?= $equippedCount ?></span>
                </h3>

                <?php if (empty($equippedBy)): ?>
                    <p class="text-muted" style="font-size:0.9rem">
                        <?= $item['category'] === 'consumable'
                            ? 'Consumables are used rather than equipped.'
                            : 'No adventurers currently have this item equipped.' ?>
                    </p>
                <?php else: ?>
                <div class="equipped-by-list">
                    <?php foreach ($equippedBy as $holder): ?>
                    <div class="equipped-by-row">
                        <span class="equipped-by-icon"><?= $classIcons[$holder['class']] ?? '⚔️' ?></span>
                        <a href="<?= BASE_URL ?>/pages/profile.php?user=<?= urlencode($holder['username']) ?>"
                           class="equipped-by-name">
                            <?= e($holder['username']) ?>
                        </a>
                        <span class="equipped-by-level text-muted">Lvl <?= $holder['level'] ?></span>
                    </div>
                    <?php endforeach; ?>
                    <?php if ($equippedCount > 20): ?>
                    <p class="text-muted" style="font-size:0.8rem;margin-top:0.5rem">
                        +<?= $equippedCount - 20 ?> more adventurers
                    </p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- RARITY CONTEXT -->
            <div class="card profile-section">
                <h3 class="profile-section-title">📊 Rarity</h3>
                <?php
                $totalPlayers = (int)$db->fetchValue("SELECT COUNT(*) FROM users WHERE is_banned = 0");
                $pct = $totalPlayers > 0 ? round(($equippedCount / $totalPlayers) * 100, 1) : 0;
                $rarity = match(true) {
                    $pct === 0.0      => ['label' => 'Unclaimed',  'color' => '#f0d980'],
                    $pct < 5          => ['label' => 'Rare',        'color' => '#a78bfa'],
                    $pct < 20         => ['label' => 'Uncommon',    'color' => '#22c55e'],
                    default           => ['label' => 'Common',      'color' => '#6b82a0'],
                };
                ?>
                <div style="text-align:center;padding:1rem 0">
                    <div style="font-family:var(--font-display);font-size:1.4rem;
                                color:<?= $rarity['color'] ?>;margin-bottom:0.25rem">
                        <?= $rarity['label'] ?>
                    </div>
                    <div class="text-muted" style="font-size:0.85rem">
                        <?= $equippedCount ?> of <?= $totalPlayers ?> players
                        (<?= $pct ?>%) have this equipped
                    </div>
                </div>
            </div>

        </div>
    </div>

</div>

<?php
$pageContent = ob_get_clean();
require TPL_PATH . '/layout.php';
?>

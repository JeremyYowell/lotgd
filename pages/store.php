<?php
/**
 * pages/store.php — Item Store
 */
require_once __DIR__ . '/../bootstrap.php';
Session::requireLogin();

if (!(bool)$db->getSetting('store_enabled', '1')) {
    Session::setFlash('info', 'The store is not yet open.');
    redirect('/pages/dashboard.php');
}

$userModel = new User();
$store     = new Store();
$userId    = Session::userId();
$user      = $userModel->findById($userId);

// =========================================================================
// HANDLE ACTIONS
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::verifyCsrfPost();

    $action = $_POST['action'] ?? '';
    $itemId = (int)($_POST['item_id'] ?? 0);

    if ($action === 'buy' && $itemId) {
        $freshUser = $userModel->findById($userId);
        $result    = $store->purchase($userId, $itemId, $freshUser);

        if ($result['success']) {
            Session::setFlash('success', $result['message']);
        } else {
            Session::setFlash('error', $result['error']);
        }
    }

    if ($action === 'use' && $itemId) {
        // Preflight for reroll_once: verify there is actually a recent failure to re-roll
        // before calling useConsumable (which would mark the daily state as used).
        $effectType = $db->fetchValue(
            "SELECT si.effect_type FROM user_inventory ui
             JOIN store_items si ON si.id = ui.item_id
             WHERE ui.user_id = ? AND ui.item_id = ? AND ui.quantity > 0 LIMIT 1",
            [$userId, $itemId]
        );
        if ($effectType === 'reroll_once') {
            $lastAdv = $db->fetchOne(
                "SELECT al.id FROM adventure_log al
                 WHERE al.user_id = ? ORDER BY al.adventured_at DESC LIMIT 1",
                [$userId]
            );
            if (!$lastAdv || !in_array(
                $db->fetchValue("SELECT outcome FROM adventure_log WHERE id = ?", [$lastAdv['id']]),
                ['failure', 'crit_failure']
            )) {
                Session::setFlash('error', 'No recent failure to re-roll. Your scroll remains safely in your satchel.');
                redirect('/pages/store.php');
            }
        }

        $result = $store->useConsumable($userId, $itemId);

        if (!$result['success']) {
            Session::setFlash('error', $result['error']);
        } else {
            // Apply the effect
            switch ($result['effect_type']) {
                case 'action_restore':
                    $toAdd = (int)$result['effect_value'];
                    $limit = (int)$db->getSetting('daily_action_limit', 10);
                    $daily = $userModel->getDailyState($userId);
                    $newVal = min($limit, $daily['actions_remaining'] + $toAdd);
                    $db->run(
                        "UPDATE daily_state SET actions_remaining = ?
                         WHERE user_id = ? AND state_date = CURDATE()",
                        [$newVal, $userId]
                    );
                    Session::setFlash('success',
                        $result['item_name'] . ' used! +' . $toAdd . ' actions restored.'
                    );
                    break;

                case 'roll_boost_once':
                    Session::set('roll_boost_once', (int)$result['effect_value']);
                    Session::setFlash('success',
                        $result['item_name'] . ' used! +' . (int)$result['effect_value'] .
                        ' to your next adventure roll.'
                    );
                    break;

                case 'reroll_once':
                    // Preflight already verified a recent failure exists above.
                    // Store.php::useConsumable() marked daily state used WITHOUT consuming inventory.
                    $lastAdv = $db->fetchOne(
                        "SELECT id FROM adventure_log WHERE user_id = ?
                         ORDER BY adventured_at DESC LIMIT 1",
                        [$userId]
                    );
                    Session::set('reroll_pending', $lastAdv['id']);
                    Session::setFlash('success',
                        'Second Chance Scroll activated! Head to Adventure — your last failure awaits a second chance.'
                    );
                    break;
            }
        }
    }

    // Reload user after any gold change
    $user = $userModel->findById($userId);
    redirect('/pages/store.php');
}

// =========================================================================
// DATA
// =========================================================================
$catalog    = $store->getCatalog();
$inventory  = $store->getInventory($userId);
$equipped   = $store->getEquipped($userId);
$consumables = $store->getConsumables($userId);

// Group catalog by category
$grouped = [];
foreach ($catalog as $item) {
    $grouped[$item['category']][] = $item;
}

// Map owned item IDs for quick lookup
$ownedItemIds = array_column($inventory, 'item_id');

$categoryMeta = [
    'tool'       => ['icon' => '🔧', 'label' => 'Tools',       'desc' => 'Boost rolls in specific encounter types'],
    'armor'      => ['icon' => '🛡️',  'label' => 'Armor',       'desc' => 'Reduce Gold lost on failed encounters'],
    'weapon'     => ['icon' => '⚔️',  'label' => 'Weapons',     'desc' => 'Increase XP earned on successful encounters'],
    'consumable' => ['icon' => '⚗️',  'label' => 'Consumables', 'desc' => 'One-time use items, replenish daily'],
];

$effectDescriptions = [
    'roll_bonus'        => fn($v, $cat) => '+' . (int)$v . ' to ' . ($cat ? ucfirst($cat) : 'all') . ' rolls',
    'failure_reduction' => fn($v, $cat) => '-' . (int)($v * 100) . '% failure Gold penalty' . ($cat ? ' (' . ucfirst($cat) . ')' : ''),
    'xp_boost'          => fn($v, $cat) => '+' . (int)($v * 100) . '% XP on success' . ($cat ? ' (' . ucfirst($cat) . ')' : ''),
    'action_restore'    => fn($v, $cat) => 'Restores ' . (int)$v . ' daily actions',
    'roll_boost_once'   => fn($v, $cat) => '+' . (int)$v . ' to your very next roll',
    'reroll_once'       => fn($v, $cat) => 'Re-roll most recent failed adventure',
];

// =========================================================================
// RENDER
// =========================================================================
$pageTitle = 'The Store';
$bodyClass = 'page-store';
$extraCss  = ['store.css'];

ob_start();
?>

<div class="store-wrap">

    <!-- HEADER -->
    <div class="store-header">
        <div>
            <h1>🏪 The Adventurer's Store</h1>
            <p class="text-muted">Equip yourself for the financial challenges ahead.</p>
        </div>
        <div class="store-gold-badge">
            <span class="store-gold-label">Your Gold</span>
            <span class="store-gold-val text-gold">
                <?= number_format((float)$user['gold'], 0) ?> 🪙
            </span>
        </div>
    </div>

    <div class="store-layout">

        <!-- =====================================================
             LEFT: CATALOG
        ===================================================== -->
        <div class="store-main">

            <?php foreach ($grouped as $catKey => $items):
                $cat = $categoryMeta[$catKey] ?? ['icon' => '📦', 'label' => $catKey, 'desc' => ''];
            ?>
            <div class="store-section">
                <div class="store-section-header">
                    <span class="store-section-icon"><?= $cat['icon'] ?></span>
                    <div>
                        <h3><?= $cat['label'] ?></h3>
                        <p class="text-muted" style="font-size:0.82rem;margin:0"><?= $cat['desc'] ?></p>
                    </div>
                </div>

                <div class="store-grid">
                    <?php foreach ($items as $item):
                        $owned    = in_array($item['id'], $ownedItemIds);
                        $isEquip  = isset($equipped[$item['slot']]) && $equipped[$item['slot']]['item_id'] == $item['id'];
                        $canAfford = (float)$user['gold'] >= $item['price'];
                        $meetsLevel = (int)$user['level'] >= (int)$item['level_req'];
                        $effectFn = $effectDescriptions[$item['effect_type']] ?? fn($v,$c) => '';
                        $effectText = $effectFn($item['effect_value'], $item['effect_category']);

                        // Consumable quantity owned
                        $consumableQty = 0;
                        if ($item['category'] === 'consumable') {
                            foreach ($consumables as $c) {
                                if ($c['item_id'] == $item['id']) {
                                    $consumableQty = $c['quantity'];
                                    break;
                                }
                            }
                        }
                        $consumableLimit = (int)$db->getSetting('consumable_daily_limit', 5);
                    ?>
                    <div class="store-item <?= $isEquip ? 'item-equipped' : '' ?> <?= !$meetsLevel ? 'item-locked' : '' ?>">
                        <div class="item-header">
                            <span class="item-slot-icon"><?= $cat['icon'] ?></span>
                            <?php if ($isEquip): ?>
                                <span class="item-badge badge-equipped">Equipped</span>
                            <?php elseif ($owned && $item['category'] !== 'consumable'): ?>
                                <span class="item-badge badge-owned">Owned</span>
                            <?php elseif ($item['category'] === 'consumable' && $consumableQty > 0): ?>
                                <span class="item-badge badge-owned">×<?= $consumableQty ?></span>
                            <?php elseif (!$meetsLevel): ?>
                                <span class="item-badge badge-locked">Lvl <?= $item['level_req'] ?></span>
                            <?php endif; ?>
                        </div>

                        <h4 class="item-name"><?= e($item['name']) ?></h4>
                        <p class="item-desc"><?= e($item['description']) ?></p>

                        <?php if ($item['flavor_text']): ?>
                        <p class="item-flavor">"<?= e($item['flavor_text']) ?>"</p>
                        <?php endif; ?>

                        <div class="item-effect">
                            <span class="effect-tag">✦ <?= e($effectText) ?></span>
                        </div>

                        <div class="item-footer">
                            <span class="item-price <?= !$canAfford ? 'cant-afford' : '' ?>">
                                <?= number_format($item['price']) ?> 🪙
                            </span>

                            <?php if (!$meetsLevel): ?>
                                <span class="btn-store-action locked">
                                    Level <?= $item['level_req'] ?> Required
                                </span>
                            <?php elseif ($item['category'] === 'consumable' && $consumableQty >= $consumableLimit): ?>
                                <span class="btn-store-action locked">Full (<?= $consumableLimit ?>)</span>
                            <?php else: ?>
                                <form method="POST">
                                    <?= Session::csrfField() ?>
                                    <input type="hidden" name="action"  value="buy">
                                    <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                    <?php
                                    $btnLabel = 'Buy';
                                    $btnClass = 'btn-store-buy';
                                    if ($isEquip) {
                                        $btnLabel = 'Re-buy (lose current)';
                                        $btnClass = 'btn-store-replace';
                                    } elseif ($owned && $item['category'] !== 'consumable') {
                                        $btnLabel = 'Upgrade (lose current)';
                                        $btnClass = 'btn-store-replace';
                                    }
                                    ?>
                                    <button
                                        type="submit"
                                        class="btn-store-action <?= $btnClass ?>"
                                        <?= !$canAfford ? 'disabled' : '' ?>
                                        <?= ($isEquip || ($owned && $item['category'] !== 'consumable'))
                                            ? 'onclick="return confirm(\'This will permanently replace your current item. Are you sure?\')"'
                                            : '' ?>
                                    ><?= $btnLabel ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

        </div><!-- /store-main -->

        <!-- =====================================================
             RIGHT: EQUIPPED GEAR + CONSUMABLES
        ===================================================== -->
        <div class="store-sidebar">

            <!-- EQUIPPED GEAR -->
            <div class="card equipped-card">
                <h3 class="mb-3">⚔ Equipped Gear</h3>
                <?php foreach (['tool' => '🔧', 'armor' => '🛡️', 'weapon' => '⚔️'] as $slot => $icon): ?>
                <div class="equipped-slot">
                    <span class="slot-icon"><?= $icon ?></span>
                    <div class="slot-body">
                        <span class="slot-label"><?= ucfirst($slot) ?></span>
                        <?php if (isset($equipped[$slot])): ?>
                            <span class="slot-item"><?= e($equipped[$slot]['name']) ?></span>
                        <?php else: ?>
                            <span class="slot-empty text-muted">Empty</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- CONSUMABLES IN INVENTORY -->
            <?php if (!empty($consumables)): ?>
            <div class="card consumables-card">
                <h3 class="mb-3">⚗️ Consumables</h3>
                <div class="consumables-list">
                    <?php foreach ($consumables as $c): ?>
                    <div class="consumable-row">
                        <div class="consumable-body">
                            <span class="consumable-name"><?= e($c['name']) ?></span>
                            <span class="consumable-qty text-muted">×<?= $c['quantity'] ?></span>
                        </div>
                        <form method="POST">
                            <?= Session::csrfField() ?>
                            <input type="hidden" name="action"  value="use">
                            <input type="hidden" name="item_id" value="<?= $c['item_id'] ?>">
                            <button type="submit" class="btn-use">Use</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- STORE INFO -->
            <div class="card store-info-card">
                <h3 class="mb-2">📜 Store Rules</h3>
                <ul class="store-rules">
                    <li>Each slot holds one item — buying a new one destroys the old</li>
                    <li>Item effects apply automatically during adventures</li>
                    <li>Consumables stack up to <?= $db->getSetting('consumable_daily_limit', 5) ?> and replenish daily</li>
                    <li>Higher level items become available as you advance</li>
                    <li>Choose carefully — there are no refunds</li>
                </ul>
            </div>

        </div><!-- /store-sidebar -->

    </div><!-- /store-layout -->

</div>

<?php
$pageContent = ob_get_clean();
require TPL_PATH . '/layout.php';
?>

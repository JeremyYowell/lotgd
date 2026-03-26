<?php
/**
 * lib/Store.php — Item store business logic
 */

class Store {

    private Database $db;
    private int      $consumableLimit;

    public function __construct() {
        $this->db              = Database::getInstance();
        $this->consumableLimit = (int) $this->db->getSetting('consumable_daily_limit', 5);
    }

    // =========================================================================
    // CATALOG
    // =========================================================================

    /**
     * Get all active store items, optionally filtered by category.
     */
    public function getCatalog(?string $category = null): array {
        $where  = 'WHERE is_active = 1';
        $params = [];
        if ($category) {
            $where   .= ' AND category = ?';
            $params[] = $category;
        }
        return $this->db->fetchAll(
            "SELECT * FROM store_items {$where} ORDER BY category, sort_order, price ASC",
            $params
        );
    }

    /**
     * Get a single item by ID.
     */
    public function getItem(int $itemId): array|false {
        return $this->db->fetchOne(
            "SELECT * FROM store_items WHERE id = ? AND is_active = 1", [$itemId]
        );
    }

    // =========================================================================
    // INVENTORY
    // =========================================================================

    /**
     * Get a player's full inventory with item details joined.
     */
    public function getInventory(int $userId): array {
        return $this->db->fetchAll(
            "SELECT ui.*, si.name, si.description, si.flavor_text,
                    si.category, si.slot, si.effect_type, si.effect_value,
                    si.effect_category, si.price, si.level_req
             FROM user_inventory ui
             JOIN store_items si ON si.id = ui.item_id
             WHERE ui.user_id = ?
             ORDER BY si.category, si.slot",
            [$userId]
        );
    }

    /**
     * Get only equipped items (non-consumables that are active).
     * Returns keyed by slot for easy lookup.
     */
    public function getEquipped(int $userId): array {
        $rows = $this->db->fetchAll(
            "SELECT ui.*, si.name, si.category, si.slot,
                    si.effect_type, si.effect_value, si.effect_category
             FROM user_inventory ui
             JOIN store_items si ON si.id = ui.item_id
             WHERE ui.user_id = ? AND ui.equipped = 1
               AND si.category != 'consumable'",
            [$userId]
        );

        $keyed = [];
        foreach ($rows as $row) {
            $keyed[$row['slot']] = $row;
        }
        return $keyed;
    }

    /**
     * Get consumables in inventory.
     */
    public function getConsumables(int $userId): array {
        return $this->db->fetchAll(
            "SELECT ui.*, si.name, si.description, si.effect_type,
                    si.effect_value, si.price
             FROM user_inventory ui
             JOIN store_items si ON si.id = ui.item_id
             WHERE ui.user_id = ? AND si.category = 'consumable' AND ui.quantity > 0",
            [$userId]
        );
    }

    // =========================================================================
    // PURCHASING
    // =========================================================================

    /**
     * Purchase an item for a player.
     * Returns ['success' => bool, 'error' => string|null, 'message' => string]
     */
    public function purchase(int $userId, int $itemId, array $user): array {
        $item = $this->getItem($itemId);

        if (!$item) {
            return ['success' => false, 'error' => 'That item does not exist.'];
        }

        // Level check
        if ((int)$user['level'] < (int)$item['level_req']) {
            return ['success' => false, 'error' =>
                'You must be level ' . $item['level_req'] . ' to purchase ' . $item['name'] . '.'
            ];
        }

        // Gold check
        if ((float)$user['gold'] < (int)$item['price']) {
            return ['success' => false, 'error' =>
                'Insufficient Gold. ' . $item['name'] . ' costs ' .
                number_format($item['price']) . ' Gold and you have ' .
                number_format((float)$user['gold'], 0) . '.'
            ];
        }

        $this->db->beginTransaction();
        try {
            if ($item['category'] === 'consumable') {
                $result = $this->purchaseConsumable($userId, $item);
            } else {
                $result = $this->purchaseEquipment($userId, $item);
            }

            if (!$result['success']) {
                $this->db->rollBack();
                return $result;
            }

            // Deduct gold
            $this->db->run(
                "UPDATE users SET gold = gold - ? WHERE id = ?",
                [$item['price'], $userId]
            );

            $this->db->commit();

            appLog('info', 'Item purchased', [
                'user_id' => $userId,
                'item'    => $item['name'],
                'price'   => $item['price'],
            ]);

            return $result;

        } catch (Exception $e) {
            $this->db->rollBack();
            appLog('error', 'Store purchase failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'Transaction failed. Please try again.'];
        }
    }

    private function purchaseEquipment(int $userId, array $item): array {
        $existing = $this->db->fetchOne(
            "SELECT ui.id, si.name AS old_name
             FROM user_inventory ui
             JOIN store_items si ON si.id = ui.item_id
             WHERE ui.user_id = ? AND si.slot = ? AND si.category != 'consumable'",
            [$userId, $item['slot']]
        );

        if ($existing) {
            // Replace and destroy old item — no refund
            $this->db->run(
                "UPDATE user_inventory SET item_id = ?, acquired_at = NOW()
                 WHERE id = ?",
                [$item['id'], $existing['id']]
            );
            $msg = 'Equipped ' . $item['name'] . '. Your ' . $existing['old_name'] . ' has been lost.';
        } else {
            // New slot
            $this->db->run(
                "INSERT INTO user_inventory (user_id, item_id, quantity, equipped)
                 VALUES (?, ?, 1, 1)",
                [$userId, $item['id']]
            );
            $msg = $item['name'] . ' equipped in your ' . ucfirst($item['slot']) . ' slot.';
        }

        return ['success' => true, 'error' => null, 'message' => $msg];
    }

    private function purchaseConsumable(int $userId, array $item): array {
        $existing = $this->db->fetchOne(
            "SELECT * FROM user_inventory WHERE user_id = ? AND item_id = ?",
            [$userId, $item['id']]
        );

        if ($existing) {
            if ($existing['quantity'] >= $this->consumableLimit) {
                return ['success' => false, 'error' =>
                    'You already have the maximum of ' . $this->consumableLimit .
                    ' ' . $item['name'] . '(s).'
                ];
            }
            $this->db->run(
                "UPDATE user_inventory SET quantity = quantity + 1 WHERE id = ?",
                [$existing['id']]
            );
        } else {
            $this->db->run(
                "INSERT INTO user_inventory (user_id, item_id, quantity, equipped)
                 VALUES (?, ?, 1, 1)",
                [$userId, $item['id']]
            );
        }

        return [
            'success' => true,
            'error'   => null,
            'message' => $item['name'] . ' added to your inventory.',
        ];
    }

    // =========================================================================
    // CONSUMABLE USAGE
    // =========================================================================

    /**
     * Use a consumable from inventory.
     * Returns ['success' => bool, 'error' => string, 'effect_type' => string, 'effect_value' => float]
     */
    public function useConsumable(int $userId, int $itemId): array {
        $inv = $this->db->fetchOne(
            "SELECT ui.*, si.effect_type, si.effect_value, si.name, si.category
             FROM user_inventory ui
             JOIN store_items si ON si.id = ui.item_id
             WHERE ui.user_id = ? AND ui.item_id = ? AND ui.quantity > 0",
            [$userId, $itemId]
        );

        if (!$inv || $inv['category'] !== 'consumable') {
            return ['success' => false, 'error' => 'You do not have that item.'];
        }

        // reroll_once: daily cooldown tracked in DB — scroll is NOT consumed on use
        if ($inv['effect_type'] === 'reroll_once') {
            // Ensure daily_state row exists (getDailyState creates it if absent)
            $userModel  = new User();
            $dailyState = $userModel->getDailyState($userId);

            if (!empty($dailyState['reroll_used_scroll'])) {
                return ['success' => false, 'error' => 'You can only use one Second Chance Scroll per day. Return at dawn.'];
            }

            // Mark used in daily_state (row guaranteed to exist from getDailyState above)
            $this->db->run(
                "UPDATE daily_state SET reroll_used_scroll = 1
                 WHERE user_id = ? AND state_date = CURDATE()",
                [$userId]
            );

            // Return success WITHOUT deducting from inventory — scroll stays in satchel
            return [
                'success'      => true,
                'error'        => null,
                'effect_type'  => 'reroll_once',
                'effect_value' => 0,
                'item_name'    => $inv['name'],
            ];
        }

        // All other consumables: deduct from inventory
        if ($inv['quantity'] > 1) {
            $this->db->run(
                "UPDATE user_inventory SET quantity = quantity - 1 WHERE id = ?",
                [$inv['id']]
            );
        } else {
            $this->db->run(
                "DELETE FROM user_inventory WHERE id = ?", [$inv['id']]
            );
        }

        return [
            'success'      => true,
            'error'        => null,
            'effect_type'  => $inv['effect_type'],
            'effect_value' => (float)$inv['effect_value'],
            'item_name'    => $inv['name'],
        ];
    }

    // =========================================================================
    // ADMIN TOOLS
    // =========================================================================

    /**
     * Admin: grant an item directly to a player, bypassing level/gold checks.
     * Equipment: replaces slot if occupied by a different item.
     * Consumable: increments quantity with no cap.
     */
    public static function adminGiveItem(int $userId, int $itemId): array {
        $db   = Database::getInstance();
        $item = $db->fetchOne("SELECT * FROM store_items WHERE id = ?", [$itemId]);

        if (!$item) {
            return ['success' => false, 'message' => 'Item not found.'];
        }

        $db->beginTransaction();
        try {
            if ($item['category'] === 'consumable') {
                $existing = $db->fetchOne(
                    "SELECT * FROM user_inventory WHERE user_id = ? AND item_id = ?",
                    [$userId, $item['id']]
                );
                if ($existing) {
                    $db->run(
                        "UPDATE user_inventory SET quantity = quantity + 1 WHERE id = ?",
                        [$existing['id']]
                    );
                    $msg = $item['name'] . ' quantity increased (now ' . ($existing['quantity'] + 1) . ').';
                } else {
                    $db->run(
                        "INSERT INTO user_inventory (user_id, item_id, quantity, equipped) VALUES (?, ?, 1, 1)",
                        [$userId, $item['id']]
                    );
                    $msg = $item['name'] . ' added to inventory.';
                }
            } else {
                // Equipment — find existing item in this slot
                $existing = $db->fetchOne(
                    "SELECT ui.id, ui.item_id, si.name AS old_name
                     FROM user_inventory ui
                     JOIN store_items si ON si.id = ui.item_id
                     WHERE ui.user_id = ? AND si.slot = ? AND si.category != 'consumable'",
                    [$userId, $item['slot']]
                );
                if ($existing) {
                    if ((int)$existing['item_id'] === (int)$item['id']) {
                        $db->rollBack();
                        return ['success' => false, 'message' => 'Player already has ' . $item['name'] . ' equipped.'];
                    }
                    $db->run(
                        "UPDATE user_inventory SET item_id = ?, acquired_at = NOW() WHERE id = ?",
                        [$item['id'], $existing['id']]
                    );
                    $msg = $item['name'] . ' equipped, replacing ' . $existing['old_name'] . '.';
                } else {
                    $db->run(
                        "INSERT INTO user_inventory (user_id, item_id, quantity, equipped) VALUES (?, ?, 1, 1)",
                        [$userId, $item['id']]
                    );
                    $msg = $item['name'] . ' equipped.';
                }
            }

            $db->commit();
            appLog('info', 'Admin gave item', [
                'admin_id'    => Session::userId(),
                'target_user' => $userId,
                'item'        => $item['name'],
            ]);
            return ['success' => true, 'message' => $msg];

        } catch (Exception $e) {
            $db->rollBack();
            appLog('error', 'Admin give item failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Transaction failed.'];
        }
    }

    // =========================================================================
    // EFFECT CALCULATION (called by Adventure engine)
    // =========================================================================

    /**
     * Calculate the total roll modifier from equipped items for a given scenario category.
     */
    public function getRollBonus(int $userId, string $scenarioCategory): int {
        $equipped = $this->getEquipped($userId);
        $bonus    = 0;

        foreach ($equipped as $item) {
            if ($item['effect_type'] !== 'roll_bonus') continue;
            if ($item['effect_category'] === null || $item['effect_category'] === $scenarioCategory) {
                $bonus += (int)$item['effect_value'];
            }
        }

        // One-time roll boost from consumable (stored in session)
        $onceBonus = (int) Session::get('roll_boost_once', 0);
        if ($onceBonus > 0) {
            $bonus += $onceBonus;
            Session::delete('roll_boost_once');
        }

        return $bonus;
    }

    /**
     * Calculate the failure gold penalty multiplier from equipped armor.
     * Returns a value between 0 and 1 — multiply the penalty by this.
     * 1.0 = no reduction, 0.75 = 25% reduction, etc.
     */
    public function getFailureMultiplier(int $userId, string $scenarioCategory): float {
        $equipped   = $this->getEquipped($userId);
        $multiplier = 1.0;

        foreach ($equipped as $item) {
            if ($item['effect_type'] !== 'failure_reduction') continue;
            if ($item['effect_category'] === null || $item['effect_category'] === $scenarioCategory) {
                $multiplier -= (float)$item['effect_value'];
            }
        }

        return max(0.0, $multiplier); // Never below 0
    }

    /**
     * Calculate the XP boost multiplier from equipped weapon for a given category.
     * Returns 1.0 = no boost, 1.15 = 15% boost, etc.
     */
    public function getXpMultiplier(int $userId, string $scenarioCategory): float {
        $equipped   = $this->getEquipped($userId);
        $multiplier = 1.0;

        foreach ($equipped as $item) {
            if ($item['effect_type'] !== 'xp_boost') continue;
            if ($item['effect_category'] === null || $item['effect_category'] === $scenarioCategory) {
                $multiplier += (float)$item['effect_value'];
            }
        }

        return $multiplier;
    }
}

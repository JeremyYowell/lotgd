-- =============================================================================
-- Fix: Switch benchmark from ^GSPC to SPY
-- ^GSPC requires a Finnhub paid subscription; SPY works on the free tier
-- =============================================================================

-- Remove any ^GSPC rows (they were never populated anyway)
DELETE FROM stock_prices WHERE ticker = '^GSPC';

-- Reset inception so next price_update.php run sets it from SPY
UPDATE settings SET setting_value = '' WHERE setting_key = 'spx_inception_date';
UPDATE settings SET setting_value = '' WHERE setting_key = 'spx_inception_price';

-- SPY will be added to stock_prices automatically on the next
-- price_update.php run (it gets appended to the ticker list if not present).
-- No manual INSERT needed.

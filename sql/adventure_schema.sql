-- =============================================================================
-- LEGENDS OF THE GREEN DOLLAR
-- Adventure System — Schema + Seed Data
-- Run against both lotgd_dev and lotgd_prod
-- =============================================================================

-- ------------------------------------
-- adventure_scenarios
-- ------------------------------------
CREATE TABLE IF NOT EXISTS `adventure_scenarios` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `title`         VARCHAR(150)    NOT NULL,
    `description`   TEXT            NOT NULL COMMENT 'The scene-setting narrative shown to the player',
    `flavor_text`   VARCHAR(255)    DEFAULT NULL COMMENT 'Short italic tagline under the title',
    `category`      ENUM(
                        'shopping',
                        'work',
                        'banking',
                        'investing',
                        'housing',
                        'daily_life'
                    )               NOT NULL DEFAULT 'daily_life',
    `min_level`     TINYINT         NOT NULL DEFAULT 1,
    `max_level`     TINYINT         NOT NULL DEFAULT 50,
    `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_category` (`category`),
    KEY `idx_is_active` (`is_active`),
    KEY `idx_min_level` (`min_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------
-- adventure_choices
-- ------------------------------------
CREATE TABLE IF NOT EXISTS `adventure_choices` (
    `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `scenario_id`           INT UNSIGNED    NOT NULL,
    `choice_text`           VARCHAR(255)    NOT NULL COMMENT 'The action the player takes',
    `hint_text`             VARCHAR(255)    DEFAULT NULL COMMENT 'Visible hint about the approach',
    `difficulty`            TINYINT         NOT NULL DEFAULT 10 COMMENT 'DC 1-20 the roll must beat',
    `base_xp`               SMALLINT        NOT NULL DEFAULT 100,
    `base_gold`             SMALLINT        NOT NULL DEFAULT 25,
    `success_narrative`     TEXT            NOT NULL,
    `failure_narrative`     TEXT            NOT NULL,
    `crit_success_narrative`TEXT            NOT NULL,
    `crit_failure_narrative`TEXT            NOT NULL,
    `sort_order`            TINYINT         NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_scenario_id` (`scenario_id`),
    CONSTRAINT `fk_choices_scenario`
        FOREIGN KEY (`scenario_id`) REFERENCES `adventure_scenarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------
-- adventure_log
-- ------------------------------------
CREATE TABLE IF NOT EXISTS `adventure_log` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED    NOT NULL,
    `scenario_id`   INT UNSIGNED    NOT NULL,
    `choice_id`     INT UNSIGNED    NOT NULL,
    `roll`          TINYINT         NOT NULL COMMENT 'Raw d20 roll',
    `modifier`      TINYINT         NOT NULL DEFAULT 0 COMMENT 'Level + class modifier applied',
    `final_roll`    TINYINT         NOT NULL COMMENT 'roll + modifier',
    `difficulty`    TINYINT         NOT NULL,
    `outcome`       ENUM(
                        'crit_success',
                        'success',
                        'failure',
                        'crit_failure'
                    )               NOT NULL,
    `xp_delta`      SMALLINT        NOT NULL DEFAULT 0,
    `gold_delta`    SMALLINT        NOT NULL DEFAULT 0 COMMENT 'Negative on failure',
    `adventured_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_adventured_at` (`adventured_at` DESC),
    CONSTRAINT `fk_advlog_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SEED DATA: Adventure Scenarios + Choices
-- =============================================================================

-- -------------------------------------------------------
-- SHOPPING SCENARIOS
-- -------------------------------------------------------
INSERT INTO `adventure_scenarios`
    (title, description, flavor_text, category, min_level, max_level) VALUES
(
    'The Used Car Dealership of Despair',
    'You step onto the lot and a silver-tongued salesman materializes from the shadows. He smells of cologne and commission. A gleaming sedan sits under fluorescent lights, its sticker price bearing questionable fees and an extended warranty you did not ask for.',
    'Not all that glitters is a good deal.',
    'shopping', 1, 50
),
(
    'The Electronics Temple',
    'The great cathedral of gadgetry looms before you. A new phone model has just been released — your current one works perfectly fine. A store associate approaches with the zeal of a true believer, brandishing a 24-month payment plan.',
    'Desire is the enemy of the wise spender.',
    'shopping', 1, 50
),
(
    'The Grocery Gauntlet',
    'You enter the store for bread and milk. The endcap displays are a trap — buy two get one free on things you do not need. The premium organic section beckons. Your stomach growls treacherously.',
    'The battlefield of impulse awaits.',
    'shopping', 1, 20
);

-- -------------------------------------------------------
-- WORK SCENARIOS
-- -------------------------------------------------------
INSERT INTO `adventure_scenarios`
    (title, description, flavor_text, category, min_level, max_level) VALUES
(
    'The Salary Negotiation',
    'Your annual review has arrived. Your manager sits across the table, folder closed, expression neutral. You have delivered strong results this year. The first offer hangs in the air — lower than you deserve.',
    'Fortune favors the prepared.',
    'work', 3, 50
),
(
    'The Freelance Contract',
    'A new client wants your skills. They have sent over a contract — the scope is vague, the rate is below market, and the payment terms say net-90. They seem eager. You have leverage you may not realize.',
    'Your time has value. Name it.',
    'work', 5, 50
),
(
    'The Coworker''s Hot Stock Tip',
    'Your colleague leans over the cubicle wall with the look of someone who has discovered fire. He whispers the name of a small-cap mining company his brother-in-law''s friend mentioned. "It''s about to explode," he says.',
    'Whispers in the break room have ruined many a portfolio.',
    'work', 2, 50
);

-- -------------------------------------------------------
-- BANKING SCENARIOS
-- -------------------------------------------------------
INSERT INTO `adventure_scenarios`
    (title, description, flavor_text, category, min_level, max_level) VALUES
(
    'The Overdraft Fee Ambush',
    'You check your account and find a $35 overdraft fee from a $4.50 coffee purchase. The bank''s phone tree labyrinth awaits. A customer service representative has the power to waive it — or not.',
    'The fee is not the end. It is the beginning of a negotiation.',
    'banking', 1, 50
),
(
    'The Credit Card Upsell',
    'Your bank calls to offer you a new rewards credit card. The APR is 26.99%. The sign-up bonus sounds impressive. The annual fee is buried in the fine print. The representative is friendly and persistent.',
    'Not all rewards are worth their cost.',
    'banking', 2, 50
),
(
    'The Predatory Loan Offer',
    'Between jobs and short on rent, a payday lending storefront catches your eye. 400% APR in small print, smiling faces on the sign. A personal loan from a credit union is two bus stops away.',
    'The easy path often leads to the hardest place.',
    'banking', 1, 15
);

-- -------------------------------------------------------
-- INVESTING SCENARIOS
-- -------------------------------------------------------
INSERT INTO `adventure_scenarios`
    (title, description, flavor_text, category, min_level, max_level) VALUES
(
    'The Crypto Evangelist',
    'At a dinner party, a loud acquaintance corners you to explain why a new cryptocurrency called MoonCoin will replace the global financial system by Thursday. He has already remortgaged his boat.',
    'Not every prophet is worth following.',
    'investing', 3, 50
),
(
    'The Market Crash',
    'Red candles fill every screen. Your portfolio is down 18% in two weeks. The financial news is apocalyptic. Your finger hovers over the sell button. History says something. Fear says something else.',
    'Panic is the most expensive emotion.',
    'investing', 5, 50
),
(
    'The 401k Enrollment',
    'HR has emailed you about open enrollment. Your employer matches up to 4% of your salary. You currently contribute 0%. The form is three pages long and asks about asset allocation. You have been putting this off.',
    'Free money awaits the merely attentive.',
    'investing', 1, 50
);

-- -------------------------------------------------------
-- HOUSING SCENARIOS
-- -------------------------------------------------------
INSERT INTO `adventure_scenarios`
    (title, description, flavor_text, category, min_level, max_level) VALUES
(
    'The Rent Increase Notice',
    'A letter has appeared under your door. Your landlord is raising rent by $200/month at renewal. The market rate for comparable units is $150 above your current rate. You have been a model tenant for three years.',
    'Loyalty has value. Remind them.',
    'housing', 2, 50
),
(
    'The Mortgage Broker',
    'You are buying your first home. Three lenders have made offers. One is a friend of a friend who says he can get you "a great deal" without showing you the terms first. The other two sent disclosure forms.',
    'The friendliest offer is not always the fairest.',
    'housing', 8, 50
);

-- -------------------------------------------------------
-- DAILY LIFE SCENARIOS
-- -------------------------------------------------------
INSERT INTO `adventure_scenarios`
    (title, description, flavor_text, category, min_level, max_level) VALUES
(
    'The Subscription Audit',
    'Reviewing your bank statement, you discover seven recurring charges you don''t immediately recognize. One is a gym membership from two years ago. Two are streaming services you share a password for anyway.',
    'Small leaks sink large ships.',
    'daily_life', 1, 50
),
(
    'The Restaurant Pressure',
    'Your group of friends has chosen an expensive restaurant for a birthday dinner. Split evenly, your share will be $95 — for food you didn''t order and wine you didn''t drink. Social pressure and financial sense are in direct conflict.',
    'The cost of belonging is sometimes negotiable.',
    'daily_life', 1, 50
),
(
    'The Emergency Fund Test',
    'Your car needs a $900 repair. You have $1,200 in your emergency fund, built over six months of discipline. You also have a credit card with a $5,000 limit at 22% APR.',
    'This is exactly what the fund is for.',
    'daily_life', 1, 50
);

-- =============================================================================
-- CHOICES FOR EACH SCENARIO
-- =============================================================================

-- Scenario 1: Used Car Dealership
SET @s = (SELECT id FROM adventure_scenarios WHERE title = 'The Used Car Dealership of Despair');
INSERT INTO `adventure_choices`
    (scenario_id, choice_text, hint_text, difficulty,
     base_xp, base_gold, sort_order,
     success_narrative, failure_narrative,
     crit_success_narrative, crit_failure_narrative) VALUES
(@s,
 'Present the KBB report and counter $2,000 below sticker',
 'You came prepared. Research is your armor.',
 12, 120, 30, 1,
 'You slide the printout across the desk. The salesman''s smile flickers. After twenty minutes of theatre, he comes back with a number $1,800 lower. You leave victorious.',
 'The salesman waves away your printout dismissively. "That''s not how we price here." You lose ground in the negotiation and pay more than planned.',
 'Your preparation is impeccable. The salesman calls his manager, who calls his manager. You walk out $2,400 under sticker with free floor mats. A legend is born.',
 'He laughs. Actually laughs. The printout flutters to the floor. You pay sticker price and drive home in expensive silence.'
),
(@s,
 'Ask for all fees itemized in writing before discussing price',
 'Transparency first. Never negotiate blind.',
 10, 100, 25, 2,
 'The itemized list reveals $800 in documentation fees. You point to each one calmly. Three disappear before you shake hands.',
 'The paperwork arrives but the fees are labeled in jargon you can''t decode quickly. You sign without fully understanding, and the fees remain.',
 'The itemization reveals a documentation fee, a dealer prep fee, and a "market adjustment" fee. You decline all three by name. The salesman loses his composure entirely.',
 'The paperwork is forty pages. You get lost in section seven and sign something you shouldn''t have. The fees stay. You pay them all.'
),
(@s,
 'Accept the deal and sign immediately',
 'Risky. Excitement is the enemy of a good price.',
 5, 50, 10, 3,
 'You sign quickly. The deal isn''t terrible — luck was with you today, and the base price was fair.',
 'Impulse has a price. You realize three days later you paid $1,500 over market rate. The extended warranty is non-refundable.',
 'Somehow everything aligns — the base price is fair, the rate is reasonable, and you needed this car anyway. Not every fast decision is wrong.',
 'You signed in fourteen places before reading them. The extended warranty alone cost $2,800. The "guaranteed rate" was not guaranteed. Darkness.'
);

-- Scenario 2: Electronics Temple
SET @s = (SELECT id FROM adventure_scenarios WHERE title = 'The Electronics Temple');
INSERT INTO `adventure_choices`
    (scenario_id, choice_text, hint_text, difficulty,
     base_xp, base_gold, sort_order,
     success_narrative, failure_narrative,
     crit_success_narrative, crit_failure_narrative) VALUES
(@s,
 'Walk out without buying anything',
 'The most powerful word in personal finance.',
 8, 110, 28, 1,
 'You turn toward the exit. The associate calls after you with a "limited time offer." You wave without turning around. The money stays in your pocket.',
 'You make it to the door and then turn back. "Just to look." You leave with accessories you didn''t need.',
 'You stride out without breaking pace. The automatic doors part like the Red Sea. You feel genuinely powerful.',
 'You make it to the parking lot and then check the website on your phone. You order it online instead. The victory is partial at best.'
),
(@s,
 'Ask to see last year''s model at a discount',
 'New features rarely justify full price.',
 11, 115, 28, 2,
 'Last year''s model is 40% cheaper and does everything you need. You buy it. The associate looks personally offended.',
 'Last year''s model is out of stock. The associate steers you back to the new one with practiced ease.',
 'Last year''s model is on clearance. You get it for 55% off and it is functionally identical. You are insufferably smug about this.',
 'There is no last year''s model. There is only the new one, glowing under the lights, calling your name. You buy it on a payment plan.'
),
(@s,
 'Buy the new model on the payment plan',
 'Convenience has a monthly cost.',
 4, 40, 10, 3,
 'The payment plan rate is actually 0% promotional. You buy it and pay it off before interest kicks in.',
 'The 0% rate expires in six months. You forget. The interest retroactively applied is significant.',
 'Zero percent interest for 18 months and you already have a payoff plan. This is fine, actually.',
 'The payment plan is 29.99% APR in the fine print. You will pay for this phone for three years and then some.'
);

-- Scenario 3: Grocery Gauntlet
SET @s = (SELECT id FROM adventure_scenarios WHERE title = 'The Grocery Gauntlet');
INSERT INTO `adventure_choices`
    (scenario_id, choice_text, hint_text, difficulty,
     base_xp, base_gold, sort_order,
     success_narrative, failure_narrative,
     crit_success_narrative, crit_failure_narrative) VALUES
(@s,
 'Stick to the list, ignore all endcaps',
 'The list is your shield against impulse.',
 9, 100, 25, 1,
 'You navigate the store with tunnel vision. Bread. Milk. Done. The cart is lighter than usual. So is the receipt.',
 'The endcap has something you genuinely do need. Then another thing. The list expands by four items.',
 'You complete the mission in eleven minutes. Three sales associates try to direct you to featured items. You do not hear them.',
 'The endcap has a deal so good it hurts. Buy four get two free on something you will never use four of. The cart fills.'
),
(@s,
 'Buy store brand versions of everything',
 'The label is not the product.',
 8, 95, 22, 2,
 'Store brand pasta, store brand sauce, store brand everything. The bill is 30% lower. The food is identical.',
 'You grab store brand on most things but switch back on two or three items from habit. Partial victory.',
 'Every single item is store brand. You save 35% and cannot taste the difference on a single one. You evangelize this to strangers.',
 'Store brand cereal is fine. Store brand dish soap is fine. Store brand cooking wine is a mistake you will not repeat.'
),
(@s,
 'Let yourself browse — you deserve a treat',
 'Morale matters. But so does the budget.',
 6, 60, 15, 3,
 'You buy two small treats and otherwise stay reasonable. The joy was worth the modest overage.',
 'Browsing becomes grazing becomes a cart full of things that looked good in the store. The receipt is a confession.',
 'You find a genuinely good sale, get your treat, and stay under budget anyway. Today the universe provided.',
 'You do not remember most of what you bought. The fridge is full. The wallet is empty. At least you have twelve yogurts.'
);

-- Scenario 4: Salary Negotiation
SET @s = (SELECT id FROM adventure_scenarios WHERE title = 'The Salary Negotiation');
INSERT INTO `adventure_choices`
    (scenario_id, choice_text, hint_text, difficulty,
     base_xp, base_gold, sort_order,
     success_narrative, failure_narrative,
     crit_success_narrative, crit_failure_narrative) VALUES
(@s,
 'Name a specific number 15% above the offer',
 'Specificity signals preparation. Anchor high.',
 13, 150, 40, 1,
 'You say the number without flinching. There is a pause. Your manager writes something down. The final number splits the difference favorably.',
 'The number lands awkwardly. Your manager says it''s outside the band. You settle for less than you deserved.',
 'You name the number. Your manager nods slowly. "I think we can do that." No counteroffer. Just yes.',
 'The number is too high and it shows on their face. The conversation becomes uncomfortable. You end up below the original offer.'
),
(@s,
 'Ask what the budget range is before committing',
 'Information before position.',
 11, 130, 35, 2,
 'They reveal the top of the range. You ask for the top of the range. You get it.',
 'They give a vague answer. You lose the information advantage and negotiate without data.',
 'The range is wider than you expected. You ask for the absolute top. They give it to you, impressed by your directness.',
 'They deflect the question entirely. You have no anchor. The negotiation drifts toward their number.'
),
(@s,
 'Accept the first offer graciously',
 'Gratitude is kind. Negotiation is kinder to your bank account.',
 5, 60, 15, 3,
 'The offer is actually fair. You accept and your manager notes your easy professionalism.',
 'The offer was the opening bid. There was room. There is always room. You left money on the table.',
 'Unknown to you, the first offer was also the maximum. You got everything available and built goodwill.',
 'The offer was 20% below what they were authorized to pay. You will never know. The money is gone forever.'
);

-- Scenario 5: Freelance Contract
SET @s = (SELECT id FROM adventure_scenarios WHERE title = 'The Freelance Contract');
INSERT INTO `adventure_choices`
    (scenario_id, choice_text, hint_text, difficulty,
     base_xp, base_gold, sort_order,
     success_narrative, failure_narrative,
     crit_success_narrative, crit_failure_narrative) VALUES
(@s,
 'Rewrite the scope, rate, and payment terms before signing',
 'A contract is a negotiation, not a sentence.',
 14, 160, 45, 1,
 'You return a marked-up contract. The client accepts most changes. Net-30, market rate, defined scope. You sign something you understand.',
 'The client pushes back hard on payment terms. You get the rate but not the timeline. Net-90 it is.',
 'The client accepts every change without comment. You suspect they expected this and respect you for it.',
 'The client withdraws the offer. They wanted someone who wouldn''t ask questions. Saved you trouble, actually. But the gold is gone.'
),
(@s,
 'Ask for 50% upfront before starting',
 'Time and skill deserve protection.',
 12, 140, 38, 2,
 'The client agrees to 50% upfront. You begin work with confidence and appropriate security.',
 'The client balks at upfront payment. You work on faith. The invoice sits unpaid for nine weeks.',
 'The client pays immediately and adds a note of respect. This is how real professionals work, they say.',
 'The client disappears after you start. Net-90 means nothing if they never pay. A hard lesson.'
),
(@s,
 'Sign as-is to secure the work quickly',
 'Speed has a cost. Read before you sign.',
 5, 55, 12, 3,
 'The terms are surprisingly fair. You got lucky. The client pays on time.',
 'Net-90 means you wait three months for money you earned in week one. Cash flow suffers.',
 'The contract was boilerplate and entirely reasonable. Sometimes the paperwork is fine.',
 'Scope creep begins on day two. The vague contract gives them every excuse. You work twice as much for the same pay.'
);

-- Scenario 6: Coworker Hot Stock Tip
SET @s = (SELECT id FROM adventure_scenarios WHERE title = 'The Coworker''s Hot Stock Tip');
INSERT INTO `adventure_choices`
    (scenario_id, choice_text, hint_text, difficulty,
     base_xp, base_gold, sort_order,
     success_narrative, failure_narrative,
     crit_success_narrative, crit_failure_narrative) VALUES
(@s,
 'Smile politely and put nothing in',
 'The best investment advice usually comes in writing, not whispers.',
 7, 105, 28, 1,
 'You nod thoughtfully and do nothing. Three weeks later the stock is down 60%. Your coworker stops mentioning it.',
 'Curiosity wins. You put in a small amount "just to see." The small amount is now a smaller amount.',
 'You do not invest a single dollar. The stock becomes the subject of an SEC investigation. Your restraint was prophetic.',
 'You politely decline and then look it up anyway and buy a little. It halves. You do not tell your coworker.'
),
(@s,
 'Research the company thoroughly before deciding',
 'Diligence is not exciting. It is effective.',
 13, 145, 38, 2,
 'Three hours of research reveals the company has one asset: a website. You invest nothing. You feel very wise.',
 'The research is inconclusive and you buy a small amount anyway. It performs poorly. Research without discipline is just delay.',
 'Research reveals the company is genuinely promising AND undervalued. Your independent conclusion, not the tip, leads you to a solid investment.',
 'Research reveals red flags and you ignore them because your coworker seemed so sure. You lose the gold. He loses his boat.'
),
(@s,
 'Invest a significant amount immediately',
 'Acting on rumors is a form of gambling.',
 3, 30, 8, 3,
 'Against all odds it actually goes up. You sell quickly before it reverses. Luck is not a strategy but it worked today.',
 'The stock drops 40% by Friday. Your coworker says the timing was just bad. The money is gone.',
 'You stumble into a genuine multi-bagger. This happens roughly once per career. Do not learn the wrong lesson.',
 'It is a pump and dump. Your purchase was at the peak. You lost the gold and your coworker is suddenly not at his desk.'
);

-- Scenario 7: Overdraft Fee
SET @s = (SELECT id FROM adventure_scenarios WHERE title = 'The Overdraft Fee Ambush');
INSERT INTO `adventure_choices`
    (scenario_id, choice_text, hint_text, difficulty,
     base_xp, base_gold, sort_order,
     success_narrative, failure_narrative,
     crit_success_narrative, crit_failure_narrative) VALUES
(@s,
 'Call and firmly but politely request a waiver',
 'Banks waive first-time fees regularly. Ask directly.',
 9, 110, 30, 1,
 'The representative checks your account history. Good standing, first offense. The fee is reversed. Total call time: six minutes.',
 'The first representative says no. You accept it. A second call to a different rep would have succeeded.',
 'The representative waives the fee and proactively enrolls you in overdraft protection. You did not even have to ask twice.',
 'The hold time is 45 minutes. The representative is unyielding. The fee stands. You are exhausted and $35 poorer.'
),
(@s,
 'Ask to speak to a supervisor immediately',
 'Escalation works. So does patience.',
 12, 120, 30, 2,
 'The supervisor waives the fee and adds a courtesy credit for your trouble.',
 'The supervisor is less sympathetic than the original rep. You have escalated yourself into a worse position.',
 'The supervisor waives the fee, the next month''s fee preemptively, and upgrades your account tier.',
 'The supervisor is the final word. They say no. You have burned your one escalation. The fee remains.'
),
(@s,
 'Accept the fee and move on',
 '$35 is not nothing. But time is also money.',
 3, 35, 8, 3,
 'You move on. The fee stings but your time is genuinely valuable and the stress isn''t worth it.',
 'You move on, but the fee recurs next month because you never set up overdraft protection.',
 'Moving on was right — you immediately use the time saved to automate your finances and prevent future fees entirely.',
 'You move on. The fee recurs. And again. Five months later you have paid $175 for your equanimity.'
);

-- Scenario 8: Credit Card Upsell
SET @s = (SELECT id FROM adventure_scenarios WHERE title = 'The Credit Card Upsell');
INSERT INTO `adventure_choices`
    (scenario_id, choice_text, hint_text, difficulty,
     base_xp, base_gold, sort_order,
     success_narrative, failure_narrative,
     crit_success_narrative, crit_failure_narrative) VALUES
(@s,
 'Ask for the full terms in writing and call back',
 'A decision made under sales pressure is rarely optimal.',
 10, 115, 30, 1,
 'The written terms reveal the rewards rate is lower than advertised. You decline. The representative is disappointed. Your wallet is not.',
 'You call back and a more persuasive representative closes you. The card is not what you wanted.',
 'The written terms are actually excellent. You apply with full information and get a genuinely useful product.',
 'You call back in a weak moment and apply. The credit inquiry drops your score. The card sits unused.'
),
(@s,
 'Decline outright and ask to remove you from offers',
 'Saying no is a complete sentence.',
 8, 105, 26, 2,
 'You are removed from the offer list. The calls stop. The silence is valuable.',
 'You decline but forget to ask about removal. The calls continue at dinner time indefinitely.',
 'You decline, get removed from all marketing lists, and the representative notes your account as financially sophisticated.',
 'They call back next week with a different offer. And the week after. The removal request did not take.'
),
(@s,
 'Apply for the card — the rewards sound good',
 'Rewards are only valuable if you pay in full monthly.',
 6, 65, 15, 3,
 'You apply, get approved, and pay it in full every month. The rewards are modest but real.',
 'The rewards are real. So is the $150 annual fee. The math does not favor you.',
 'The card has an unexpected perk that saves you significantly more than the annual fee. A rare win.',
 '26.99% APR is a number that compounds against you. One missed payment later and the rewards are a distant memory.'
);

-- Scenario 9: Market Crash
SET @s = (SELECT id FROM adventure_scenarios WHERE title = 'The Market Crash');
INSERT INTO `adventure_choices`
    (scenario_id, choice_text, hint_text, difficulty,
     base_xp, base_gold, sort_order,
     success_narrative, failure_narrative,
     crit_success_narrative, crit_failure_narrative) VALUES
(@s,
 'Hold and buy more at the lower prices',
 'Every crash in history has eventually recovered.',
 14, 160, 45, 1,
 'You buy the dip with discipline. Six months later the portfolio is up 24% from your pre-crash level.',
 'You buy more but the dip continues. Your conviction wavers. You sell at the worst possible moment.',
 'You buy aggressively at the bottom. The recovery is faster than expected. This is the trade you will tell stories about.',
 'You buy all the way down. The recovery takes four years. Your conviction is technically vindicated but practically irrelevant.'
),
(@s,
 'Hold without adding — stay the course',
 'Inaction is underrated as a financial strategy.',
 11, 130, 35, 2,
 'You watch the red numbers without acting. They eventually turn green. Your discipline is rewarded.',
 'Watching is harder than it sounds. You sell 40% of your position at the bottom and miss most of the recovery.',
 'You hold with serene conviction. The recovery is swift. You never had to do anything at all.',
 'You hold all the way down and then sell at the very bottom in a moment of pure fear. Then it recovers immediately.'
),
(@s,
 'Sell everything and wait for stability',
 'Timing the market is famously difficult.',
 5, 50, 12, 3,
 'You sell and the crash continues further. You rebuy at a lower price. Accidental timing saves you.',
 'You sell at a loss and wait. You rebuy higher than you sold. You have successfully bought high and sold low.',
 'You sell at exactly the right moment, sit in cash, and rebuy at the absolute bottom. This almost never happens.',
 'You sell at -18%, miss the recovery, rebuy at +5%, and experience the full crash again. The sequence is biblical in its cruelty.'
);

-- Scenario 10: 401k Enrollment
SET @s = (SELECT id FROM adventure_scenarios WHERE title = 'The 401k Enrollment');
INSERT INTO `adventure_choices`
    (scenario_id, choice_text, hint_text, difficulty,
     base_xp, base_gold, sort_order,
     success_narrative, failure_narrative,
     crit_success_narrative, crit_failure_narrative) VALUES
(@s,
 'Contribute the full match amount immediately',
 'Never leave employer match on the table. It is a 100% return.',
 7, 120, 35, 1,
 'You complete the form. 4% of your salary now earns a 100% return before market performance. Future you is grateful.',
 'The form is confusing and you accidentally enroll at 1% instead of 4%. Only partial match captured.',
 'You enroll at the full match, pick a target-date fund, and automate increases of 1% per year. Textbook execution.',
 'You miss the enrollment deadline. The window closes. You wait another year. The compounding you lost cannot be recovered.'
),
(@s,
 'Contribute more than the match — max it out',
 'The match is the floor, not the ceiling.',
 13, 155, 42, 2,
 'You set contributions to 15%. Future you is significantly wealthier for this decision made in a fluorescent HR office.',
 'The contribution is too aggressive for your current budget. You reduce it two months later to cover expenses.',
 'You max out the contribution, select a three-fund portfolio, and never touch it again. This is the way.',
 'The contribution strain causes you to rely on credit cards for expenses. The interest negates the tax advantage. Recalibrate.'
),
(@s,
 'Put it off until next quarter',
 'Delay is the most expensive habit.',
 4, 40, 10, 3,
 'Next quarter arrives and you actually do it. The delay costs you three months of compounding. Lesson noted.',
 'Next quarter becomes next year. Every quarter of delay is a quarter of match you will never recover.',
 'You put it off but then immediately automate a reminder and complete it the next morning. Minimal damage.',
 'It is three years later. The form is still in the email. The match has been sitting uncollected. A significant sum, gone.'
);

-- Scenario 11: Rent Increase
SET @s = (SELECT id FROM adventure_scenarios WHERE title = 'The Rent Increase Notice');
INSERT INTO `adventure_choices`
    (scenario_id, choice_text, hint_text, difficulty,
     base_xp, base_gold, sort_order,
     success_narrative, failure_narrative,
     crit_success_narrative, crit_failure_narrative) VALUES
(@s,
 'Write a professional letter citing your tenancy record and market data',
 'Evidence and professionalism are more powerful than emotion.',
 12, 140, 38, 1,
 'Your landlord responds within a week. The increase is reduced to $100. Your letter is referenced as the reason.',
 'Your landlord holds firm. The letter was professional but the market supports the increase. You pay it.',
 'Your landlord counters with a $50 increase and a two-year lease lock. You take it. Stability has value.',
 'Your landlord is insulted by the letter and declines to renew your lease at all. An expensive negotiation.'
),
(@s,
 'Offer to sign a longer lease in exchange for a smaller increase',
 'Stability has value to both parties.',
 10, 125, 32, 2,
 'Your landlord accepts a two-year lease at $100 above current rather than $200. You gain certainty and savings.',
 'Your landlord prefers the flexibility of annual leases. The offer is declined and the increase stands.',
 'Your landlord accepts and drops the increase to $75 and fixes the bathroom fan as a goodwill gesture.',
 'Your landlord uses the longer lease conversation to discuss other concerns they had. You leave with more obligations.'
),
(@s,
 'Accept the increase and start apartment hunting',
 'Sometimes the best negotiation is finding a better deal elsewhere.',
 8, 110, 28, 3,
 'You find a comparable apartment for $50 less than the new rate. The move costs you once but saves you monthly.',
 'The apartment hunt reveals the market is worse than your current situation. You renew at the higher rate anyway.',
 'You find a significantly better apartment at a lower price point. The move was the right call entirely.',
 'The moving costs, deposits, and time investment exceed two years of the rent increase. You have optimized yourself into a loss.'
);

-- Scenario 12: Subscription Audit
SET @s = (SELECT id FROM adventure_scenarios WHERE title = 'The Subscription Audit');
INSERT INTO `adventure_choices`
    (scenario_id, choice_text, hint_text, difficulty,
     base_xp, base_gold, sort_order,
     success_narrative, failure_narrative,
     crit_success_narrative, crit_failure_narrative) VALUES
(@s,
 'Cancel everything unused, keep only what you used this week',
 'Usage is the only valid justification for a subscription.',
 9, 115, 30, 1,
 'Four subscriptions cancelled. $67/month reclaimed. You do not miss a single one after the first week.',
 'The cancellation flows are deliberately difficult. You cancel two and give up on the others.',
 'You cancel six subscriptions and negotiate a retention discount on a seventh. $94/month recovered.',
 'One of the subscriptions was an annual auto-renew that just charged. Non-refundable. You cancel it anyway for next year.'
),
(@s,
 'Call to negotiate lower rates before cancelling',
 'Retention departments have authority to discount.',
 12, 135, 36, 2,
 'Three services offer discounted rates rather than lose you. You keep two and cancel the third. Net savings: $42/month.',
 'Only one service offers a discount. The others let you cancel without a fight. Mixed results.',
 'Every service either discounts or reveals a better plan tier. You save $71/month without cancelling anything.',
 'The negotiation calls take two hours. You save $8/month. Your time was worth more than that.'
),
(@s,
 'Keep them all — you might use them eventually',
 'Sunk cost thinking is expensive.',
 3, 30, 8, 3,
 'You review again next month with fresh eyes and cancel four. The delay cost you $67.',
 '"Eventually" does not arrive. The subscriptions renew annually. The gym membership is now three years old.',
 'You actually use all of them this month. Unusual but possible. No cancellations needed.',
 'Two years later you have added four more. The audit reveals $340/month in subscriptions. The eventually never came.'
);

-- Scenario 13: Emergency Fund Test
SET @s = (SELECT id FROM adventure_scenarios WHERE title = 'The Emergency Fund Test');
INSERT INTO `adventure_choices`
    (scenario_id, choice_text, hint_text, difficulty,
     base_xp, base_gold, sort_order,
     success_narrative, failure_narrative,
     crit_success_narrative, crit_failure_narrative) VALUES
(@s,
 'Use the emergency fund — this is exactly what it is for',
 'The fund exists for this moment. Trust it.',
 7, 120, 32, 1,
 'You pay the $900 in cash. The fund drops to $300. You rebuild it over the next three months. The system worked.',
 'You use the fund but feel guilty enough to put the repair on the card also. You pay twice from different directions.',
 'You use the fund, rebuild it in six weeks, and take the incident as motivation to increase the fund target. Clean.',
 'You drain the fund and two weeks later another emergency arrives. The fund is gone. The credit card is not.'
),
(@s,
 'Split it — half from savings, half from card',
 'Hedging feels safe. The math may disagree.',
 9, 110, 28, 2,
 'You pay $450 from each. The card balance is cleared in one paycheck. Reasonable.',
 'The card balance lingers at $450 for four months. Interest turns a $450 charge into $510.',
 'The split works perfectly and you pay the card in full before interest accrues. Efficient.',
 'The card balance becomes the foundation of a larger balance. The $450 grows. The emergency fund does not rebuild.'
),
(@s,
 'Put the whole thing on the credit card',
 'The fund exists so this card never has to.',
 5, 55, 12, 3,
 'You pay it off completely within two weeks. The interest charge is $3. Acceptable, if unnecessary.',
 '22% APR on $900 accrues faster than expected. The balance grows before it shrinks.',
 'You pay it off same-day with a planned fund transfer. The card was used but the logic was sound.',
 'The $900 grows to $1,200 through interest and minimum payments. Your emergency fund sits untouched while the card charges you monthly.'
);

-- =============================================================================
-- Update settings
-- =============================================================================
INSERT INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('adventure_enabled', '1', 'Enable the Go Adventuring module')
ON DUPLICATE KEY UPDATE description = VALUES(description);

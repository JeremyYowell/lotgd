-- =============================================================================
-- LEGENDS OF THE GREEN DOLLAR
-- Adventure Scenarios — Batch 02 (12 new scenarios, 2 per category)
-- Run against both lotgd_dev and lotgd_prod
-- =============================================================================

-- -------------------------------------------------------
-- SHOPPING SCENARIOS
-- -------------------------------------------------------
INSERT INTO `adventure_scenarios`
    (title, description, flavor_text, category, min_level, max_level) VALUES
(
    'The Checkout Lane Gauntlet',
    'You place your items on the conveyor belt with quiet satisfaction. Your list is complete, your budget intact. Then the cashier asks if you would like to add a donation, a store loyalty card, an extended warranty on your $12 spatula, and a magazine currently "marked down" beside the register.',
    'The register is the final boss.',
    'shopping', 1, 15
),
(
    'The Flash Sale Frenzy',
    'An email arrives: 40% off sitewide, today only, ending at midnight. The countdown timer pulses red. Items in your cart are "almost gone." You were not planning to buy anything today, but the math seems compelling.',
    'Urgency is a manufacturing process.',
    'shopping', 8, 50
);

-- -------------------------------------------------------
-- WORK SCENARIOS
-- -------------------------------------------------------
INSERT INTO `adventure_scenarios`
    (title, description, flavor_text, category, min_level, max_level) VALUES
(
    'The Side Hustle Launch',
    'A neighbor needs a website. A friend needs some bookkeeping. Someone posted online asking for exactly your skill set. This could be meaningful extra income, or it could devour your evenings for underwhelming pay. The client is eager. The contract does not exist yet.',
    'The second job that might become the first one.',
    'work', 2, 20
),
(
    'The Corporate Restructuring',
    'The company has announced a reorganization. Your department may be affected. A recruiter from a competitor reached out last month and you never responded. Your manager has gotten quiet and started closing his office door more often. This is the moment to act — or wait.',
    'The calm before the restructuring.',
    'work', 10, 50
);

-- -------------------------------------------------------
-- BANKING SCENARIOS
-- -------------------------------------------------------
INSERT INTO `adventure_scenarios`
    (title, description, flavor_text, category, min_level, max_level) VALUES
(
    'The Balance Transfer Gambit',
    'A credit card offer arrives promising 0% APR on balance transfers for 18 months. There is a 3% transfer fee. You carry $4,000 in credit card debt at 22% interest. The math is compelling. The terms are in six-point font.',
    'Free money, for now.',
    'banking', 5, 25
),
(
    'The Investment Property Pitch',
    'A colleague has assembled a group to buy a rental property. They need a tenth partner. Your share would be $8,000. The projected return is 11% annually. The contract is twelve pages and mentions "capital calls" in a footnote.',
    'Illiquid assets and fragile friendships.',
    'banking', 12, 50
);

-- -------------------------------------------------------
-- INVESTING SCENARIOS
-- -------------------------------------------------------
INSERT INTO `adventure_scenarios`
    (title, description, flavor_text, category, min_level, max_level) VALUES
(
    'The Market Timing Temptation',
    'Everything seems to point the same direction — or so it feels. The economy looks shaky. Three financial podcasters you follow all went to cash last month. Your automated contributions are still buying in. Your finger hovers over the pause button.',
    'The oracle is always wrong eventually.',
    'investing', 7, 25
),
(
    'The Real Estate Seminar',
    'A glossy invitation arrived for a free real estate wealth-building seminar at a hotel conference room. Free dinner included. Seventeen testimonials on the back of the card. A mentor once told you: the free dinner always costs something.',
    'Nothing is free inside the conference room.',
    'investing', 15, 50
);

-- -------------------------------------------------------
-- HOUSING SCENARIOS
-- -------------------------------------------------------
INSERT INTO `adventure_scenarios`
    (title, description, flavor_text, category, min_level, max_level) VALUES
(
    'The Home Warranty Upsell',
    'You just bought a used appliance and the seller is offering a home warranty add-on for $350 per year. It covers most things, with a $75 service call fee per incident and exclusions for pre-existing conditions. The appliance is eight years old.',
    'Insurance for things that might not break.',
    'housing', 3, 20
),
(
    'The First Homebuyer''s Dilemma',
    'You are pre-approved for more than you want to spend. The realtor keeps showing you homes at the top of your approval range, not the top of your budget. "You can always grow into it," she says. You have been shown four houses you could technically afford and one you actually could.',
    'Pre-approval is not a budget.',
    'housing', 10, 50
);

-- -------------------------------------------------------
-- DAILY LIFE SCENARIOS
-- -------------------------------------------------------
INSERT INTO `adventure_scenarios`
    (title, description, flavor_text, category, min_level, max_level) VALUES
(
    'The Lifestyle Creep Reckoning',
    'You got a raise. A good one. After taxes it is about $400 more per month. You have already started noticing where it is going — a nicer gym, food delivery twice a week, the streaming tier with 4K. None of it was a decision. All of it happened.',
    'The cost of a raise, hidden in the rounding.',
    'daily_life', 5, 20
),
(
    'The Inheritance Windfall',
    'A distant relative has left you $12,000. It arrived as a wire transfer on a Tuesday with no ceremony. Your family has opinions. Your financial feeds have opinions. Somewhere underneath the noise, you have to decide what to do with found money.',
    'The truest test of a financial value system.',
    'daily_life', 15, 50
);

-- =============================================================================
-- CHOICES FOR EACH NEW SCENARIO
-- =============================================================================

-- Scenario: The Checkout Lane Gauntlet
SET @s = (SELECT id FROM adventure_scenarios WHERE title = 'The Checkout Lane Gauntlet');
INSERT INTO `adventure_choices`
    (scenario_id, choice_text, hint_text, difficulty,
     base_xp, base_gold, sort_order,
     success_narrative, failure_narrative,
     crit_success_narrative, crit_failure_narrative) VALUES
(@s,
 'Decline everything politely and pay',
 'No thank you is a complete sentence.',
 8, 90, 22, 1,
 'No thank you, no thank you, no thank you, no. Your total is exactly what you planned. You tap your card and walk away clean.',
 'The cashier''s persistence wears you down on the loyalty card. You now have a card to a store you visit twice a year.',
 'You decline with such calm authority that the cashier stops mid-pitch. You pay and exit before the receipt finishes printing.',
 'You end up with a loyalty card, a warranty on the spatula, a celebrity gossip magazine, and a donation receipt. Your total is $34 over budget.'
),
(@s,
 'Sign up for the loyalty card — it might be worth it',
 'Run the math before you hand over your email.',
 10, 80, 18, 2,
 'The loyalty card gives you 5% back. You do the math — you will break even in three visits. Acceptable.',
 'The loyalty card requires an app, a linked payment method, and a verified email. You get 200 points worth $0.80.',
 'The loyalty card has a sign-up bonus that covers today''s entire purchase. You did not expect this. Neither did the cashier.',
 'Enrollment takes twelve minutes, slows the line, and grants you access to exclusive member pricing on nothing you want.'
);

-- Scenario: The Flash Sale Frenzy
SET @s = (SELECT id FROM adventure_scenarios WHERE title = 'The Flash Sale Frenzy');
INSERT INTO `adventure_choices`
    (scenario_id, choice_text, hint_text, difficulty,
     base_xp, base_gold, sort_order,
     success_narrative, failure_narrative,
     crit_success_narrative, crit_failure_narrative) VALUES
(@s,
 'Close the tab and revisit in 48 hours',
 'Real need survives the countdown timer.',
 11, 120, 30, 1,
 'You close the tab. 48 hours later, the sale is over and you don''t miss anything you didn''t buy.',
 'You close the tab. You reopen it four minutes later. The checkout completes before you fully decide to.',
 'You don''t open the email at all. The want passes by morning. The money remains entirely yours.',
 'You close the tab but told a friend about the sale first. They send you links. You somehow buy from their cart too.'
),
(@s,
 'Only buy the one item you actually needed',
 'The cart is not a commitment. The checkout button is.',
 9, 105, 25, 2,
 'One item. Checkout. Done. The recommended products section does not tempt you today.',
 'The recommended products appear. One has good reviews. Then another. The cart grows to seven items.',
 'You buy exactly the item at a genuine discount, skip the upsell, and feel completely at peace about it.',
 'The one item has a "complete the set" bundle for only $20 more. You buy the bundle. The set is still somehow incomplete.'
),
(@s,
 'Fill the cart — you needed most of this stuff eventually',
 'Eventually is doing a lot of work in that sentence.',
 5, 50, 12, 3,
 'Several items were genuinely needed. The sale price beats what you would have paid anyway. Not a disaster.',
 'Half the cart is aspirational. You will return three items in two weeks, but not five.',
 'Every item in your cart was actually on a list somewhere. The sale timing was perfect. This was real savings, not manufactured urgency.',
 'The sale ends in fifteen minutes. You panic-buy. Three items do not fit. One was for a hobby you tried once in 2019.'
);

-- Scenario: The Side Hustle Launch
SET @s = (SELECT id FROM adventure_scenarios WHERE title = 'The Side Hustle Launch');
INSERT INTO `adventure_choices`
    (scenario_id, choice_text, hint_text, difficulty,
     base_xp, base_gold, sort_order,
     success_narrative, failure_narrative,
     crit_success_narrative, crit_failure_narrative) VALUES
(@s,
 'Quote your market rate and set clear scope in writing',
 'A vague agreement always resolves in their favor.',
 12, 130, 35, 1,
 'You send a professional proposal with a clear scope, rate, and timeline. They accept. The project runs smoothly. You get paid on time.',
 'Your rate is above their budget. They counter with equity in their idea. You politely decline and move on.',
 'They accept your rate immediately, refer you to two other clients, and leave a glowing review before the project even ends.',
 'The scope was clear on your end. They had other ideas. The project doubles in size, the rate stays the same, and you learn what a contract clause costs to not have.'
),
(@s,
 'Agree to a lower rate to build your portfolio',
 'Discounted rates attract discounted respect. Sometimes.',
 8, 90, 20, 2,
 'The low rate was worth it — the portfolio piece opens a door to a better-paid client shortly after.',
 'The low-rate client treats you like a full-time employee, expects endless revisions, and your portfolio piece looks like their idea, not yours.',
 'The low-rate project goes so well they voluntarily pay you above the agreed amount. They say you were worth ten times what they paid.',
 'Three months in, one low-rate client, nothing to show, and you have lost the evenings you needed to find better work.'
);

-- Scenario: The Corporate Restructuring
SET @s = (SELECT id FROM adventure_scenarios WHERE title = 'The Corporate Restructuring');
INSERT INTO `adventure_choices`
    (scenario_id, choice_text, hint_text, difficulty,
     base_xp, base_gold, sort_order,
     success_narrative, failure_narrative,
     crit_success_narrative, crit_failure_narrative) VALUES
(@s,
 'Update your resume and respond to the recruiter today',
 'The best time to look is before you have to.',
 13, 160, 42, 1,
 'The recruiter still has the position open. You interview, impress, and have an offer before the restructuring is announced.',
 'The recruiter filled the role last week. You update your resume and begin the search. The timing stings.',
 'The recruiter has two openings. You interview for both the same week, receive competing offers, and choose the better one. You leave before anything is announced.',
 'Your manager sees the resume update in a shared folder. The conversation that follows is uncomfortable for everyone involved.'
),
(@s,
 'Schedule a direct conversation with your manager',
 'Information is better than speculation.',
 11, 140, 36, 2,
 'Your manager respects the directness. Your role is being preserved. You leave knowing more than when you arrived.',
 'Your manager is vague and reassuring in ways that are not reassuring. You leave knowing nothing useful.',
 'Your manager tells you that you are being considered for a leadership role in the new structure. Your directness impressed the right person.',
 'Your manager reports the conversation upward. You are now on a list you did not want to be on.'
),
(@s,
 'Wait and see — restructurings often blow over',
 'Patience and paralysis look identical from the outside.',
 6, 60, 15, 3,
 'The restructuring spares your role. You were right to wait. The anxiety was the worst part.',
 'Your role is eliminated. You had two weeks of warning and no plan. The severance is two weeks.',
 'The restructuring actually elevates your department. You are offered a raise to stay. Patience paid unexpectedly well.',
 'You waited so long the recruiter stopped returning messages. The restructuring is not kind. You start the job search from zero.'
);

-- Scenario: The Balance Transfer Gambit
SET @s = (SELECT id FROM adventure_scenarios WHERE title = 'The Balance Transfer Gambit');
INSERT INTO `adventure_choices`
    (scenario_id, choice_text, hint_text, difficulty,
     base_xp, base_gold, sort_order,
     success_narrative, failure_narrative,
     crit_success_narrative, crit_failure_narrative) VALUES
(@s,
 'Calculate the fee vs. interest savings and transfer if it wins',
 'The math is always worth doing.',
 12, 135, 35, 1,
 'The math is clear: $120 transfer fee versus $880 in interest you would otherwise pay. You transfer, set autopay, and clear the balance before the promotional rate expires.',
 'The calculation favors the transfer, but you do not set autopay. Month 19 arrives. The deferred interest posts all at once.',
 'You transfer, automate aggressive payments, and clear the debt in 14 months. You save over $700 and close the account cleanly.',
 'The 0% rate applies to purchases, not transfers — a distinction buried in paragraph four. The transfer posts at 22% plus the fee.'
),
(@s,
 'Ignore the offer and attack the debt with extra payments',
 'No new accounts. No new complexity.',
 10, 120, 28, 2,
 'No transfer fees, no new accounts. You put an extra $200 per month toward the balance. It is gone in 16 months.',
 'Good intention, inconsistent execution. The extra payments do not materialize reliably. The debt lingers.',
 'A bonus arrives at exactly the right moment. You put it all toward the debt and clear it in four months. The offer was irrelevant.',
 'You ignore the offer and make only minimum payments. Two years and $900 in interest later, the balance has barely moved.'
);

-- Scenario: The Investment Property Pitch
SET @s = (SELECT id FROM adventure_scenarios WHERE title = 'The Investment Property Pitch');
INSERT INTO `adventure_choices`
    (scenario_id, choice_text, hint_text, difficulty,
     base_xp, base_gold, sort_order,
     success_narrative, failure_narrative,
     crit_success_narrative, crit_failure_narrative) VALUES
(@s,
 'Read the full contract and ask about the capital call clause',
 'The footnotes are where the surprises live.',
 13, 150, 40, 1,
 'The capital call clause means you could be asked for more money without warning. You negotiate a cap. They agree. You join with protection in place.',
 'They explain the capital call away with confidence. You do not ask follow-up questions. You sign. You hope for the best.',
 'Reading the contract reveals two other problematic clauses. You negotiate all three. The group respects your diligence and the investment becomes a good one.',
 'You ask about the capital call and they say it''s standard. You sign. A year later the call arrives. You are not prepared for the amount.'
),
(@s,
 'Decline and invest the $8,000 in index funds instead',
 'Liquid, diversified, no capital calls.',
 9, 115, 28, 2,
 'Index funds. Liquid, diversified, no capital calls. Eight months later your colleague''s property has a vacancy problem. You sleep fine.',
 'You decline and leave the $8,000 in a checking account temporarily. It is absorbed into expenses over the next few months.',
 'You invest the $8,000 in a low-cost index fund. The property deal eventually unravels. You avoided the drama and kept the gains.',
 'You decline the investment and park the $8,000 in a savings account at 0.01% APY. Inflation is patient and unkind.'
);

-- Scenario: The Market Timing Temptation
SET @s = (SELECT id FROM adventure_scenarios WHERE title = 'The Market Timing Temptation');
INSERT INTO `adventure_choices`
    (scenario_id, choice_text, hint_text, difficulty,
     base_xp, base_gold, sort_order,
     success_narrative, failure_narrative,
     crit_success_narrative, crit_failure_narrative) VALUES
(@s,
 'Leave the automated contributions running',
 'Time in market. Every time.',
 10, 125, 30, 1,
 'You do nothing. The market dips another 8% and recovers the next quarter. Your cost basis is excellent. Dollar-cost averaging worked exactly as intended.',
 'The market drops another 15% after you stay in. Logically you know this is fine. Emotionally, you check your balance too often.',
 'The dip buys you fractional shares at a discount. The recovery is sharp. Your quarterly statement shows growth that would not have happened if you had paused.',
 'Contributions run. The market falls for six months. The podcasters say they told you so. You pause at exactly the wrong time.'
),
(@s,
 'Pause contributions and wait for clarity',
 'Clarity never arrives on schedule.',
 8, 80, 18, 2,
 'You pause, the market dips, then recovers. You restart contributions. The timing cost some gains but not critically.',
 'The pause extends three months. The market recovers before you restart. You missed the bottom and paid for it in gains.',
 'You pause, the market falls further, and you restart with extra cash near the bottom. This was luck, not skill — but it worked.',
 'The pause becomes indefinite. You never find the clarity you were waiting for. Three years of compounding is gone.'
),
(@s,
 'Move everything to cash until the picture clears',
 'The picture never clears. That is the market.',
 5, 55, 12, 3,
 'The market falls 20% after you go to cash. You move back in near the bottom. This was fortunate, not repeatable, and you know it.',
 'The market rises 12% the week after you go to cash. You miss the rally. The picture never gets clearer. You sold low and missed high.',
 'Impossible luck: you time both exit and entry nearly perfectly. You put it all back in an index fund immediately and never try again.',
 'You go to cash, miss a 30% bull run, and owe capital gains taxes on the sale. The picture never clarified. You lost money doing nothing.'
);

-- Scenario: The Real Estate Seminar
SET @s = (SELECT id FROM adventure_scenarios WHERE title = 'The Real Estate Seminar');
INSERT INTO `adventure_choices`
    (scenario_id, choice_text, hint_text, difficulty,
     base_xp, base_gold, sort_order,
     success_narrative, failure_narrative,
     crit_success_narrative, crit_failure_narrative) VALUES
(@s,
 'Skip it entirely',
 'The time saved is worth more than the dinner.',
 8, 110, 25, 1,
 'You do not go. You spend the evening reviewing your actual investment accounts. This was the right call.',
 'You skip the seminar but spend the evening doom-scrolling real estate influencers instead. Same outcome, slower.',
 'You skip it, spend the evening reading about index fund investing, and find two actionable improvements to your own portfolio. Free and genuinely useful.',
 'You skip the seminar but your friend goes and calls you about the system they bought for the next three months. Secondhand damage.'
),
(@s,
 'Attend for the free dinner — commit to nothing',
 'You can eat the food without buying the system.',
 12, 130, 32, 2,
 'The dinner is decent. The pitch is transparent. You eat, thank them politely, and decline the $3,000 mentorship program with practiced calm.',
 'The dinner is decent. The pitch is more sophisticated than expected. You leave with a brochure and a follow-up appointment you did not want.',
 'You eat the dinner, loudly decline the upsell, and accidentally talk two other attendees out of buying as you leave. You feel like a hero.',
 'The dinner is $14 of food. The follow-up pitch is a $4,500 advanced seminar. You pay it before you fully realize what happened.'
);

-- Scenario: The Home Warranty Upsell
SET @s = (SELECT id FROM adventure_scenarios WHERE title = 'The Home Warranty Upsell');
INSERT INTO `adventure_choices`
    (scenario_id, choice_text, hint_text, difficulty,
     base_xp, base_gold, sort_order,
     success_narrative, failure_narrative,
     crit_success_narrative, crit_failure_narrative) VALUES
(@s,
 'Decline and put the $350 in a dedicated repair fund',
 'Self-insuring is often cheaper than buying insurance.',
 10, 115, 28, 1,
 'The appliance runs fine for two years. Your repair fund now holds $700. A real repair bill would have been covered twice over.',
 'The appliance breaks six months in. The repair is $480. The warranty would have cost $350 plus the service fee. You came close.',
 'The appliance runs fine for four years. Your repair fund grew with interest. When it finally dies you replace it entirely in cash.',
 'The appliance breaks in month two. The repair is $600. You had $350 saved. The difference comes from somewhere less comfortable.'
),
(@s,
 'Buy the warranty — repairs are expensive and unpredictable',
 'Peace of mind has a price. Know what you''re paying.',
 8, 80, 20, 2,
 'The appliance needs a $400 repair in year one. The warranty covers it minus the service fee. You break even.',
 'The appliance runs fine all year. The warranty expires. You paid $350 for peace of mind you could have manufactured yourself.',
 'Two major repairs in year one, both covered. You come out $500 ahead. The warranty paid for itself and then some.',
 'The repair is classified as a pre-existing condition. The warranty does not cover it. You paid $350 for a document that said no.'
);

-- Scenario: The First Homebuyer's Dilemma
SET @s = (SELECT id FROM adventure_scenarios WHERE title = 'The First Homebuyer''s Dilemma');
INSERT INTO `adventure_choices`
    (scenario_id, choice_text, hint_text, difficulty,
     base_xp, base_gold, sort_order,
     success_narrative, failure_narrative,
     crit_success_narrative, crit_failure_narrative) VALUES
(@s,
 'Establish your real budget ceiling and filter to it',
 'Your approval limit is the bank''s number. Your budget is yours.',
 12, 145, 38, 1,
 'You communicate the number clearly. The realtor adjusts. One of the smaller homes is exactly right. You buy it without stretching.',
 'You name the ceiling but the realtor just wants to show you one more. The one more is beautiful. Your resolve weakens.',
 'You buy a home $60,000 under your approval limit. The lower payment gives you cash flow for investments. Years later this decision compounds spectacularly.',
 'You name the ceiling. The realtor ignores it. You fall in love with a house $80,000 above it. You offer anyway. The budget is broken before you move in.'
),
(@s,
 'Buy at the top of your approval — you expect your income to grow',
 'Projections are not guarantees.',
 7, 70, 16, 2,
 'Income does grow. The payment becomes manageable. You got fortunate that the projection held.',
 'Income does not grow as fast as projected. The payment is a weight. Every unexpected expense becomes a crisis.',
 'Income grows faster than expected and you refinance into a better rate two years later. The stretch was worth it this time.',
 'The payment is fine until a job change, a car repair, and a medical bill arrive in the same quarter. The margin was not there. The house sells two years later at a loss.'
);

-- Scenario: The Lifestyle Creep Reckoning
SET @s = (SELECT id FROM adventure_scenarios WHERE title = 'The Lifestyle Creep Reckoning');
INSERT INTO `adventure_choices`
    (scenario_id, choice_text, hint_text, difficulty,
     base_xp, base_gold, sort_order,
     success_narrative, failure_narrative,
     crit_success_narrative, crit_failure_narrative) VALUES
(@s,
 'Automate the full raise into savings before you adapt to it',
 'Your lifestyle cannot spend money it never sees.',
 12, 135, 35, 1,
 'You adjust the automatic transfer before the first paycheck arrives. The lifestyle never adapts to the new number. The savings grow visibly.',
 'You set up the transfer a week after the first paycheck. Lifestyle adapts faster than expected. You scale the transfer back.',
 'Full raise into investments. Your lifestyle does not notice. A year later you have deployed $4,800 in new capital without ever feeling the absence.',
 'You automate it, then move the money out for a temporary expense. Temporary becomes permanent. The lifestyle creep won anyway.'
),
(@s,
 'Give yourself one deliberate upgrade and save the rest',
 'Intention beats accident every time.',
 10, 115, 28, 2,
 'One deliberate upgrade: a gym, a hobby, something real. The rest goes to savings. Intentional rather than accidental.',
 'The modest upgrade expands. One becomes three. The rest is smaller than planned.',
 'The deliberate upgrade genuinely improves your quality of life and the remainder compounds. This is exactly what a raise is for.',
 'Each choice felt modest individually. Together they consumed the entire raise. There are no new savings, only new subscriptions.'
),
(@s,
 'Enjoy the raise — life is short',
 'Enjoyment and intention are not mutually exclusive.',
 5, 55, 12, 3,
 'You spend freely and genuinely enjoy it. The spending is time-limited. You rein it in after three months with something real to show for it.',
 'Enjoying it becomes the baseline. The new expenses stop feeling like expenses. They feel like necessities.',
 'You have a genuinely wonderful few months, then refocus and still manage to save something meaningful. Life and finances coexisted.',
 'The raise is invisible six months later. The account balance looks the same as before. The only evidence is the slightly nicer gym and four new streaming services.'
);

-- Scenario: The Inheritance Windfall
SET @s = (SELECT id FROM adventure_scenarios WHERE title = 'The Inheritance Windfall');
INSERT INTO `adventure_choices`
    (scenario_id, choice_text, hint_text, difficulty,
     base_xp, base_gold, sort_order,
     success_narrative, failure_narrative,
     crit_success_narrative, crit_failure_narrative) VALUES
(@s,
 'Pay off high-interest debt first, invest the rest',
 'A guaranteed return beats a speculative one.',
 13, 160, 42, 1,
 'You clear two credit cards and invest the remainder in a brokerage account. The debt payoff is a guaranteed return. The investment compounds from day one.',
 'You pay off some debt but the remainder goes to an invest-later account that quietly becomes a spending account.',
 'Zero debt. Maximum remainder invested. The psychological weight lifts immediately. The money works in two ways simultaneously.',
 'You pay off one card and feel such relief that you treat yourself with the rest. The other card remains. The moment is gone.'
),
(@s,
 'Put the full amount into a low-cost index fund',
 'The best time to invest was always now.',
 11, 140, 36, 2,
 'Lump sum invested. The market is unpredictable short-term, but in twenty years this decision will look obvious.',
 'You invest it but choose individual stocks on advice from a family member. Half underperform the index.',
 'Full lump sum into a total market fund. The timing turns out to be excellent. Five years later you wonder what you would have bought instead.',
 'You invest it but check the balance daily. A dip in month two prompts a panic sell. You are back to cash, minus the gains you briefly had.'
),
(@s,
 'Spend a meaningful portion on something lasting, invest the rest',
 'Compound interest and a good memory are not mutually exclusive.',
 8, 100, 25, 3,
 'A portion goes to something you have delayed for years — a trip, a repair, a course. It was worth it. The rest is invested.',
 'The meaningful portion expands. The rest shrinks. The investment happens in a smaller amount than intended.',
 'The experience delivers genuine lasting value. The invested remainder grows. You made peace with both halves of the decision.',
 'The spend felt meaningful at the time. It does not later. The remainder never got invested. The $12,000 is just a memory and a few receipts.'
);

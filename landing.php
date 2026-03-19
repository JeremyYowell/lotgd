<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Legends of the Green Dollar — The Financial RPG</title>
<meta name="description" content="A multiplayer fantasy RPG. Go on adventures, build an S&P 500 portfolio, and compete for glory in the Realm of Fiscal Destiny.">
<meta property="og:title" content="Legends of the Green Dollar">
<meta property="og:description" content="A multiplayer fantasy RPG. Adventure, invest, equip, and compete.">
<meta property="og:type" content="website">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cinzel+Decorative:wght@700&family=Cinzel:wght@400;600;700&family=Crimson+Pro:ital,wght@0,300;0,400;0,600;1,300;1,400&display=swap" rel="stylesheet">
<style>
/* ============================================================
   RESET & BASE
============================================================ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --gold:        #d4a017;
    --gold-light:  #f0d980;
    --gold-dark:   #8a6a1a;
    --bg:          #07090f;
    --bg-card:     #0e1420;
    --bg-mid:      #111827;
    --border:      #1e2d45;
    --border-gold: #8a6a1a;
    --text:        #c8d8e8;
    --text-muted:  #6b82a0;
    --text-dim:    #3d5070;
    --green:       #22c55e;
    --red:         #ef4444;
    --blue:        #3b82f6;
}

html { scroll-behavior: smooth; }

body {
    background: var(--bg);
    color: var(--text);
    font-family: 'Crimson Pro', Georgia, serif;
    font-size: 18px;
    line-height: 1.7;
    overflow-x: hidden;
}

a { color: var(--gold); text-decoration: none; transition: color 0.2s; }
a:hover { color: var(--gold-light); }

/* ============================================================
   NOISE TEXTURE OVERLAY
============================================================ */
body::before {
    content: '';
    position: fixed;
    inset: 0;
    background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");
    pointer-events: none;
    z-index: 1000;
    opacity: 0.4;
}

/* ============================================================
   NAV
============================================================ */
.site-nav {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 100;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem 2.5rem;
    background: rgba(7, 9, 15, 0.85);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid rgba(138, 106, 26, 0.2);
    transition: border-color 0.3s;
}

.nav-brand {
    font-family: 'Cinzel', serif;
    font-size: 1rem;
    font-weight: 700;
    color: var(--gold-light);
    letter-spacing: 0.1em;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.nav-links {
    display: flex;
    align-items: center;
    gap: 2rem;
    font-family: 'Cinzel', serif;
    font-size: 0.72rem;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.nav-links a { color: var(--text-muted); }
.nav-links a:hover { color: var(--gold-light); }

.nav-cta {
    background: var(--gold);
    color: var(--bg) !important;
    padding: 0.45rem 1.25rem;
    border-radius: 4px;
    font-weight: 700;
    transition: background 0.2s !important;
}
.nav-cta:hover { background: var(--gold-light) !important; }

/* ============================================================
   HERO
============================================================ */
.hero {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 8rem 2rem 6rem;
    position: relative;
    overflow: hidden;
}

/* Radial glow behind hero */
.hero::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -60%);
    width: 900px;
    height: 600px;
    background: radial-gradient(ellipse, rgba(212, 160, 23, 0.07) 0%, transparent 70%);
    pointer-events: none;
}

/* Decorative corner ornaments */
.hero-ornament {
    position: absolute;
    width: 120px;
    height: 120px;
    opacity: 0.15;
}
.hero-ornament.tl { top: 80px; left: 40px; border-top: 1px solid var(--gold); border-left: 1px solid var(--gold); }
.hero-ornament.tr { top: 80px; right: 40px; border-top: 1px solid var(--gold); border-right: 1px solid var(--gold); }
.hero-ornament.bl { bottom: 40px; left: 40px; border-bottom: 1px solid var(--gold); border-left: 1px solid var(--gold); }
.hero-ornament.br { bottom: 40px; right: 40px; border-bottom: 1px solid var(--gold); border-right: 1px solid var(--gold); }

.hero-eyebrow {
    font-family: 'Cinzel', serif;
    font-size: 0.7rem;
    letter-spacing: 0.3em;
    text-transform: uppercase;
    color: var(--gold);
    margin-bottom: 1.5rem;
    animation: fadeUp 0.8s ease both;
}

.hero-title {
    font-family: 'Cinzel Decorative', serif;
    font-size: clamp(2.4rem, 6vw, 5rem);
    color: var(--gold-light);
    line-height: 1.15;
    letter-spacing: 0.04em;
    margin-bottom: 0.5rem;
    animation: fadeUp 0.8s 0.1s ease both;
    text-shadow: 0 0 60px rgba(212, 160, 23, 0.25);
}

.hero-subtitle {
    font-family: 'Cinzel', serif;
    font-size: clamp(1rem, 2vw, 1.4rem);
    color: var(--gold-dark);
    letter-spacing: 0.15em;
    text-transform: uppercase;
    margin-bottom: 2rem;
    animation: fadeUp 0.8s 0.15s ease both;
}

.hero-desc {
    font-size: clamp(1rem, 1.5vw, 1.25rem);
    color: var(--text-muted);
    max-width: 600px;
    margin: 0 auto 3rem;
    font-weight: 300;
    line-height: 1.8;
    animation: fadeUp 0.8s 0.2s ease both;
}

.hero-ctas {
    display: flex;
    gap: 1rem;
    justify-content: center;
    flex-wrap: wrap;
    animation: fadeUp 0.8s 0.3s ease both;
}

.btn-primary {
    background: var(--gold);
    color: var(--bg);
    font-family: 'Cinzel', serif;
    font-size: 0.82rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    padding: 1rem 2.5rem;
    border-radius: 4px;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-block;
}
.btn-primary:hover {
    background: var(--gold-light);
    color: var(--bg);
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(212, 160, 23, 0.35);
}

.btn-secondary {
    background: transparent;
    color: var(--gold);
    font-family: 'Cinzel', serif;
    font-size: 0.82rem;
    font-weight: 600;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    padding: 1rem 2.5rem;
    border-radius: 4px;
    border: 1px solid var(--border-gold);
    cursor: pointer;
    transition: all 0.2s;
    display: inline-block;
}
.btn-secondary:hover {
    background: rgba(212, 160, 23, 0.08);
    color: var(--gold-light);
    border-color: var(--gold);
}

.hero-stats {
    display: flex;
    gap: 3rem;
    justify-content: center;
    margin-top: 4rem;
    padding-top: 2.5rem;
    border-top: 1px solid var(--border);
    animation: fadeUp 0.8s 0.4s ease both;
}

.hero-stat-val {
    font-family: 'Cinzel', serif;
    font-size: 1.8rem;
    font-weight: 700;
    color: var(--gold-light);
    display: block;
}

.hero-stat-label {
    font-size: 0.75rem;
    color: var(--text-dim);
    letter-spacing: 0.1em;
    text-transform: uppercase;
    font-family: 'Cinzel', serif;
}

/* ============================================================
   SCREENSHOT SHOWCASE
============================================================ */
.showcase {
    padding: 6rem 2rem;
    max-width: 1100px;
    margin: 0 auto;
}

.section-label {
    font-family: 'Cinzel', serif;
    font-size: 0.68rem;
    letter-spacing: 0.3em;
    text-transform: uppercase;
    color: var(--gold);
    text-align: center;
    margin-bottom: 1rem;
}

.section-title {
    font-family: 'Cinzel', serif;
    font-size: clamp(1.6rem, 3vw, 2.4rem);
    color: var(--gold-light);
    text-align: center;
    margin-bottom: 1rem;
    letter-spacing: 0.04em;
}

.section-desc {
    color: var(--text-muted);
    text-align: center;
    max-width: 560px;
    margin: 0 auto 3.5rem;
    font-weight: 300;
}

/* Screenshot placeholder */
.screenshot-main {
    width: 100%;
    aspect-ratio: 16/9;
    background: var(--bg-card);
    border: 1px solid var(--border-gold);
    border-radius: 12px;
    overflow: hidden;
    position: relative;
    margin-bottom: 1.5rem;
    box-shadow: 0 24px 60px rgba(0,0,0,0.6), 0 0 0 1px rgba(212,160,23,0.1);
}

.screenshot-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 1rem;
    color: var(--text-dim);
    font-family: 'Cinzel', serif;
    font-size: 0.75rem;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    background: linear-gradient(135deg, #0a0d14 0%, #111827 100%);
}

.screenshot-placeholder .ph-icon { font-size: 2.5rem; opacity: 0.4; }
.screenshot-placeholder .ph-title { color: var(--text-muted); font-size: 0.8rem; }
.screenshot-placeholder .ph-desc { color: var(--text-dim); font-size: 0.68rem; max-width: 300px; text-align: center; font-family: 'Crimson Pro', serif; text-transform: none; letter-spacing: 0; }

.screenshot-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
}

.screenshot-sm {
    aspect-ratio: 4/3;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 8px;
    overflow: hidden;
    transition: border-color 0.2s, transform 0.2s;
}
.screenshot-sm:hover { border-color: var(--border-gold); transform: translateY(-3px); }

/* ============================================================
   FEATURES GRID
============================================================ */
.features {
    padding: 6rem 2rem;
    background: linear-gradient(180deg, transparent 0%, rgba(14,20,32,0.8) 20%, rgba(14,20,32,0.8) 80%, transparent 100%);
}

.features-inner {
    max-width: 1100px;
    margin: 0 auto;
}

.features-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1.5rem;
    margin-top: 3.5rem;
}

.feature-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 2rem;
    transition: border-color 0.3s, transform 0.3s;
    position: relative;
    overflow: hidden;
}

.feature-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, var(--gold-dark), transparent);
    opacity: 0;
    transition: opacity 0.3s;
}

.feature-card:hover {
    border-color: var(--border-gold);
    transform: translateY(-4px);
}
.feature-card:hover::before { opacity: 1; }

.feature-icon {
    font-size: 2rem;
    margin-bottom: 1rem;
    display: block;
}

.feature-title {
    font-family: 'Cinzel', serif;
    font-size: 0.95rem;
    font-weight: 700;
    color: var(--gold-light);
    letter-spacing: 0.06em;
    margin-bottom: 0.75rem;
}

.feature-desc {
    color: var(--text-muted);
    font-size: 0.95rem;
    line-height: 1.6;
    font-weight: 300;
}

/* ============================================================
   HOW IT WORKS
============================================================ */
.how-it-works {
    padding: 6rem 2rem;
    max-width: 900px;
    margin: 0 auto;
}

.steps {
    display: flex;
    flex-direction: column;
    gap: 0;
    margin-top: 3.5rem;
    position: relative;
}

.steps::before {
    content: '';
    position: absolute;
    left: 28px;
    top: 0;
    bottom: 0;
    width: 1px;
    background: linear-gradient(180deg, transparent, var(--border-gold), var(--border-gold), transparent);
}

.step {
    display: flex;
    gap: 2rem;
    align-items: flex-start;
    padding-bottom: 3rem;
    position: relative;
}

.step:last-child { padding-bottom: 0; }

.step-num {
    width: 56px;
    height: 56px;
    flex-shrink: 0;
    background: var(--bg-card);
    border: 1px solid var(--border-gold);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Cinzel', serif;
    font-size: 1rem;
    font-weight: 700;
    color: var(--gold);
    position: relative;
    z-index: 1;
}

.step-body h3 {
    font-family: 'Cinzel', serif;
    font-size: 1.05rem;
    color: var(--gold-light);
    letter-spacing: 0.05em;
    margin-bottom: 0.5rem;
    padding-top: 0.85rem;
}

.step-body p {
    color: var(--text-muted);
    font-weight: 300;
    font-size: 1rem;
}

/* ============================================================
   ADVENTURE PREVIEW
============================================================ */
.adventure-preview {
    padding: 6rem 2rem;
    background: var(--bg-mid);
    border-top: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
}

.adventure-inner {
    max-width: 1100px;
    margin: 0 auto;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 4rem;
    align-items: center;
}

.scenario-card {
    background: var(--bg-card);
    border: 1px solid var(--border-gold);
    border-radius: 12px;
    padding: 2rem;
    box-shadow: 0 16px 40px rgba(0,0,0,0.5);
}

.scenario-cat {
    font-family: 'Cinzel', serif;
    font-size: 0.65rem;
    letter-spacing: 0.2em;
    text-transform: uppercase;
    color: #f59e0b;
    margin-bottom: 1rem;
}

.scenario-title {
    font-family: 'Cinzel', serif;
    font-size: 1.1rem;
    color: var(--gold-light);
    margin-bottom: 0.5rem;
}

.scenario-flavor {
    font-style: italic;
    color: var(--text-dim);
    font-size: 0.88rem;
    margin-bottom: 1rem;
    border-left: 2px solid var(--border-gold);
    padding-left: 0.75rem;
}

.scenario-desc {
    color: var(--text-muted);
    font-size: 0.95rem;
    margin-bottom: 1.5rem;
    line-height: 1.6;
}

.scenario-choices {
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
}

.choice-pill {
    background: rgba(212, 160, 23, 0.06);
    border: 1px solid var(--border-gold);
    border-radius: 6px;
    padding: 0.65rem 1rem;
    font-size: 0.88rem;
    color: var(--text);
    display: flex;
    align-items: center;
    justify-content: space-between;
    transition: background 0.2s;
    cursor: default;
}

.choice-pill:hover { background: rgba(212, 160, 23, 0.12); }
.choice-dc { font-family: 'Cinzel', serif; font-size: 0.65rem; color: var(--text-dim); letter-spacing: 0.1em; }

.scenario-modifier {
    margin-top: 1.25rem;
    padding-top: 1rem;
    border-top: 1px solid var(--border);
    font-family: 'Cinzel', serif;
    font-size: 0.72rem;
    letter-spacing: 0.08em;
    color: var(--text-dim);
}

.scenario-modifier strong { color: var(--gold-light); }

.adventure-copy h2 {
    font-family: 'Cinzel', serif;
    font-size: clamp(1.5rem, 2.5vw, 2rem);
    color: var(--gold-light);
    letter-spacing: 0.04em;
    margin-bottom: 1.5rem;
}

.adventure-copy p {
    color: var(--text-muted);
    font-weight: 300;
    margin-bottom: 1rem;
    line-height: 1.8;
}

.outcome-pills {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    margin: 1.5rem 0;
}

.outcome-pill {
    font-family: 'Cinzel', serif;
    font-size: 0.65rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    padding: 0.3rem 0.75rem;
    border-radius: 20px;
    border: 1px solid;
}

.op-crit  { color: #fbbf24; border-color: #92400e; background: rgba(251,191,36,0.08); }
.op-win   { color: #22c55e; border-color: #166534; background: rgba(34,197,94,0.08); }
.op-fail  { color: #f97316; border-color: #7c2d12; background: rgba(249,115,22,0.08); }
.op-cfail { color: #ef4444; border-color: #7f1d1d; background: rgba(239,68,68,0.08); }

/* ============================================================
   CLASSES
============================================================ */
.classes {
    padding: 6rem 2rem;
    max-width: 1100px;
    margin: 0 auto;
}

.class-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 1rem;
    margin-top: 3.5rem;
}

.class-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 1.5rem 1rem;
    text-align: center;
    transition: all 0.3s;
    cursor: default;
}

.class-card:hover {
    border-color: var(--border-gold);
    transform: translateY(-4px);
    background: linear-gradient(135deg, rgba(212,160,23,0.05), var(--bg-card));
}

.class-emoji { font-size: 2rem; margin-bottom: 0.75rem; display: block; }

.class-name {
    font-family: 'Cinzel', serif;
    font-size: 0.75rem;
    font-weight: 700;
    color: var(--gold-light);
    letter-spacing: 0.08em;
    text-transform: uppercase;
    margin-bottom: 0.5rem;
}

.class-bonus {
    font-size: 0.78rem;
    color: var(--text-dim);
    line-height: 1.5;
    font-weight: 300;
}

/* ============================================================
   PORTFOLIO SECTION
============================================================ */
.portfolio-section {
    padding: 6rem 2rem;
    background: var(--bg-mid);
    border-top: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
}

.portfolio-inner {
    max-width: 1100px;
    margin: 0 auto;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 4rem;
    align-items: center;
}

.portfolio-copy h2 {
    font-family: 'Cinzel', serif;
    font-size: clamp(1.5rem, 2.5vw, 2rem);
    color: var(--gold-light);
    letter-spacing: 0.04em;
    margin-bottom: 1.5rem;
}

.portfolio-copy p {
    color: var(--text-muted);
    font-weight: 300;
    margin-bottom: 1rem;
    line-height: 1.8;
}

.portfolio-stats {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    margin-top: 1.5rem;
}

.pstat {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 1.25rem;
}

.pstat-val {
    font-family: 'Cinzel', serif;
    font-size: 1.4rem;
    font-weight: 700;
    display: block;
    margin-bottom: 0.25rem;
}

.pstat-label {
    font-size: 0.75rem;
    color: var(--text-dim);
    font-family: 'Cinzel', serif;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

/* Screenshot placeholder - portfolio */
.portfolio-screenshot {
    aspect-ratio: 4/3;
    background: var(--bg-card);
    border: 1px solid var(--border-gold);
    border-radius: 12px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 1rem;
    color: var(--text-dim);
    font-family: 'Cinzel', serif;
    font-size: 0.72rem;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    box-shadow: 0 16px 40px rgba(0,0,0,0.5);
}

/* ============================================================
   DAILY BRIEF TEASER
============================================================ */
.brief-section {
    padding: 6rem 2rem;
    max-width: 800px;
    margin: 0 auto;
    text-align: center;
}

.brief-card {
    background: var(--bg-card);
    border: 1px solid var(--border-gold);
    border-radius: 12px;
    overflow: hidden;
    margin-top: 3rem;
    text-align: left;
    box-shadow: 0 16px 40px rgba(0,0,0,0.4);
}

.brief-header-strip {
    background: linear-gradient(135deg, #16213e, #1a1a2e);
    border-bottom: 2px solid var(--gold);
    padding: 1rem 1.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.brief-title-text {
    font-family: 'Cinzel', serif;
    font-size: 0.75rem;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--gold-light);
}

.brief-date-text {
    font-family: 'Cinzel', serif;
    font-size: 0.65rem;
    color: var(--text-muted);
}

.brief-body-pad {
    padding: 1.5rem;
}

.brief-market-text {
    border-left: 2px solid var(--border-gold);
    padding-left: 1rem;
    margin-bottom: 1.25rem;
}

.brief-market-text p {
    font-style: italic;
    font-size: 0.95rem;
    color: var(--text);
    line-height: 1.7;
    margin-bottom: 0.5rem;
}

.brief-stats-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.75rem;
    margin-bottom: 1.25rem;
}

.bstat {
    background: rgba(255,255,255,0.03);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 0.6rem 0.75rem;
    text-align: center;
}

.bstat-label { font-family: 'Cinzel', serif; font-size: 0.58rem; letter-spacing: 0.12em; text-transform: uppercase; color: var(--text-muted); display: block; margin-bottom: 0.2rem; }
.bstat-val   { font-family: 'Cinzel', serif; font-size: 1rem; font-weight: 700; color: var(--text); }
.text-green  { color: var(--green) !important; }
.text-red    { color: var(--red) !important; }
.text-gold   { color: var(--gold-light) !important; }

.brief-realm-header {
    font-family: 'Cinzel', serif;
    font-size: 0.72rem;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--gold-light);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding-top: 1rem;
    border-top: 1px solid var(--border);
    margin-bottom: 0.75rem;
}

.brief-realm-text {
    font-size: 0.92rem;
    color: var(--text-muted);
    line-height: 1.7;
    font-weight: 300;
}

/* ============================================================
   CTA SECTION
============================================================ */
.cta-section {
    padding: 8rem 2rem;
    text-align: center;
    position: relative;
    overflow: hidden;
}

.cta-section::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 700px;
    height: 400px;
    background: radial-gradient(ellipse, rgba(212, 160, 23, 0.06) 0%, transparent 70%);
    pointer-events: none;
}

.cta-title {
    font-family: 'Cinzel Decorative', serif;
    font-size: clamp(1.8rem, 4vw, 3.2rem);
    color: var(--gold-light);
    margin-bottom: 1.5rem;
    letter-spacing: 0.04em;
}

.cta-desc {
    color: var(--text-muted);
    font-size: 1.1rem;
    max-width: 500px;
    margin: 0 auto 2.5rem;
    font-weight: 300;
}

.cta-fine {
    margin-top: 1.5rem;
    font-size: 0.8rem;
    color: var(--text-dim);
    font-family: 'Cinzel', serif;
    letter-spacing: 0.06em;
}

/* ============================================================
   FOOTER
============================================================ */
.site-footer {
    border-top: 1px solid var(--border);
    padding: 2.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
}

.footer-brand {
    font-family: 'Cinzel', serif;
    font-size: 0.75rem;
    color: var(--text-dim);
    letter-spacing: 0.1em;
}

.footer-links {
    display: flex;
    gap: 2rem;
    font-family: 'Cinzel', serif;
    font-size: 0.68rem;
    letter-spacing: 0.1em;
    text-transform: uppercase;
}

.footer-links a { color: var(--text-dim); }
.footer-links a:hover { color: var(--gold); }

/* ============================================================
   DIVIDER
============================================================ */
.gold-divider {
    width: 60px;
    height: 1px;
    background: linear-gradient(90deg, transparent, var(--gold), transparent);
    margin: 0 auto 2rem;
}

/* ============================================================
   ANIMATIONS
============================================================ */
@keyframes fadeUp {
    from { opacity: 0; transform: translateY(20px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ============================================================
   RESPONSIVE
============================================================ */
@media (max-width: 900px) {
    .adventure-inner,
    .portfolio-inner  { grid-template-columns: 1fr; gap: 2.5rem; }
    .class-grid       { grid-template-columns: repeat(3, 1fr); }
    .hero-stats       { gap: 1.5rem; }
    .screenshot-grid  { grid-template-columns: 1fr 1fr; }
}

@media (max-width: 640px) {
    .site-nav         { padding: 1rem 1.25rem; }
    .nav-links        { display: none; }
    .class-grid       { grid-template-columns: repeat(2, 1fr); }
    .screenshot-grid  { grid-template-columns: 1fr; }
    .brief-stats-row  { grid-template-columns: repeat(3, 1fr); }
    .hero-stats       { flex-direction: column; gap: 1.5rem; }
    .portfolio-stats  { grid-template-columns: 1fr 1fr; }
    .site-footer      { flex-direction: column; text-align: center; }
}
</style>
</head>
<body>

<!-- NAV -->
<nav class="site-nav">
    <div class="nav-brand">⚔ LotGD</div>
    <div class="nav-links">
        <a href="#features">Features</a>
        <a href="#how-it-works">How It Works</a>
        <a href="#adventure">Adventure</a>
        <a href="pages/login.php">Sign In</a>
        <a href="pages/register.php" class="nav-cta">Join the Realm</a>
    </div>
</nav>

<!-- HERO -->
<section class="hero">
    <div class="hero-ornament tl"></div>
    <div class="hero-ornament tr"></div>
    <div class="hero-ornament bl"></div>
    <div class="hero-ornament br"></div>

    <p class="hero-eyebrow">⚔ &nbsp; A Multiplayer Financial RPG &nbsp; ⚔</p>
    <h1 class="hero-title">Legends of the<br>Green Dollar</h1>
    <p class="hero-subtitle">Where Fortune Favors the Prepared</p>
    <p class="hero-desc">
        Go on adventures in the Realm of Fiscal Destiny. Build an S&P 500 portfolio with in-game Gold. Equip your character with powerful gear. Compete for glory on the leaderboard — one d20 roll at a time.
    </p>
    <div class="hero-ctas">
        <a href="pages/register.php" class="btn-primary">⚔ Begin Your Legend</a>
        <a href="#how-it-works" class="btn-secondary">How It Works</a>
    </div>
    <div class="hero-stats">
        <div>
            <span class="hero-stat-val">13+</span>
            <span class="hero-stat-label">Scenarios</span>
        </div>
        <div>
            <span class="hero-stat-val">500</span>
            <span class="hero-stat-label">S&P 500 Stocks</span>
        </div>
        <div>
            <span class="hero-stat-val">5</span>
            <span class="hero-stat-label">Player Classes</span>
        </div>
        <div>
            <span class="hero-stat-val">Free</span>
            <span class="hero-stat-label">To Play</span>
        </div>
    </div>
</section>

<!-- HERO SCREENSHOT -->
<section class="showcase">
    <p class="section-label">The Realm Awaits</p>
    <h2 class="section-title">Your Dashboard, Your Empire</h2>
    <p class="section-desc">Track your adventures, monitor your portfolio, and see how you stack up against other adventurers — all from one command center.</p>

    <!--
    ╔══════════════════════════════════════════════════════════════╗
    ║  SCREENSHOT PLACEHOLDER — Dashboard                         ║
    ║  Replace this div with an <img> tag                         ║
    ║  Recommended: Full-width screenshot of the dashboard page   ║
    ║  Showing: player stats, daily brief, mini leaderboard       ║
    ║  Size: 1280×720px minimum, PNG or WebP                      ║
    ║  Save to: assets/img/landing/screenshot-dashboard.png       ║
    ╚══════════════════════════════════════════════════════════════╝
    -->
    <div class="screenshot-main">
        <div class="screenshot-placeholder">
            <span class="ph-icon">🖥</span>
            <span class="ph-title">Dashboard Screenshot</span>
            <span class="ph-desc">Replace with a full screenshot of your dashboard page showing the Daily Brief, player stats card, and mini leaderboard</span>
        </div>
    </div>

    <div class="screenshot-grid">
        <!--
        Screenshot 2: Adventure encounter screen
        Show a scenario card with choices visible
        Save to: assets/img/landing/screenshot-adventure.png
        -->
        <div class="screenshot-sm">
            <div class="screenshot-placeholder" style="height:100%">
                <span class="ph-icon">⚔</span>
                <span class="ph-title">Adventure Screen</span>
                <span class="ph-desc">A scenario card with choices</span>
            </div>
        </div>
        <!--
        Screenshot 3: Portfolio page
        Show holdings table, % return vs SPY benchmark
        Save to: assets/img/landing/screenshot-portfolio.png
        -->
        <div class="screenshot-sm">
            <div class="screenshot-placeholder" style="height:100%">
                <span class="ph-icon">📈</span>
                <span class="ph-title">Portfolio Page</span>
                <span class="ph-desc">Holdings and return vs SPY</span>
            </div>
        </div>
        <!--
        Screenshot 4: Leaderboard page
        Show top players ranked by portfolio return
        Save to: assets/img/landing/screenshot-leaderboard.png
        -->
        <div class="screenshot-sm">
            <div class="screenshot-placeholder" style="height:100%">
                <span class="ph-icon">👑</span>
                <span class="ph-title">Leaderboard</span>
                <span class="ph-desc">Top players ranked by return</span>
            </div>
        </div>
    </div>
</section>

<!-- FEATURES -->
<section class="features" id="features">
    <div class="features-inner">
        <p class="section-label">Everything You Need</p>
        <div class="gold-divider"></div>
        <h2 class="section-title">Built for the Modern Adventurer</h2>
        <p class="section-desc">A fully-featured fantasy RPG with a twist — your adventures take place in the financial world.</p>

        <div class="features-grid">
            <div class="feature-card">
                <span class="feature-icon">⚔</span>
                <div class="feature-title">d20 Adventure System</div>
                <p class="feature-desc">Face scenarios drawn from the financial world — car dealerships, salary negotiations, market crashes. Roll dice, choose your approach, and live with the outcome.</p>
            </div>
            <div class="feature-card">
                <span class="feature-icon">📈</span>
                <div class="feature-title">S&P 500 Portfolio</div>
                <p class="feature-desc">Trade any of 500 real S&P 500 stocks using in-game Gold earned through adventuring. Hourly price updates. Beat the SPY benchmark at month-end and earn a bonus.</p>
            </div>
            <div class="feature-card">
                <span class="feature-icon">🏪</span>
                <div class="feature-title">Item Store</div>
                <p class="feature-desc">Equip gear that changes how you play. Tools boost specific rolls. Armor reduces losses. Weapons amplify your XP gains. Every purchase is permanent — choose carefully.</p>
            </div>
            <div class="feature-card">
                <span class="feature-icon">📜</span>
                <div class="feature-title">Daily Adventurer's Brief</div>
                <p class="feature-desc">Every morning, an AI-generated fantasy dispatch covering yesterday's market action and the realm's own news — leaderboard shifts, achievements, and notable deeds.</p>
            </div>
            <div class="feature-card">
                <span class="feature-icon">👑</span>
                <div class="feature-title">Competitive Leaderboard</div>
                <p class="feature-desc">Ranked by portfolio return. Who's beating the S&P 500? Who's getting crushed? Glory and shame, rendered in percentages.</p>
            </div>
            <div class="feature-card">
                <span class="feature-icon">🍺</span>
                <div class="feature-title">The Tavern</div>
                <p class="feature-desc">A community message board for sharing strategies, celebrating victories, and commiserating over crit failures. The realm has stories to tell.</p>
            </div>
        </div>
    </div>
</section>

<!-- HOW IT WORKS -->
<section class="how-it-works" id="how-it-works">
    <p class="section-label">Getting Started</p>
    <div class="gold-divider"></div>
    <h2 class="section-title">Your Legend Begins Here</h2>
    <p class="section-desc" style="text-align:center">From registration to your first adventure in under five minutes.</p>

    <div class="steps">
        <div class="step">
            <div class="step-num">I</div>
            <div class="step-body">
                <h3>Choose Your Class</h3>
                <p>Are you an Investor, Debt Slayer, Saver, Entrepreneur, or Minimalist? Each class receives +3 on specific adventure categories. Your class shapes every roll you make.</p>
            </div>
        </div>
        <div class="step">
            <div class="step-num">II</div>
            <div class="step-body">
                <h3>Go Adventuring</h3>
                <p>Each day you get 10 adventure actions. Face a scenario, choose your approach, and roll the dice. Earn XP and Gold on success. Lose Gold on failure. Level up your character over time.</p>
            </div>
        </div>
        <div class="step">
            <div class="step-num">III</div>
            <div class="step-body">
                <h3>Build Your Portfolio</h3>
                <p>Spend your hard-earned Gold on S&P 500 stocks at real market prices. Your portfolio is benchmarked against SPY daily. Beat the index at month-end and earn bonus Gold.</p>
            </div>
        </div>
        <div class="step">
            <div class="step-num">IV</div>
            <div class="step-body">
                <h3>Equip and Dominate</h3>
                <p>Visit the store to buy gear that gives you an edge. A Graphing Calculator adds +2 to investing rolls. Emergency Fund Plate reduces failure penalties. The CFA Study Materials add +2 to everything.</p>
            </div>
        </div>
    </div>
</section>

<!-- ADVENTURE PREVIEW -->
<section class="adventure-preview" id="adventure">
    <div class="adventure-inner">
        <div class="scenario-card">
            <div class="scenario-cat">🛒 Shopping Encounter</div>
            <div class="scenario-title">The Electronics Temple</div>
            <p class="scenario-flavor">"Desire is the enemy of the wise spender."</p>
            <p class="scenario-desc">The great cathedral of gadgetry looms before you. A new phone model has just been released — your current one works perfectly fine. A store associate approaches with the zeal of a true believer, brandishing a 24-month payment plan.</p>
            <div class="scenario-choices">
                <div class="choice-pill">
                    <span>Research comparable models and negotiate</span>
                    <span class="choice-dc">DC 10</span>
                </div>
                <div class="choice-pill">
                    <span>Walk out without looking back</span>
                    <span class="choice-dc">DC 7</span>
                </div>
                <div class="choice-pill">
                    <span>Accept the payment plan "just this once"</span>
                    <span class="choice-dc">DC 14</span>
                </div>
            </div>
            <div class="scenario-modifier">
                Your modifier: <strong>+5</strong>
                (Level 3 + Saver class bonus + Consumer Reports 🛒)
            </div>
        </div>

        <div class="adventure-copy">
            <h2>The Realm Has Real Stakes.</h2>
            <p>Every adventure takes place in a recognizable corner of the financial world — car dealerships, salary negotiations, investment decisions, housing costs, banking traps.</p>
            <p>Your class, level, and equipped gear all modify your d20 roll. Higher rolls mean better outcomes — but the choice of approach matters too. Risky options offer bigger rewards if you pull them off.</p>
            <div class="outcome-pills">
                <span class="outcome-pill op-crit">⚡ Critical Success — 150% XP + Gold</span>
                <span class="outcome-pill op-win">✔ Success — Full rewards</span>
                <span class="outcome-pill op-fail">✘ Failure — Lose Gold</span>
                <span class="outcome-pill op-cfail">💀 Critical Failure — Big loss</span>
            </div>
            <p>Six scenario categories. Thirteen encounters at launch, with more added regularly. Every session is different.</p>
            <a href="pages/register.php" class="btn-primary" style="margin-top:1rem;display:inline-block">
                ⚔ Start Adventuring Free
            </a>
        </div>
    </div>
</section>

<!-- CLASSES -->
<section class="classes">
    <p class="section-label">Choose Your Path</p>
    <div class="gold-divider"></div>
    <h2 class="section-title">Five Classes. One Destiny.</h2>
    <p class="section-desc" style="text-align:center">Your class determines your bonus categories in adventure rolls. Choose based on your financial goals — or your financial nemesis.</p>

    <div class="class-grid">
        <div class="class-card">
            <span class="class-emoji">📈</span>
            <div class="class-name">Investor</div>
            <div class="class-bonus">+3 on Investing encounters</div>
        </div>
        <div class="class-card">
            <span class="class-emoji">🗡️</span>
            <div class="class-name">Debt Slayer</div>
            <div class="class-bonus">+3 on Banking & Shopping</div>
        </div>
        <div class="class-card">
            <span class="class-emoji">🏦</span>
            <div class="class-name">Saver</div>
            <div class="class-bonus">+3 on Daily Life & Shopping</div>
        </div>
        <div class="class-card">
            <span class="class-emoji">🚀</span>
            <div class="class-name">Entrepreneur</div>
            <div class="class-bonus">+3 on Work encounters</div>
        </div>
        <div class="class-card">
            <span class="class-emoji">🧘</span>
            <div class="class-name">Minimalist</div>
            <div class="class-bonus">+3 on Shopping & Daily Life</div>
        </div>
    </div>
</section>

<!-- PORTFOLIO -->
<section class="portfolio-section">
    <div class="portfolio-inner">
        <div class="portfolio-copy">
            <h2>Earn Gold.<br>Build an Empire.</h2>
            <p>Gold earned through adventuring can be invested in any S&P 500 stock at real previous-close prices, updated every hour during market hours. Your portfolio is benchmarked against SPY daily.</p>
            <p>Beat the index at month-end and earn bonus Gold. Dominate the leaderboard. All the strategy of real investing — with none of your actual money on the line.</p>
            <div class="portfolio-stats">
                <div class="pstat">
                    <span class="pstat-val text-gold">500+</span>
                    <span class="pstat-label">Tradeable stocks</span>
                </div>
                <div class="pstat">
                    <span class="pstat-val text-green">Hourly</span>
                    <span class="pstat-label">Price updates</span>
                </div>
                <div class="pstat">
                    <span class="pstat-val text-gold">SPY</span>
                    <span class="pstat-label">Benchmark index</span>
                </div>
                <div class="pstat">
                    <span class="pstat-val text-green">Free</span>
                    <span class="pstat-label">No real money</span>
                </div>
            </div>
        </div>

        <!--
        ╔══════════════════════════════════════════════════════════════╗
        ║  SCREENSHOT PLACEHOLDER — Portfolio Page                    ║
        ║  Replace with: <img src="assets/img/landing/screenshot-portfolio.png" ...> ║
        ║  Show: holdings table, % return vs SPY, leaderboard rank    ║
        ║  Size: 800×600px minimum                                    ║
        ╚══════════════════════════════════════════════════════════════╝
        -->
        <div class="portfolio-screenshot">
            <span style="font-size:2.5rem;opacity:0.4">📊</span>
            <span style="font-family:'Cinzel',serif;font-size:0.75rem;color:var(--text-muted)">Portfolio Screenshot</span>
            <span style="font-size:0.72rem;color:var(--text-dim);max-width:200px;text-align:center;font-family:'Crimson Pro',serif;text-transform:none;letter-spacing:0">Replace with screenshot of portfolio page showing holdings and return vs SPY</span>
        </div>
    </div>
</section>

<!-- DAILY BRIEF -->
<section class="brief-section">
    <p class="section-label">Every Morning</p>
    <div class="gold-divider"></div>
    <h2 class="section-title">The Daily Adventurer's Brief</h2>
    <p class="section-desc">Real market data. Fantasy framing. Every morning a fresh dispatch from the realm — written by AI, grounded in yesterday's actual S&P 500 action and your fellow players' deeds.</p>

    <div class="brief-card">
        <div class="brief-header-strip">
            <span class="brief-title-text">📜 The Daily Adventurer's Brief</span>
            <span class="brief-date-text">Monday, March 17, 2026</span>
        </div>
        <div class="brief-body-pad">
            <div class="brief-market-text">
                <p>The great market dragon stirred with purpose yesterday. The S&P 500 advanced 31 points as technology warriors led the charge, NVDA surging on whispers of new silicon sorcery. Meanwhile the energy guilds retreated, their oil reserves less precious as peace broke out along the Strait of Hormuz.</p>
                <p>The central bank oracles convene this week. Wise adventurers keep their emergency fund vaults stocked and their debt dragons subdued.</p>
            </div>
            <div class="brief-stats-row">
                <div class="bstat">
                    <span class="bstat-label">S&P 500</span>
                    <span class="bstat-val text-gold">5,842</span>
                </div>
                <div class="bstat">
                    <span class="bstat-label">Top Raider</span>
                    <span class="bstat-val">NVDA <span class="text-green" style="font-size:0.75rem">+4.2%</span></span>
                </div>
                <div class="bstat">
                    <span class="bstat-label">Fallen</span>
                    <span class="bstat-val">XOM <span class="text-red" style="font-size:0.75rem">−2.1%</span></span>
                </div>
            </div>
            <div class="brief-realm-header">⚔ From the Realm</div>
            <p class="brief-realm-text">Twenty-three adventurers rode out yesterday, with fourteen returning victorious. <strong style="color:var(--gold-light)">Thornwick the Investor</strong> seized the leaderboard summit with a +12.4% portfolio return. Three new heroes earned their first achievement. The Compound Interest Staff was purchased for the first time — by a player who clearly intends to hold the throne.</p>
        </div>
    </div>
</section>

<!-- FINAL CTA -->
<section class="cta-section">
    <h2 class="cta-title">Your Legend Begins<br>With a Single Roll</h2>
    <p class="cta-desc">Free to play. No real money required. Earn Gold, build your portfolio, equip your character, and compete for glory in the Realm of Fiscal Destiny.</p>
    <a href="pages/register.php" class="btn-primary" style="font-size:0.9rem;padding:1.1rem 3rem">
        ⚔ Join the Realm — It's Free
    </a>
    <p class="cta-fine">Already have an account? <a href="pages/login.php" style="color:var(--text-muted)">Sign In</a></p>
</section>

<!-- FOOTER -->
<footer class="site-footer">
    <div class="footer-brand">⚔ Legends of the Green Dollar &nbsp;·&nbsp; &copy; 2026</div>
    <div class="footer-links">
        <a href="pages/login.php">Sign In</a>
        <a href="pages/register.php">Register</a>
        <a href="https://github.com/JeremyYowell/lotgd" target="_blank">GitHub</a>
    </div>
</footer>

</body>
</html>

<?php
/**
 * Neptune public landing page.
 *
 * Included by /Neptune/index.php after authentication/login processing.
 * Available variables:
 *   $error
 *   $organizations
 *   $selectedOrganizationId
 *
 * Available helpers from bootstrap:
 *   e()
 *   base_url()
 */
$githubUrl = 'https://github.com/blarkin13/neptune-frc-scouting';
?>

<style>
/* ==========================================================================
   NEPTUNE PUBLIC LANDING PAGE
   Presentation only. Authentication remains in index.php.
   ========================================================================== */

.nlp {
    --nlp-max: 1220px;
    --nlp-accent: var(--accent, #7c5cff);
    --nlp-accent-2: #18b8ff;
    --nlp-panel: var(--card, #17182a);
    --nlp-panel-2: var(--panel, #111321);
    --nlp-border: var(--border, #303248);
    --nlp-muted: var(--muted, #a7a9bc);
    --nlp-text: var(--text, #f7f7fb);
    --nlp-shadow: 0 24px 70px rgba(0,0,0,.18);
    --nlp-radius: 20px;
    --nlp-radius-sm: 14px;

    width: 100%;
    max-width: 100%;
    min-width: 0;
    color: inherit;
    overflow-x: clip;
    overflow-y: visible;
}

.nlp *,
.nlp *::before,
.nlp *::after {
    box-sizing: border-box;
}

.nlp a {
    color: inherit;
}

.nlp img {
    max-width: 100%;
}

.nlp-wrap {
    width: min(var(--nlp-max), calc(100% - 36px));
    max-width: 100%;
    min-width: 0;
    margin-inline: auto;
}

.nlp-section,
.nlp-hero,
.nlp-final {
    scroll-margin-top: 90px;
}

.nlp-section {
    position: relative;
    padding: 76px 0;
    overflow: visible;
}

.nlp-section--soft::before {
    content: "";
    position: absolute;
    inset: 18px 0;
    z-index: -1;
    border-block: 1px solid color-mix(in srgb, var(--nlp-border) 68%, transparent);
    background:
        radial-gradient(circle at 10% 20%, color-mix(in srgb, var(--nlp-accent) 10%, transparent), transparent 30%),
        radial-gradient(circle at 88% 75%, color-mix(in srgb, var(--nlp-accent-2) 8%, transparent), transparent 28%);
    pointer-events: none;
}

.nlp-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    margin: 0 0 14px;
    font-size: .76rem;
    font-weight: 900;
    letter-spacing: .15em;
    text-transform: uppercase;
    color: var(--nlp-muted);
}

.nlp-eyebrow i {
    color: var(--nlp-accent);
}

.nlp-section-head {
    max-width: 780px;
    margin-bottom: 34px;
}

.nlp-section-head h2 {
    margin: 0;
    font-size: clamp(2rem, 4.6vw, 3.55rem);
    line-height: 1.03;
    letter-spacing: -.045em;
}

.nlp-section-head p {
    max-width: 720px;
    margin: 18px 0 0;
    color: var(--nlp-muted);
    font-size: 1.03rem;
    line-height: 1.75;
}

.nlp-kicker {
    font-weight: 800;
    color: inherit;
}

.nlp-pill {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    min-height: 30px;
    padding: 5px 10px;
    border: 1px solid var(--nlp-border);
    border-radius: 999px;
    background: color-mix(in srgb, var(--nlp-panel) 86%, transparent);
    color: var(--nlp-muted);
    font-size: .78rem;
    font-weight: 800;
    white-space: nowrap;
    font-family: inherit;
    cursor: pointer;
}

.nlp-pill i {
    color: var(--nlp-accent);
}


/* ==========================================================================
   LOCAL PAGE NAV
   ========================================================================== */

.nlp-nav-shell {
    position: sticky;
    top: 0;
    z-index: 30;
    width: 100%;
    max-width: 100%;
    overflow: hidden;
    border-bottom: 1px solid color-mix(in srgb, var(--nlp-border) 75%, transparent);
    background: color-mix(in srgb, var(--nlp-panel-2) 88%, transparent);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
}

.nlp-nav {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    min-height: 58px;
    gap: 18px;
}

.nlp-nav-brand {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: max-content;
    text-decoration: none;
    font-weight: 900;
    letter-spacing: .05em;
}

.nlp-nav-brand img {
    width: 34px;
    height: 34px;
    object-fit: contain;
}

.nlp-nav-links {
    display: flex;
    align-items: center;
    gap: 4px;
    min-width: 0;
    margin-right: auto;
    overflow-x: auto;
    scrollbar-width: none;
}

.nlp-nav-links::-webkit-scrollbar {
    display: none;
}

.nlp-nav-links a {
    display: inline-flex;
    align-items: center;
    min-height: 38px;
    padding: 0 11px;
    border-radius: 9px;
    color: var(--nlp-muted);
    text-decoration: none;
    font-size: .84rem;
    font-weight: 800;
    white-space: nowrap;
}

.nlp-nav-links a:hover,
.nlp-nav-links a:focus-visible {
    background: color-mix(in srgb, var(--nlp-accent) 10%, transparent);
    color: inherit;
    outline: none;
}

.nlp-nav-cta {
    appearance: none;
    -webkit-appearance: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 38px;
    padding: 0 13px;
    border-radius: 9px;
    background: var(--nlp-accent);
    color: #fff !important;
    text-decoration: none;
    font-size: .84rem;
    font-weight: 900;
    white-space: nowrap;
}


/* ==========================================================================
   HERO
   ========================================================================== */

.nlp-hero {
    position: relative;
    padding: 74px 0 68px;
    overflow: hidden;
}

.nlp-hero::before {
    content: "";
    position: absolute;
    width: 760px;
    height: 760px;
    left: -360px;
    top: -390px;
    border-radius: 50%;
    background: color-mix(in srgb, var(--nlp-accent) 16%, transparent);
    filter: blur(4px);
    pointer-events: none;
}

.nlp-hero::after {
    content: "";
    position: absolute;
    width: 620px;
    height: 620px;
    right: -320px;
    bottom: -350px;
    border-radius: 50%;
    background: color-mix(in srgb, var(--nlp-accent-2) 10%, transparent);
    pointer-events: none;
}

.nlp-hero-grid {
    position: relative;
    z-index: 1;
    display: grid;
    grid-template-columns: minmax(0, 1.25fr) minmax(340px, .75fr);
    gap: 62px;
    align-items: center;
}

.nlp-brand-lockup {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 28px;
}

.nlp-brand-lockup img {
    width: 74px;
    height: 74px;
    object-fit: contain;
}

.nlp-brand-copy strong {
    display: block;
    font-size: 1.05rem;
    letter-spacing: .14em;
    text-transform: uppercase;
}

.nlp-brand-copy span {
    display: block;
    margin-top: 4px;
    color: var(--nlp-muted);
    font-size: .82rem;
    font-weight: 700;
}

.nlp-hero h1 {
    max-width: 760px;
    margin: 0;
    font-size: clamp(3rem, 5.4vw, 5.25rem);
    line-height: .96;
    letter-spacing: -.055em;
    text-wrap: balance;
    overflow-wrap: normal;
}

.nlp-hero h1 span {
    color: var(--nlp-accent);
}

.nlp-hero-tagline {
    max-width: 760px;
    margin: 20px 0 0;
    font-size: clamp(1.35rem, 3vw, 2.15rem);
    line-height: 1.08;
    letter-spacing: -.03em;
    font-weight: 850;
}

.nlp-hero-lede {
    max-width: 760px;
    margin: 26px 0 0;
    color: var(--nlp-muted);
    font-size: clamp(1.02rem, 1.9vw, 1.22rem);
    line-height: 1.75;
}

.nlp-hero-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 30px;
}

.nlp-btn {
    appearance: none;
    -webkit-appearance: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    min-height: 48px;
    padding: 0 18px;
    border: 1px solid transparent;
    border-radius: 11px;
    text-decoration: none;
    font-size: .92rem;
    font-weight: 900;
    cursor: pointer;
    font-family: inherit;
    transition: transform .16s ease, border-color .16s ease, background .16s ease, opacity .16s ease;
}

.nlp-btn:hover {
    transform: translateY(-1px);
}

.nlp-btn--primary {
    background: var(--nlp-accent);
    color: #fff !important;
    box-shadow: 0 10px 26px color-mix(in srgb, var(--nlp-accent) 24%, transparent);
}

.nlp-btn--secondary {
    border-color: var(--nlp-border);
    background: color-mix(in srgb, var(--nlp-panel) 86%, transparent);
}

.nlp-btn--ghost {
    color: var(--nlp-muted) !important;
}

.nlp-hero-points {
    display: flex;
    flex-wrap: wrap;
    gap: 9px;
    margin-top: 28px;
}

.nlp-hero-points .nlp-pill {
    background: color-mix(in srgb, var(--nlp-panel) 64%, transparent);
}


/* ==========================================================================
   LOGIN CARD
   ========================================================================== */

.nlp-login {
    position: relative;
    scroll-margin-top: 96px;
    padding: 26px;
    border: 1px solid var(--nlp-border);
    border-radius: var(--nlp-radius);
    background:
        linear-gradient(180deg,
            color-mix(in srgb, var(--nlp-panel) 97%, white 3%),
            color-mix(in srgb, var(--nlp-panel) 94%, transparent)
        );
    box-shadow: var(--nlp-shadow);
}

.nlp-login::before {
    content: "";
    position: absolute;
    inset: 0;
    border-radius: inherit;
    border-top: 1px solid color-mix(in srgb, white 12%, transparent);
    pointer-events: none;
}

.nlp-login-head {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 22px;
}

.nlp-login-head h2 {
    margin: 0 0 5px;
    font-size: 1.55rem;
    letter-spacing: -.025em;
}

.nlp-login-head p {
    margin: 0;
    color: var(--nlp-muted);
    font-size: .9rem;
    line-height: 1.5;
}

.nlp-login-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    flex: 0 0 44px;
    border-radius: 12px;
    background: color-mix(in srgb, var(--nlp-accent) 15%, transparent);
    color: var(--nlp-accent);
}

.nlp-login .notice {
    margin-bottom: 16px;
}

.nlp-login label {
    display: block;
    margin: 14px 0 7px;
    font-size: .83rem;
    font-weight: 900;
}

.nlp-login input,
.nlp-login select {
    width: 100%;
}

.nlp-login .toolbar {
    margin-top: 20px;
}

.nlp-login .toolbar button {
    width: 100%;
    min-height: 46px;
}

.nlp-login-links {
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: 8px 14px;
    margin-top: 18px;
    padding-top: 17px;
    border-top: 1px solid var(--nlp-border);
    font-size: .87rem;
    font-weight: 800;
}

.nlp-login-links a {
    color: var(--nlp-accent);
    text-decoration: none;
}


/* ==========================================================================
   QUICK OVERVIEW / STATS
   ========================================================================== */

.nlp-overview {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    margin-top: -16px;
    position: relative;
    z-index: 3;
}

.nlp-overview-item {
    padding: 20px;
    border: 1px solid var(--nlp-border);
    border-radius: var(--nlp-radius-sm);
    background: var(--nlp-panel);
    box-shadow: 0 10px 30px rgba(0,0,0,.08);
}

.nlp-overview-item i {
    color: var(--nlp-accent);
    margin-right: 7px;
}

.nlp-overview-item strong {
    display: block;
    margin-bottom: 7px;
    font-size: .93rem;
}

.nlp-overview-item span {
    color: var(--nlp-muted);
    font-size: .84rem;
    line-height: 1.5;
}


/* ==========================================================================
   MODULE BENTO
   ========================================================================== */

.nlp-module-grid {
    display: grid;
    grid-template-columns: repeat(12, minmax(0, 1fr));
    gap: 16px;
}

.nlp-module {
    grid-column: span 4;
    position: relative;
    min-width: 0;
    min-height: 210px;
    padding: 24px;
    border: 1px solid var(--nlp-border);
    border-radius: var(--nlp-radius);
    background: var(--nlp-panel);
    overflow: hidden;
}

.nlp-module--wide {
    grid-column: span 6;
}

.nlp-module--hero {
    grid-column: span 8;
}

.nlp-module::after {
    content: "";
    position: absolute;
    width: 150px;
    height: 150px;
    right: -70px;
    bottom: -75px;
    border-radius: 50%;
    background: color-mix(in srgb, var(--nlp-accent) 9%, transparent);
    pointer-events: none;
}

.nlp-module-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 46px;
    height: 46px;
    margin-bottom: 20px;
    border-radius: 12px;
    background: color-mix(in srgb, var(--nlp-accent) 14%, transparent);
    color: var(--nlp-accent);
    font-size: 1.1rem;
}

.nlp-module h3 {
    margin: 0 0 9px;
    font-size: 1.15rem;
    letter-spacing: -.02em;
}

.nlp-module p {
    margin: 0;
    color: var(--nlp-muted);
    font-size: .92rem;
    line-height: 1.65;
}

.nlp-module-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    margin-top: 18px;
}

.nlp-module-tags span {
    padding: 5px 8px;
    border: 1px solid var(--nlp-border);
    border-radius: 999px;
    color: var(--nlp-muted);
    font-size: .72rem;
    font-weight: 800;
}


/* ==========================================================================
   EVENT FLOW
   ========================================================================== */

.nlp-flow {
    position: relative;
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 14px;
}

.nlp-flow::before {
    content: "";
    position: absolute;
    left: 8%;
    right: 8%;
    top: 31px;
    height: 2px;
    background: linear-gradient(90deg,
        color-mix(in srgb, var(--nlp-accent) 25%, transparent),
        var(--nlp-accent),
        color-mix(in srgb, var(--nlp-accent-2) 45%, transparent)
    );
    z-index: 0;
}

.nlp-flow-step {
    position: relative;
    z-index: 1;
    min-width: 0;
}

.nlp-flow-dot {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 62px;
    height: 62px;
    margin-bottom: 18px;
    border: 6px solid color-mix(in srgb, var(--nlp-panel-2) 90%, transparent);
    border-radius: 50%;
    background: var(--nlp-accent);
    color: #fff;
    font-weight: 950;
}

.nlp-flow-step h3 {
    margin: 0 0 7px;
    font-size: 1rem;
}

.nlp-flow-step p {
    margin: 0;
    color: var(--nlp-muted);
    font-size: .85rem;
    line-height: 1.55;
}


/* ==========================================================================
   LIVE MATCH / SYSTEM MAP
   ========================================================================== */

.nlp-split {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 22px;
    align-items: stretch;
}

.nlp-panel {
    padding: 28px;
    border: 1px solid var(--nlp-border);
    border-radius: var(--nlp-radius);
    background: var(--nlp-panel);
}

.nlp-panel h3 {
    margin: 0 0 12px;
    font-size: 1.35rem;
    letter-spacing: -.025em;
}

.nlp-panel > p {
    margin: 0;
    color: var(--nlp-muted);
    line-height: 1.7;
}

.nlp-checklist {
    display: grid;
    gap: 13px;
    margin-top: 22px;
}

.nlp-check {
    display: grid;
    grid-template-columns: 30px minmax(0, 1fr);
    gap: 12px;
    align-items: start;
}

.nlp-check i {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 8px;
    background: color-mix(in srgb, var(--nlp-accent) 13%, transparent);
    color: var(--nlp-accent);
}

.nlp-check strong {
    display: block;
    margin-bottom: 3px;
    font-size: .91rem;
}

.nlp-check span {
    display: block;
    color: var(--nlp-muted);
    font-size: .84rem;
    line-height: 1.5;
}

.nlp-system-map {
    display: grid;
    gap: 12px;
    margin-top: 20px;
}

.nlp-system-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 34px minmax(0, 1fr);
    gap: 8px;
    align-items: center;
}

.nlp-system-box {
    padding: 15px;
    border: 1px solid var(--nlp-border);
    border-radius: 12px;
    background: color-mix(in srgb, var(--nlp-panel-2) 62%, transparent);
}

.nlp-system-box strong {
    display: block;
    margin-bottom: 3px;
    font-size: .84rem;
}

.nlp-system-box span {
    display: block;
    color: var(--nlp-muted);
    font-size: .76rem;
    line-height: 1.4;
}

.nlp-system-arrow {
    text-align: center;
    color: var(--nlp-accent);
}


/* ==========================================================================
   DEPLOYMENT OPTIONS
   ========================================================================== */

.nlp-deploy-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
}

.nlp-deploy-card {
    display: flex;
    flex-direction: column;
    min-width: 0;
    padding: 24px;
    border: 1px solid var(--nlp-border);
    border-radius: var(--nlp-radius);
    background: var(--nlp-panel);
}

.nlp-deploy-card--featured {
    border-color: color-mix(in srgb, var(--nlp-accent) 55%, var(--nlp-border));
    box-shadow: 0 18px 50px color-mix(in srgb, var(--nlp-accent) 10%, transparent);
}

.nlp-deploy-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 50px;
    height: 50px;
    margin-bottom: 18px;
    border-radius: 13px;
    background: color-mix(in srgb, var(--nlp-accent) 13%, transparent);
    color: var(--nlp-accent);
    font-size: 1.15rem;
}

.nlp-deploy-card h3 {
    margin: 0 0 8px;
    font-size: 1.1rem;
}

.nlp-deploy-card p {
    margin: 0;
    color: var(--nlp-muted);
    font-size: .88rem;
    line-height: 1.6;
}

.nlp-deploy-list {
    display: grid;
    gap: 8px;
    margin: 18px 0 22px;
}

.nlp-deploy-list span {
    display: flex;
    gap: 8px;
    color: var(--nlp-muted);
    font-size: .8rem;
    line-height: 1.45;
}

.nlp-deploy-list i {
    margin-top: 2px;
    color: var(--nlp-accent);
}

.nlp-deploy-card .nlp-btn {
    margin-top: auto;
    min-height: 42px;
    font-size: .83rem;
}


/* ==========================================================================
   ACCOUNT SETUP STEPS
   ========================================================================== */

.nlp-steps {
    display: grid;
    gap: 13px;
    counter-reset: nlpstep;
}

.nlp-step {
    counter-increment: nlpstep;
    display: grid;
    grid-template-columns: 58px minmax(0, 1fr) auto;
    gap: 18px;
    align-items: center;
    padding: 19px 20px;
    border: 1px solid var(--nlp-border);
    border-radius: 15px;
    background: var(--nlp-panel);
}

.nlp-step::before {
    content: counter(nlpstep);
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: var(--nlp-accent);
    color: #fff;
    font-weight: 950;
}

.nlp-step h3 {
    margin: 0 0 4px;
    font-size: .98rem;
}

.nlp-step p {
    margin: 0;
    color: var(--nlp-muted);
    font-size: .84rem;
    line-height: 1.5;
}

.nlp-step i {
    color: var(--nlp-muted);
}


/* ==========================================================================
   INSTALL / GITHUB
   ========================================================================== */

.nlp-install-grid {
    display: grid;
    grid-template-columns: minmax(0, .82fr) minmax(0, 1.18fr);
    gap: 22px;
    align-items: start;
}

.nlp-install-menu {
    display: grid;
    gap: 12px;
}

.nlp-install-menu details,
.nlp-faq details {
    border: 1px solid var(--nlp-border);
    border-radius: 14px;
    background: var(--nlp-panel);
    overflow: hidden;
}

.nlp-install-menu summary,
.nlp-faq summary {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 18px 20px;
    cursor: pointer;
    font-weight: 900;
    list-style: none;
}

.nlp-install-menu summary::-webkit-details-marker,
.nlp-faq summary::-webkit-details-marker {
    display: none;
}

.nlp-install-menu summary::after,
.nlp-faq summary::after {
    content: "+";
    margin-left: auto;
    color: var(--nlp-accent);
    font-size: 1.2rem;
}

.nlp-install-menu details[open] summary::after,
.nlp-faq details[open] summary::after {
    content: "−";
}

.nlp-detail-body {
    padding: 0 20px 20px;
    color: var(--nlp-muted);
    font-size: .88rem;
    line-height: 1.65;
}

.nlp-detail-body p {
    margin: 0 0 12px;
}

.nlp-detail-body p:last-child {
    margin-bottom: 0;
}

.nlp-terminal {
    position: relative;
    overflow: hidden;
    border: 1px solid var(--nlp-border);
    border-radius: var(--nlp-radius);
    background: #0a0b12;
    box-shadow: var(--nlp-shadow);
}

.nlp-terminal-bar {
    display: flex;
    align-items: center;
    gap: 7px;
    min-height: 44px;
    padding: 0 15px;
    border-bottom: 1px solid #242637;
    background: #11131d;
    color: #a9adbd;
    font-size: .75rem;
    font-weight: 800;
}

.nlp-terminal-dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: #555a6e;
}

.nlp-terminal pre {
    margin: 0;
    padding: 22px;
    overflow-x: auto;
    color: #e7e9f4;
    font: 500 .82rem/1.68 ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
    white-space: pre;
}

.nlp-terminal .cmd {
    color: #93e6ff;
}

.nlp-terminal .comment {
    color: #7f8499;
}


/* ==========================================================================
   SECURITY / ARCHITECTURE
   ========================================================================== */

.nlp-security-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 15px;
}

.nlp-security {
    padding: 22px;
    border: 1px solid var(--nlp-border);
    border-radius: 15px;
    background: var(--nlp-panel);
}

.nlp-security i {
    margin-bottom: 14px;
    color: var(--nlp-accent);
    font-size: 1.1rem;
}

.nlp-security h3 {
    margin: 0 0 7px;
    font-size: .98rem;
}

.nlp-security p {
    margin: 0;
    color: var(--nlp-muted);
    font-size: .82rem;
    line-height: 1.55;
}


/* ==========================================================================
   FAQ / FINAL CTA
   ========================================================================== */

.nlp-faq {
    display: grid;
    gap: 11px;
    max-width: 900px;
}

.nlp-final {
    padding: 40px 0 86px;
}

.nlp-final-box {
    position: relative;
    overflow: hidden;
    padding: 46px;
    border: 1px solid color-mix(in srgb, var(--nlp-accent) 45%, var(--nlp-border));
    border-radius: 24px;
    background:
        radial-gradient(circle at 10% 20%, color-mix(in srgb, var(--nlp-accent) 18%, transparent), transparent 34%),
        radial-gradient(circle at 90% 80%, color-mix(in srgb, var(--nlp-accent-2) 11%, transparent), transparent 34%),
        var(--nlp-panel);
}

.nlp-final-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 30px;
    align-items: center;
}

.nlp-final h2 {
    margin: 0;
    font-size: clamp(2rem, 4.2vw, 3.3rem);
    line-height: 1.04;
    letter-spacing: -.045em;
}

.nlp-final p {
    max-width: 720px;
    margin: 14px 0 0;
    color: var(--nlp-muted);
    line-height: 1.7;
}

.nlp-final-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}



/* ==========================================================================
   MOTION / REVEALS / PARALLAX
   ========================================================================== */

.nlp {
    --nlp-parallax-y: 0px;
    --nlp-parallax-x: 0px;
}

/* Subtle hero depth. These pseudo-elements already exist; this only adds motion. */
.nlp-hero::before {
    transform: translate3d(
        calc(var(--nlp-parallax-x) * -0.20),
        calc(var(--nlp-parallax-y) * -0.32),
        0
    );
    will-change: transform;
}

.nlp-hero::after {
    transform: translate3d(
        calc(var(--nlp-parallax-x) * 0.12),
        calc(var(--nlp-parallax-y) * 0.22),
        0
    );
    will-change: transform;
}

/* Initial hero entrance. */
.nlp-hero h1,
.nlp-hero-lede,
.nlp-hero-actions,
.nlp-hero-points,
.nlp-login {
    animation: nlpHeroRise .72s cubic-bezier(.2,.8,.2,1) both;
}

.nlp-hero-lede { animation-delay: .08s; }
.nlp-hero-actions { animation-delay: .14s; }
.nlp-hero-points { animation-delay: .20s; }
.nlp-login { animation-delay: .12s; }

@keyframes nlpHeroRise {
    from {
        opacity: 0;
        transform: translate3d(0, 22px, 0);
    }
    to {
        opacity: 1;
        transform: translate3d(0, 0, 0);
    }
}

/* Scroll-reveal targets are activated by the small IntersectionObserver below. */
.nlp-motion-ready .nlp-reveal {
    opacity: 0;
    transform: translate3d(0, 24px, 0) scale(.985);
    transition:
        opacity .62s cubic-bezier(.2,.8,.2,1),
        transform .62s cubic-bezier(.2,.8,.2,1);
    will-change: opacity, transform;
}

.nlp-motion-ready .nlp-reveal.nlp-inview {
    opacity: 1;
    transform: translate3d(0, 0, 0) scale(1);
}

/* Stagger grid/card entrances without hard-coding markup. */
.nlp-motion-ready .nlp-module-grid .nlp-reveal:nth-child(2n),
.nlp-motion-ready .nlp-deploy-grid .nlp-reveal:nth-child(2n),
.nlp-motion-ready .nlp-security-grid .nlp-reveal:nth-child(2n),
.nlp-motion-ready .nlp-overview .nlp-reveal:nth-child(2n) {
    transition-delay: .06s;
}

.nlp-motion-ready .nlp-module-grid .nlp-reveal:nth-child(3n),
.nlp-motion-ready .nlp-security-grid .nlp-reveal:nth-child(3n) {
    transition-delay: .12s;
}

/* Card polish. */
.nlp-module,
.nlp-deploy-card,
.nlp-panel,
.nlp-security,
.nlp-overview-item,
.nlp-step,
.nlp-faq details {
    transition:
        transform .22s ease,
        border-color .22s ease,
        box-shadow .22s ease,
        background .22s ease;
}

@media (hover:hover) and (pointer:fine) {
    .nlp-module:hover,
    .nlp-deploy-card:hover,
    .nlp-security:hover,
    .nlp-overview-item:hover {
        transform: translateY(-5px);
        border-color: color-mix(in srgb, var(--nlp-accent) 42%, var(--nlp-border));
        box-shadow: 0 18px 46px rgba(0,0,0,.16);
    }

    .nlp-panel:hover,
    .nlp-step:hover,
    .nlp-faq details:hover {
        border-color: color-mix(in srgb, var(--nlp-accent) 32%, var(--nlp-border));
    }
}

/* Soft light sweep on important cards. */
.nlp-module,
.nlp-deploy-card,
.nlp-final-box,
.nlp-login {
    isolation: isolate;
}

.nlp-module::before,
.nlp-deploy-card::before,
.nlp-final-box::before,
.nlp-login::after {
    content: "";
    position: absolute;
    inset: 0;
    z-index: -1;
    border-radius: inherit;
    pointer-events: none;
    background:
        linear-gradient(
            120deg,
            transparent 20%,
            color-mix(in srgb, var(--nlp-accent-2) 6%, transparent) 45%,
            transparent 70%
        );
    transform: translateX(-70%);
    transition: transform .65s cubic-bezier(.2,.8,.2,1);
}

@media (hover:hover) and (pointer:fine) {
    .nlp-module:hover::before,
    .nlp-deploy-card:hover::before,
    .nlp-final-box:hover::before,
    .nlp-login:hover::after {
        transform: translateX(45%);
    }
}

/* Button highlight with no layout movement beyond the existing 1px lift. */
.nlp-btn,
.nlp-nav-cta {
    position: relative;
    overflow: hidden;
}

.nlp-btn::after,
.nlp-nav-cta::after {
    content: "";
    position: absolute;
    top: -100%;
    bottom: -100%;
    width: 38%;
    left: -55%;
    transform: skewX(-18deg);
    background: linear-gradient(
        90deg,
        transparent,
        rgba(255,255,255,.20),
        transparent
    );
    transition: left .48s ease;
    pointer-events: none;
}

@media (hover:hover) and (pointer:fine) {
    .nlp-btn:hover::after,
    .nlp-nav-cta:hover::after {
        left: 120%;
    }
}

/* Gentle accent activity rather than constant large animation. */
.nlp-eyebrow i,
.nlp-module-icon i,
.nlp-deploy-icon i {
    transition: transform .22s ease;
}

@media (hover:hover) and (pointer:fine) {
    .nlp-module:hover .nlp-module-icon i,
    .nlp-deploy-card:hover .nlp-deploy-icon i {
        transform: scale(1.12) rotate(-3deg);
    }
}

/* Keep all motion optional and accessible. */
@media (prefers-reduced-motion: reduce) {
    .nlp,
    .nlp *,
    .nlp *::before,
    .nlp *::after {
        animation: none !important;
        transition: none !important;
        scroll-behavior: auto !important;
    }

    .nlp-hero::before,
    .nlp-hero::after,
    .nlp-motion-ready .nlp-reveal,
    .nlp-motion-ready .nlp-reveal.nlp-inview {
        transform: none !important;
        opacity: 1 !important;
    }
}



.nlp-mt-24 { margin-top: 24px; }
.nlp-mt-48 { margin-top: 48px; }
.nlp-pill--start { align-self: flex-start; margin-bottom: 14px; }
.nlp-terminal-title { margin-left: 6px; }

/* ==========================================================================
   RESPONSIVE
   ========================================================================== */

/* Laptop / narrow-desktop protection.
   The full six-link subnav is wider than the available space on many
   11–13 inch laptop/browser layouts, so collapse it before it can clip. */
@media (max-width: 1280px) {
    .nlp-nav-shell {
        display: none;
    }

    .nlp-nav-links {
        display: none;
    }

    .nlp-nav {
        justify-content: flex-end;
    }

    .nlp-hero-grid {
        grid-template-columns: minmax(0, 1.1fr) minmax(320px, .9fr);
        gap: 32px;
    }

    .nlp-hero h1 {
        max-width: 660px;
        font-size: clamp(2.8rem, 5vw, 4.5rem);
    }

    .nlp-hero-tagline,
    .nlp-hero-lede {
        max-width: 650px;
    }
}

@media (max-width: 1050px) {
    .nlp-nav-links {
        display: none;
    }

    .nlp-hero-grid {
        grid-template-columns: 1fr;
        gap: 34px;
    }

    .nlp-login {
        width: min(100%, 680px);
    }

    .nlp-overview {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .nlp-module {
        grid-column: span 6;
    }

    .nlp-module--hero,
    .nlp-module--wide {
        grid-column: span 6;
    }

    .nlp-deploy-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .nlp-flow {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        row-gap: 28px;
    }

    .nlp-flow::before {
        display: none;
    }
}

@media (max-width: 820px) {
    .nlp-section {
        padding: 58px 0;
    }

    .nlp-hero {
        padding: 46px 0 48px;
    }

    .nlp-hero-grid,
    .nlp-split,
    .nlp-install-grid,
    .nlp-final-grid {
        grid-template-columns: 1fr;
    }

    .nlp-hero-grid {
        gap: 34px;
    }

    .nlp-login {
        max-width: 620px;
    }

    .nlp-flow {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .nlp-security-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .nlp-final-actions {
        justify-content: flex-start;
    }
}

@media (max-width: 600px) {
    .nlp-nav-shell {
        position: static;
        backdrop-filter: none;
        -webkit-backdrop-filter: none;
    }

    .nlp-nav {
        justify-content: flex-end;
    }

    .nlp-login {
        scroll-margin-top: 18px;
    }

    .nlp-wrap {
        width: min(100% - 22px, var(--nlp-max));
    }

    .nlp-nav {
        min-height: 54px;
    }

    .nlp-nav-cta {
        min-height: 36px;
        padding: 0 11px;
        font-size: .78rem;
    }

    .nlp-hero {
        padding: 30px 0 38px;
    }

    .nlp-hero h1 {
        font-size: clamp(2.5rem, 12vw, 3.7rem);
        line-height: .98;
    }

    .nlp-hero-lede {
        margin-top: 20px;
        font-size: 1rem;
        line-height: 1.65;
    }

    .nlp-hero-actions,
    .nlp-final-actions {
        display: grid;
        grid-template-columns: 1fr;
    }

    .nlp-btn {
        width: 100%;
    }

    .nlp-login {
        padding: 20px;
        border-radius: 16px;
    }

    .nlp-overview,
    .nlp-deploy-grid,
    .nlp-flow,
    .nlp-security-grid {
        grid-template-columns: 1fr;
    }

    .nlp-module-grid {
        display: grid;
        grid-template-columns: 1fr;
    }

    .nlp-module,
    .nlp-module--hero,
    .nlp-module--wide {
        grid-column: 1;
        min-height: 0;
    }

    .nlp-section {
        padding: 46px 0;
    }

    .nlp-section-head {
        margin-bottom: 25px;
    }

    .nlp-section-head h2 {
        font-size: clamp(2rem, 10vw, 2.8rem);
    }

    .nlp-step {
        grid-template-columns: 50px minmax(0, 1fr);
        gap: 12px;
        align-items: start;
        padding: 16px;
    }

    .nlp-step::before {
        width: 42px;
        height: 42px;
    }

    .nlp-step > i {
        display: none;
    }

    .nlp-system-row {
        grid-template-columns: 1fr;
    }

    .nlp-system-arrow {
        transform: rotate(90deg);
    }

    .nlp-terminal pre {
        padding: 17px;
        font-size: .74rem;
    }

    .nlp-final {
        padding-bottom: 50px;
    }

    .nlp-final-box {
        padding: 30px 20px;
        border-radius: 18px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .nlp * {
        scroll-behavior: auto !important;
        transition: none !important;
    }
}






/* ==========================================================================
   LIGHT MODE CONTRAST FIXES
   ========================================================================== */

html[data-theme="light"] .nlp {
    --nlp-panel: #ffffff;
    --nlp-panel-2: #f5f7fb;
    --nlp-border: #cfd7e6;
    --nlp-muted: #4e5970;
    --nlp-text: #182132;
    --nlp-accent: #5b4dff;
    --nlp-accent-2: #007fd6;
    --nlp-shadow: 0 16px 40px rgba(18, 28, 45, 0.08);
    color: var(--nlp-text);
}

html[data-theme="light"] .nlp .nlp-section,
html[data-theme="light"] .nlp .nlp-hero,
html[data-theme="light"] .nlp .nlp-final {
    background: transparent;
}

html[data-theme="light"] .nlp .nlp-section-head h2,
html[data-theme="light"] .nlp .nlp-hero h1,
html[data-theme="light"] .nlp .nlp-hero-tagline,
html[data-theme="light"] .nlp .nlp-panel h3,
html[data-theme="light"] .nlp .nlp-module h3,
html[data-theme="light"] .nlp .nlp-deploy-card h3,
html[data-theme="light"] .nlp .nlp-security h3,
html[data-theme="light"] .nlp .nlp-step h3,
html[data-theme="light"] .nlp .nlp-flow-step h3,
html[data-theme="light"] .nlp .nlp-overview-item strong,
html[data-theme="light"] .nlp .nlp-final h2 {
    color: #182132;
}

html[data-theme="light"] .nlp .nlp-section-head p,
html[data-theme="light"] .nlp .nlp-hero-lede,
html[data-theme="light"] .nlp .nlp-module p,
html[data-theme="light"] .nlp .nlp-panel > p,
html[data-theme="light"] .nlp .nlp-deploy-card p,
html[data-theme="light"] .nlp .nlp-security p,
html[data-theme="light"] .nlp .nlp-step p,
html[data-theme="light"] .nlp .nlp-flow-step p,
html[data-theme="light"] .nlp .nlp-overview-item span,
html[data-theme="light"] .nlp .nlp-check span,
html[data-theme="light"] .nlp .nlp-system-box span,
html[data-theme="light"] .nlp .nlp-final p {
    color: #4e5970;
}

html[data-theme="light"] .nlp .nlp-overview-item,
html[data-theme="light"] .nlp .nlp-module,
html[data-theme="light"] .nlp .nlp-panel,
html[data-theme="light"] .nlp .nlp-deploy-card,
html[data-theme="light"] .nlp .nlp-security,
html[data-theme="light"] .nlp .nlp-step,
html[data-theme="light"] .nlp .nlp-install-menu details,
html[data-theme="light"] .nlp .nlp-faq details,
html[data-theme="light"] .nlp .nlp-login,
html[data-theme="light"] .nlp .nlp-final-box {
    background: #ffffff;
    border-color: #cfd7e6;
    box-shadow: 0 14px 36px rgba(18, 28, 45, 0.07);
}

html[data-theme="light"] .nlp .nlp-module::after {
    background: rgba(91, 77, 255, 0.08);
}

html[data-theme="light"] .nlp .nlp-pill,
html[data-theme="light"] .nlp .nlp-module-tags span {
    background: #eef2f8;
    border-color: #c9d3e1;
    color: #31405b;
}

html[data-theme="light"] .nlp .nlp-eyebrow {
    color: #5d6880;
}

html[data-theme="light"] .nlp .nlp-system-box {
    background: #f4f7fb;
    border-color: #d7dfec;
}

html[data-theme="light"] .nlp .nlp-system-box strong,
html[data-theme="light"] .nlp .nlp-check strong {
    color: #1d2740;
}

html[data-theme="light"] .nlp .nlp-terminal {
    border-color: #d3d9e6;
    box-shadow: 0 16px 40px rgba(18, 28, 45, 0.08);
}

html[data-theme="light"] .nlp .nlp-btn--secondary,
html[data-theme="light"] .nlp .nlp-nav-cta {
    background: #f3f6fb;
    border-color: #cfd7e6;
    color: #1d2740 !important;
}

html[data-theme="light"] .nlp .nlp-btn--ghost {
    color: #43506a !important;
}

html[data-theme="light"] .nlp .nlp-btn--primary {
    color: #ffffff !important;
}

html[data-theme="light"] .nlp .nlp-flow-dot {
    border-color: #edf1f7;
}

html[data-theme="light"] .nlp .nlp-login-links {
    border-top-color: #d6deea;
}

html[data-theme="light"] .nlp .nlp-nav-shell {
    background: rgba(248, 250, 255, .96);
    border-bottom-color: #d7dfec;
}

html[data-theme="light"] .nlp .nlp-nav-links a {
    color: #48566f;
}

html[data-theme="light"] .nlp .nlp-nav-links a:hover,
html[data-theme="light"] .nlp .nlp-nav-links a:focus-visible {
    color: #172033;
    background: #e9eef7;
}

html[data-theme="light"] .nlp .nlp-hero-tagline {
    color: #26324a;
}

</style>

<div class="nlp">

    <!-- ================================================================
         PUBLIC PAGE NAVIGATION
         ================================================================ -->
    <div class="nlp-nav-shell">
        <div class="nlp-wrap nlp-nav">
<nav class="nlp-nav-links" aria-label="Neptune landing page">
                <a href="#platform">Platform</a>
                <a href="#workflow">Workflow</a>
                <a href="#deploy">Deploy</a>
                <a href="#account">Get Started</a>
                <a href="#install">Install</a>
                <a href="#security">Architecture</a>
            </nav>

        </div>
    </div>


    <!-- ================================================================
         HERO
         ================================================================ -->
    <section class="nlp-hero" id="top">
        <div class="nlp-wrap">
            <div class="nlp-hero-grid">

                <div>
<div class="nlp-eyebrow">
                        <i class="fa-solid fa-satellite-dish"></i>
                        Built around the full competition workflow
                    </div>

                    <h1>
                        Neptune <span>FRC Scouting Platform</span>
                    </h1>

                    <p class="nlp-hero-tagline">
                        Scout the match. Understand the robot. Build the strategy.
                    </p>

                    <p class="nlp-hero-lede">
                        Neptune is a complete FIRST Robotics Competition scouting platform for
                        pre-scouting, pit scouting, synchronized live match scouting, team and
                        robot intelligence, analytics, strategy, event control, TBA data, and
                        multi-organization operation. Run it as a hosted account, on your own
                        cloud server, or on a portable local server at an event.
                    </p>

                    <div class="nlp-hero-actions">
                        <a class="nlp-btn nlp-btn--primary" href="<?= e(base_url('register.php')) ?>">
                            <i class="fa-solid fa-rocket"></i>
                            Set Up Your Team
                        </a>

                        <button class="nlp-btn nlp-btn--secondary" type="button" data-nlp-login>
                            <i class="fa-solid fa-right-to-bracket"></i>
                            Sign In
                        </button>

                        <a class="nlp-btn nlp-btn--secondary" href="#platform">
                            <i class="fa-solid fa-compass"></i>
                            Explore Neptune
                        </a>

                        <a class="nlp-btn nlp-btn--ghost"
                           href="<?= e($githubUrl) ?>"
                           target="_blank"
                           rel="noopener noreferrer">
                            <i class="fa-brands fa-github"></i>
                            GitHub
                        </a>
                    </div>

                    <div class="nlp-hero-points">
                        <span class="nlp-pill"><i class="fa-solid fa-users"></i> Multi-team</span>
                        <span class="nlp-pill"><i class="fa-solid fa-wifi"></i> Event-ready</span>
                        <span class="nlp-pill"><i class="fa-solid fa-mobile-screen"></i> Phone &amp; tablet friendly</span>
                        <span class="nlp-pill"><i class="fa-solid fa-code-branch"></i> Self-hostable</span>
                        <span class="nlp-pill"><i class="fa-solid fa-database"></i> Your scouting data</span>
                    </div>
                </div>


                <!-- LOGIN -->
                <aside class="nlp-login" data-nlp-login-target>
                    <div class="nlp-login-head">
                        <div>
                            <h2>Sign in to Neptune</h2>
                            <p>Select your organization and continue to your dashboard.</p>
                        </div>
                        <div class="nlp-login-icon">
                            <i class="fa-solid fa-user-astronaut"></i>
                        </div>
                    </div>

                    <?php if ($error): ?>
                        <div class="notice"><?= e($error) ?></div>
                    <?php endif; ?>

                    <?php if (!$organizations): ?>
                        <div class="notice">
                            Neptune has not been initialized yet.
                            <a href="<?= e(base_url('admin/install.php')) ?>">Run first-time setup</a>.
                        </div>
                    <?php else: ?>
                        <form method="post" autocomplete="on">
                            <label for="organization_id">Organization</label>
                            <select id="organization_id" name="organization_id" required>
                                <option value="">Select organization…</option>
                                <?php foreach ($organizations as $organization): ?>
                                    <option
                                        value="<?= (int)$organization['id'] ?>"
                                        <?= $selectedOrganizationId === (int)$organization['id'] ? 'selected' : '' ?>
                                    ><?= e($organization['name']) ?></option>
                                <?php endforeach; ?>
                            </select>

                            <label for="username">Username</label>
                            <input
                                id="username"
                                name="username"
                                required
                                autocomplete="username"
                            >

                            <label for="password">Password</label>
                            <input
                                id="password"
                                type="password"
                                name="password"
                                required
                                autocomplete="current-password"
                            >

                            <div class="toolbar">
                                <button type="submit">
                                    <i class="fa-solid fa-right-to-bracket"></i>
                                    Sign in
                                </button>
                            </div>
                        </form>

                        <div class="nlp-login-links">
                            <a href="<?= e(base_url('register.php')) ?>">
                                <i class="fa-solid fa-building-circle-check"></i>
                                Register an organization
                            </a>
                        </div>
                    <?php endif; ?>
                </aside>

            </div>
        </div>
    </section>


    <!-- ================================================================
         AT A GLANCE
         ================================================================ -->
    <div class="nlp-wrap">
        <div class="nlp-overview">
            <div class="nlp-overview-item nlp-reveal">
                <strong><i class="fa-solid fa-clipboard-check"></i> Collect</strong>
                <span>Pre-scouting, pits, match actions, successes, failures, notes and robot data.</span>
            </div>

            <div class="nlp-overview-item nlp-reveal">
                <strong><i class="fa-solid fa-tower-broadcast"></i> Coordinate</strong>
                <span>Run match assignments, synchronized scouting and live command-center monitoring.</span>
            </div>

            <div class="nlp-overview-item nlp-reveal">
                <strong><i class="fa-solid fa-chart-line"></i> Understand</strong>
                <span>Turn observations into team comparisons, robot intelligence and event analytics.</span>
            </div>

            <div class="nlp-overview-item nlp-reveal">
                <strong><i class="fa-solid fa-chess"></i> Strategize</strong>
                <span>Prepare matches, analyze opponents and evaluate potential alliance partners.</span>
            </div>
        </div>
    </div>


    <!-- ================================================================
         PLATFORM MODULES
         ================================================================ -->
    <section class="nlp-section" id="platform">
        <div class="nlp-wrap">

            <div class="nlp-section-head nlp-reveal">
                <div class="nlp-eyebrow">
                    <i class="fa-solid fa-cubes"></i>
                    The Platform
                </div>
                <h2>More than a scouting form.</h2>
                <p>
                    Neptune connects the work your team does before an event, in the pits,
                    in the stands, at the strategy table, and during live match operations.
                    Every module is designed to contribute to the same event data set.
                </p>
            </div>

            <div class="nlp-module-grid">

                <article class="nlp-module nlp-reveal nlp-module--hero">
                    <div class="nlp-module-icon"><i class="fa-solid fa-binoculars"></i></div>
                    <h3>Live Match Scouting</h3>
                    <p>
                        Scouts receive an assigned robot and record configured game actions during
                        the match. Neptune tracks success and failure, scoring value, match time,
                        alliance and robot context while the match-control workflow keeps scouting
                        devices synchronized.
                    </p>
                    <div class="nlp-module-tags">
                        <span>Assigned robots</span>
                        <span>Auton + Teleop</span>
                        <span>Swipe outcomes</span>
                        <span>Real-time writes</span>
                    </div>
                </article>

                <article class="nlp-module nlp-reveal">
                    <div class="nlp-module-icon"><i class="fa-solid fa-tower-broadcast"></i></div>
                    <h3>Command Center</h3>
                    <p>
                        Control event scouting from one place. Prepare the next match, monitor
                        scout readiness, start and pause the match state, watch incoming data,
                        and identify missing coverage before it becomes a problem.
                    </p>
                </article>

                <article class="nlp-module nlp-reveal">
                    <div class="nlp-module-icon"><i class="fa-solid fa-robot"></i></div>
                    <h3>Pit Scouting</h3>
                    <p>
                        Build robot profiles with capabilities, mechanical information, drive
                        notes, strategy observations and photos. Use phone cameras in the pit
                        or upload images from another device.
                    </p>
                </article>

                <article class="nlp-module nlp-reveal nlp-module--wide">
                    <div class="nlp-module-icon"><i class="fa-solid fa-magnifying-glass-chart"></i></div>
                    <h3>Pre-Scouting</h3>
                    <p>
                        Research teams before the event and store performance history,
                        robot architecture, scoring capability, drive characteristics,
                        endgame notes and other season context by game and year.
                    </p>
                    <div class="nlp-module-tags">
                        <span>Team history</span>
                        <span>Robot archetype</span>
                        <span>Drive notes</span>
                        <span>Event preparation</span>
                    </div>
                </article>

                <article class="nlp-module nlp-reveal nlp-module--wide">
                    <div class="nlp-module-icon"><i class="fa-solid fa-chart-column"></i></div>
                    <h3>Analytics &amp; Robot Intelligence</h3>
                    <p>
                        Use collected scouting data to compare teams, review scoring and action
                        trends, inspect robot records and move from individual observations to
                        a larger picture of event performance.
                    </p>
                    <div class="nlp-module-tags">
                        <span>Team comparison</span>
                        <span>Raw actions</span>
                        <span>Scoring trends</span>
                        <span>Robot profiles</span>
                    </div>
                </article>

                <article class="nlp-module nlp-reveal">
                    <div class="nlp-module-icon"><i class="fa-solid fa-chess-board"></i></div>
                    <h3>Strategy</h3>
                    <p>
                        Bring scouting and robot intelligence into match planning. Review your
                        alliance, opponents, likely roles, weaknesses, strengths and the data
                        your strategy group needs before talking to the drive team.
                    </p>
                </article>

                <article class="nlp-module nlp-reveal">
                    <div class="nlp-module-icon"><i class="fa-solid fa-shapes"></i></div>
                    <h3>Game Builder</h3>
                    <p>
                        Define the game Neptune scouts. Configure actions, points, field locations,
                        periods and interface behavior, then store that configuration as game data
                        used by events and scouting pages.
                    </p>
                </article>

                <article class="nlp-module nlp-reveal">
                    <div class="nlp-module-icon"><i class="fa-solid fa-calendar-days"></i></div>
                    <h3>Event Management</h3>
                    <p>
                        Create events early, attach the correct game, choose the current event,
                        manage teams and later link a manual event to official event data without
                        throwing away pre-scouting or pit work.
                    </p>
                </article>

                <article class="nlp-module nlp-reveal">
                    <div class="nlp-module-icon"><i class="fa-solid fa-link"></i></div>
                    <h3>The Blue Alliance Sync</h3>
                    <p>
                        Find season events, import team rosters and schedules, or link official
                        TBA data to an event that your team created before the official schedule
                        was available.
                    </p>
                </article>

                <article class="nlp-module nlp-reveal">
                    <div class="nlp-module-icon"><i class="fa-solid fa-people-group"></i></div>
                    <h3>Organizations &amp; Sharing</h3>
                    <p>
                        Neptune supports separate organizations with organization-scoped data.
                        Controlled sharing can make selected scouting information available to
                        partner organizations without changing who owns the original records.
                    </p>
                </article>

                <article class="nlp-module nlp-reveal">
                    <div class="nlp-module-icon"><i class="fa-solid fa-database"></i></div>
                    <h3>Database Lab</h3>
                    <p>
                        Authorized organization owners can explore their scoped scouting data
                        with read-only SQL, aggregates, joins, grouping and analysis while
                        platform-level administration remains isolated.
                    </p>
                </article>

                <article class="nlp-module nlp-reveal">
                    <div class="nlp-module-icon"><i class="fa-solid fa-mobile-screen-button"></i></div>
                    <h3>Installable Web App</h3>
                    <p>
                        Neptune includes web-app assets for a phone- and tablet-friendly
                        experience. It can be placed on home screens and used as the team's
                        event-day scouting interface without requiring a native app store build.
                    </p>
                </article>

            </div>
        </div>
    </section>


    <!-- ================================================================
         EVENT WORKFLOW
         ================================================================ -->
    <section class="nlp-section nlp-section--soft" id="workflow">
        <div class="nlp-wrap">

            <div class="nlp-section-head nlp-reveal">
                <div class="nlp-eyebrow">
                    <i class="fa-solid fa-route"></i>
                    Competition Workflow
                </div>
                <h2>One system from preparation to match strategy.</h2>
                <p>
                    Neptune is organized around the way an FRC scouting group actually works:
                    prepare the game and event, understand the robots, collect match actions,
                    then turn those observations into decisions.
                </p>
            </div>

            <div class="nlp-flow">
                <div class="nlp-flow-step nlp-reveal">
                    <div class="nlp-flow-dot">1</div>
                    <h3>Build the game</h3>
                    <p>Define actions, point values, periods and the scouting interface.</p>
                </div>

                <div class="nlp-flow-step nlp-reveal">
                    <div class="nlp-flow-dot">2</div>
                    <h3>Create the event</h3>
                    <p>Import from TBA or create it manually before official data appears.</p>
                </div>

                <div class="nlp-flow-step nlp-reveal">
                    <div class="nlp-flow-dot">3</div>
                    <h3>Research robots</h3>
                    <p>Complete pre-scouting and pit profiles before qualification scouting fills in the rest.</p>
                </div>

                <div class="nlp-flow-step nlp-reveal">
                    <div class="nlp-flow-dot">4</div>
                    <h3>Scout matches</h3>
                    <p>Assign robots, synchronize scouts and capture actions as the match happens.</p>
                </div>

                <div class="nlp-flow-step nlp-reveal">
                    <div class="nlp-flow-dot">5</div>
                    <h3>Use the data</h3>
                    <p>Analyze teams, prepare match strategy and evaluate alliance possibilities.</p>
                </div>
            </div>


            <div class="nlp-split nlp-mt-48">

                <article class="nlp-panel nlp-reveal">
                    <div class="nlp-eyebrow">
                        <i class="fa-solid fa-stopwatch"></i>
                        During a Match
                    </div>
                    <h3>Synchronized scouting without six isolated forms.</h3>
                    <p>
                        The match-control side of Neptune manages the live match state while
                        scouts focus on the robot in front of them.
                    </p>

                    <div class="nlp-checklist">
                        <div class="nlp-check">
                            <i class="fa-solid fa-user-check"></i>
                            <div>
                                <strong>Assign the robot</strong>
                                <span>Each scout knows exactly which machine and alliance position they are covering.</span>
                            </div>
                        </div>

                        <div class="nlp-check">
                            <i class="fa-solid fa-play"></i>
                            <div>
                                <strong>Control match state</strong>
                                <span>Admins can make ready, start, pause and end the scouting session around the real match.</span>
                            </div>
                        </div>

                        <div class="nlp-check">
                            <i class="fa-solid fa-hand-pointer"></i>
                            <div>
                                <strong>Record outcomes quickly</strong>
                                <span>Game-defined actions are recorded with success/failure behavior designed for rapid event use.</span>
                            </div>
                        </div>

                        <div class="nlp-check">
                            <i class="fa-solid fa-eye"></i>
                            <div>
                                <strong>Watch coverage live</strong>
                                <span>Command Center can see whether scouts are connected and whether data is arriving.</span>
                            </div>
                        </div>
                    </div>
                </article>


                <article class="nlp-panel nlp-reveal">
                    <div class="nlp-eyebrow">
                        <i class="fa-solid fa-diagram-project"></i>
                        Data Flow
                    </div>
                    <h3>Everything contributes to one event picture.</h3>
                    <p>
                        Pre-scouting, pit information and match actions are different kinds of
                        observations, but Neptune brings them together around the same teams,
                        robots, games and events.
                    </p>

                    <div class="nlp-system-map">
                        <div class="nlp-system-row">
                            <div class="nlp-system-box">
                                <strong>Game + Event</strong>
                                <span>Rules, actions, teams and match schedule</span>
                            </div>
                            <div class="nlp-system-arrow"><i class="fa-solid fa-arrow-right"></i></div>
                            <div class="nlp-system-box">
                                <strong>Scout Interfaces</strong>
                                <span>Pre, pit and live match observations</span>
                            </div>
                        </div>

                        <div class="nlp-system-row">
                            <div class="nlp-system-box">
                                <strong>Scouting Database</strong>
                                <span>Organization-owned operational records</span>
                            </div>
                            <div class="nlp-system-arrow"><i class="fa-solid fa-arrow-right"></i></div>
                            <div class="nlp-system-box">
                                <strong>Analytics</strong>
                                <span>Comparisons, trends and robot intelligence</span>
                            </div>
                        </div>

                        <div class="nlp-system-row">
                            <div class="nlp-system-box">
                                <strong>Strategy</strong>
                                <span>Opponent and alliance preparation</span>
                            </div>
                            <div class="nlp-system-arrow"><i class="fa-solid fa-arrow-right"></i></div>
                            <div class="nlp-system-box">
                                <strong>Drive Team</strong>
                                <span>Useful information before the match</span>
                            </div>
                        </div>
                    </div>
                </article>

            </div>
        </div>
    </section>


    <!-- ================================================================
         DEPLOYMENT MODELS
         ================================================================ -->
    <section class="nlp-section" id="deploy">
        <div class="nlp-wrap">

            <div class="nlp-section-head nlp-reveal">
                <div class="nlp-eyebrow">
                    <i class="fa-solid fa-server"></i>
                    Deploy Neptune Your Way
                </div>
                <h2>Hosted, cloud, portable or local.</h2>
                <p>
                    Teams do not all have the same IT resources or event connectivity.
                    Neptune can be used as a hosted account or installed on infrastructure
                    your team controls.
                </p>
            </div>

            <div class="nlp-deploy-grid">

                <article class="nlp-deploy-card nlp-reveal nlp-deploy-card--featured">
                    <div class="nlp-deploy-icon"><i class="fa-solid fa-cloud"></i></div>
                    <span class="nlp-pill nlp-pill--start">
                        <i class="fa-solid fa-bolt"></i> Simplest
                    </span>
                    <h3>Hosted Neptune Account</h3>
                    <p>
                        Use this Neptune installation without managing Apache, PHP, MySQL,
                        certificates, updates or server hardware.
                    </p>
                    <div class="nlp-deploy-list">
                        <span><i class="fa-solid fa-check"></i> Create an organization</span>
                        <span><i class="fa-solid fa-check"></i> Add team users and roles</span>
                        <span><i class="fa-solid fa-check"></i> Configure events and games</span>
                        <span><i class="fa-solid fa-check"></i> Sign in from scout devices</span>
                    </div>
                    <a class="nlp-btn nlp-btn--primary" href="<?= e(base_url('register.php')) ?>">
                        Register Your Team
                    </a>
                </article>

                <article class="nlp-deploy-card nlp-reveal">
                    <div class="nlp-deploy-icon"><i class="fa-brands fa-aws"></i></div>
                    <h3>AWS / VPS / Cloud Server</h3>
                    <p>
                        Run a private Neptune instance on an always-online Linux server with
                        your own hostname, HTTPS certificate and database.
                    </p>
                    <div class="nlp-deploy-list">
                        <span><i class="fa-solid fa-check"></i> Full server control</span>
                        <span><i class="fa-solid fa-check"></i> Accessible from anywhere</span>
                        <span><i class="fa-solid fa-check"></i> Custom domain and TLS</span>
                        <span><i class="fa-solid fa-check"></i> Best for team-owned production</span>
                    </div>
                    <a class="nlp-btn nlp-btn--secondary" href="#install">
                        Cloud Install Guide
                    </a>
                </article>

                <article class="nlp-deploy-card nlp-reveal">
                    <div class="nlp-deploy-icon"><i class="fa-solid fa-suitcase"></i></div>
                    <h3>Portable Event Server</h3>
                    <p>
                        Put Neptune on a laptop, mini PC or Raspberry Pi-class Linux machine,
                        connect it to a local router or access point, and bring the server to
                        the competition.
                    </p>
                    <div class="nlp-deploy-list">
                        <span><i class="fa-solid fa-check"></i> Local event LAN</span>
                        <span><i class="fa-solid fa-check"></i> Scout phones use local Wi-Fi</span>
                        <span><i class="fa-solid fa-check"></i> Core local use can avoid venue internet</span>
                        <span><i class="fa-solid fa-check"></i> TBA/internet features when a connection exists</span>
                    </div>
                    <a class="nlp-btn nlp-btn--secondary" href="#portable">
                        Portable Setup
                    </a>
                </article>

                <article class="nlp-deploy-card nlp-reveal">
                    <div class="nlp-deploy-icon"><i class="fa-solid fa-laptop-code"></i></div>
                    <h3>Local Development</h3>
                    <p>
                        Run Neptune on a developer workstation for testing, Game Builder work,
                        themes, PHP changes and database development before pushing changes to
                        the team server.
                    </p>
                    <div class="nlp-deploy-list">
                        <span><i class="fa-solid fa-check"></i> Git-based workflow</span>
                        <span><i class="fa-solid fa-check"></i> Local Apache/PHP/MySQL</span>
                        <span><i class="fa-solid fa-check"></i> Safe place to test changes</span>
                        <span><i class="fa-solid fa-check"></i> No production data required</span>
                    </div>
                    <a class="nlp-btn nlp-btn--secondary" href="#install">
                        Developer Setup
                    </a>
                </article>

            </div>


            <div class="nlp-split nlp-mt-24" id="portable">
                <article class="nlp-panel nlp-reveal">
                    <div class="nlp-eyebrow">
                        <i class="fa-solid fa-wifi"></i>
                        Portable Event Layout
                    </div>
                    <h3>A private scouting network you can carry into the venue.</h3>
                    <p>
                        A portable installation uses the same web application and database,
                        but the server sits on your local event network instead of depending
                        on a remote web host for every request.
                    </p>

                    <div class="nlp-system-map">
                        <div class="nlp-system-row">
                            <div class="nlp-system-box">
                                <strong>Portable Server</strong>
                                <span>Laptop, mini PC or Linux SBC running Neptune</span>
                            </div>
                            <div class="nlp-system-arrow"><i class="fa-solid fa-arrow-right"></i></div>
                            <div class="nlp-system-box">
                                <strong>Router / Access Point</strong>
                                <span>Dedicated scouting Wi-Fi / local LAN</span>
                            </div>
                        </div>
                        <div class="nlp-system-row">
                            <div class="nlp-system-box">
                                <strong>Scout Devices</strong>
                                <span>Phones, tablets and laptops in the stands</span>
                            </div>
                            <div class="nlp-system-arrow"><i class="fa-solid fa-arrow-right"></i></div>
                            <div class="nlp-system-box">
                                <strong>Local Neptune</strong>
                                <span>Scouting, command, data and strategy on-site</span>
                            </div>
                        </div>
                    </div>
                </article>

                <article class="nlp-panel nlp-reveal">
                    <div class="nlp-eyebrow">
                        <i class="fa-solid fa-shield-halved"></i>
                        Portable Checklist
                    </div>
                    <h3>Prepare the server before competition day.</h3>

                    <div class="nlp-checklist">
                        <div class="nlp-check">
                            <i class="fa-solid fa-network-wired"></i>
                            <div>
                                <strong>Use a predictable local address</strong>
                                <span>Give the server a stable LAN address or local hostname so scout devices always know where Neptune is.</span>
                            </div>
                        </div>
                        <div class="nlp-check">
                            <i class="fa-solid fa-database"></i>
                            <div>
                                <strong>Load the event before leaving</strong>
                                <span>Import the game, team list and available schedule information while internet access is reliable.</span>
                            </div>
                        </div>
                        <div class="nlp-check">
                            <i class="fa-solid fa-battery-full"></i>
                            <div>
                                <strong>Plan power and networking</strong>
                                <span>Bring the server power supply, router/access point, chargers and any battery backup your setup requires.</span>
                            </div>
                        </div>
                        <div class="nlp-check">
                            <i class="fa-solid fa-flask"></i>
                            <div>
                                <strong>Test the entire LAN beforehand</strong>
                                <span>Connect multiple scout devices, run a practice match and verify that Command Center sees every device.</span>
                            </div>
                        </div>
                    </div>
                </article>
            </div>

        </div>
    </section>


    <!-- ================================================================
         HOSTED ACCOUNT SETUP
         ================================================================ -->
    <section class="nlp-section nlp-section--soft" id="account">
        <div class="nlp-wrap">

            <div class="nlp-section-head nlp-reveal">
                <div class="nlp-eyebrow">
                    <i class="fa-solid fa-user-plus"></i>
                    Use Hosted Neptune
                </div>
                <h2>From a new account to your first scouting event.</h2>
                <p>
                    If you want to use the hosted platform, you do not need to install a server.
                    Start with an organization, then configure the team and event.
                </p>
            </div>

            <div class="nlp-steps">
                <article class="nlp-step nlp-reveal">
                    <div>
                        <h3>Register your organization</h3>
                        <p>Create the Neptune organization that will own your team's users, events and scouting records.</p>
                    </div>
                    <i class="fa-solid fa-building"></i>
                </article>

                <article class="nlp-step nlp-reveal">
                    <div>
                        <h3>Create the owner account</h3>
                        <p>The organization owner manages setup, administrative tools, users and organization-level configuration.</p>
                    </div>
                    <i class="fa-solid fa-user-shield"></i>
                </article>

                <article class="nlp-step nlp-reveal">
                    <div>
                        <h3>Add your FRC team and users</h3>
                        <p>Set up the team identity, then create the accounts and roles needed by scouts, strategists and administrators.</p>
                    </div>
                    <i class="fa-solid fa-users"></i>
                </article>

                <article class="nlp-step nlp-reveal">
                    <div>
                        <h3>Select or build the season game</h3>
                        <p>Use the appropriate game configuration so Neptune knows which actions and scoring behaviors scouts should record.</p>
                    </div>
                    <i class="fa-solid fa-shapes"></i>
                </article>

                <article class="nlp-step nlp-reveal">
                    <div>
                        <h3>Create or import the event</h3>
                        <p>Use TBA when official data is available, or create the event early and link it later without losing your pre-scouting and pit work.</p>
                    </div>
                    <i class="fa-solid fa-calendar-days"></i>
                </article>

                <article class="nlp-step nlp-reveal">
                    <div>
                        <h3>Prepare before qualification matches</h3>
                        <p>Complete pre-scouting, collect pit data, confirm the team roster and make sure your scout devices can sign in.</p>
                    </div>
                    <i class="fa-solid fa-clipboard-list"></i>
                </article>

                <article class="nlp-step nlp-reveal">
                    <div>
                        <h3>Run scouting from Command Center</h3>
                        <p>Coordinate the crew, start match sessions and monitor whether scouting data is being collected across the event.</p>
                    </div>
                    <i class="fa-solid fa-tower-broadcast"></i>
                </article>

                <article class="nlp-step nlp-reveal">
                    <div>
                        <h3>Analyze and build strategy</h3>
                        <p>Use the accumulated event data for team analysis, robot intelligence, match preparation and alliance evaluation.</p>
                    </div>
                    <i class="fa-solid fa-chess"></i>
                </article>
            </div>

            <div class="nlp-hero-actions">
                <a class="nlp-btn nlp-btn--primary" href="<?= e(base_url('register.php')) ?>">
                    <i class="fa-solid fa-rocket"></i>
                    Create an Organization
                </a>
                <button class="nlp-btn nlp-btn--secondary" type="button" data-nlp-login>
                    <i class="fa-solid fa-right-to-bracket"></i>
                    Existing Team? Sign In
                </button>
            </div>

        </div>
    </section>


    <!-- ================================================================
         SELF HOST / GITHUB
         ================================================================ -->
    <section class="nlp-section" id="install">
        <div class="nlp-wrap">

            <div class="nlp-section-head nlp-reveal">
                <div class="nlp-eyebrow">
                    <i class="fa-brands fa-github"></i>
                    GitHub &amp; Self-Hosting
                </div>
                <h2>Run your own Neptune instance.</h2>
                <p>
                    Neptune is a PHP/MySQL web application. A self-hosted deployment keeps
                    the public application under the web root and places sensitive
                    configuration outside the public directory.
                </p>
            </div>

            <div class="nlp-install-grid">

                <div class="nlp-install-menu">

                    <details open>
                        <summary>
                            <i class="fa-solid fa-folder-tree"></i>
                            Neptune directory layout
                        </summary>
                        <div class="nlp-detail-body">
                            <p>
                                Keep <strong>public_html/Neptune/</strong> under the web root.
                                Keep <strong>neptune_secure/</strong> beside the public web directory,
                                not inside it.
                            </p>
                            <p>
                                The repository may also contain supporting directories such as
                                <strong>sql/</strong> and <strong>scripts/</strong> for database
                                initialization and server-side operations.
                            </p>
                        </div>
                    </details>

                    <details class="nlp-reveal">
                        <summary>
                            <i class="fa-solid fa-database"></i>
                            Database initialization
                        </summary>
                        <div class="nlp-detail-body">
                            <p>Create a MySQL database for Neptune and import the supplied schema from <strong>sql/neptune_schema.sql</strong>.</p>
                            <p>Then configure Neptune's private database connection settings outside the public application directory.</p>
                        </div>
                    </details>

                    <details class="nlp-reveal">
                        <summary>
                            <i class="fa-solid fa-key"></i>
                            Private configuration
                        </summary>
                        <div class="nlp-detail-body">
                            <p>
                                Copy the provided configuration example to the active private configuration file,
                                then add database credentials and your TBA API configuration.
                            </p>
                            <p>
                                Do not commit live passwords, keys, AWS credentials or private environment files to Git.
                            </p>
                        </div>
                    </details>

                    <details class="nlp-reveal">
                        <summary>
                            <i class="fa-solid fa-user-gear"></i>
                            First organization
                        </summary>
                        <div class="nlp-detail-body">
                            <p>
                                After the files and database are ready, open Neptune's first-time installer
                                and create the initial organization and owner account.
                            </p>
                            <p>
                                Disable, remove or rename the installer after initialization so it cannot
                                be reused on a production deployment.
                            </p>
                        </div>
                    </details>

                    <details class="nlp-reveal">
                        <summary>
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            Production deployment
                        </summary>
                        <div class="nlp-detail-body">
                            <p>
                                Use a real hostname, HTTPS, a supported PHP version, MySQL, appropriate file
                                ownership/permissions and server backups. Test PHP syntax and the application
                                before applying production updates.
                            </p>
                        </div>
                    </details>

                </div>


                <div class="nlp-terminal" aria-label="Example Neptune installation commands">
                    <div class="nlp-terminal-bar">
                        <span class="nlp-terminal-dot"></span>
                        <span class="nlp-terminal-dot"></span>
                        <span class="nlp-terminal-dot"></span>
                        <span class="nlp-terminal-title">Typical Ubuntu / Apache example</span>
                    </div>

<pre><span class="comment"># 1) Install a typical web stack</span>
<span class="cmd">sudo apt update</span>
<span class="cmd">sudo apt install apache2 mysql-server php php-mysql \
php-curl php-mbstring php-xml php-zip php-gd unzip git</span>

<span class="comment"># 2) Clone Neptune</span>
<span class="cmd">git clone <?= e($githubUrl) ?> neptune</span>
<span class="cmd">cd neptune</span>

<span class="comment"># 3) Public/private layout</span>
/var/www/.../public_html/Neptune/
../neptune_secure/
sql/
scripts/

<span class="comment"># 4) Create the database and import Neptune's schema</span>
<span class="cmd">mysql -u root -p</span>
CREATE DATABASE Neptune;
EXIT;
<span class="cmd">mysql -u root -p Neptune &lt; sql/neptune_schema.sql</span>

<span class="comment"># 5) Create the private config</span>
<span class="cmd">cp neptune_secure/config.example.php \
neptune_secure/config.php</span>

<span class="comment"># Add DB credentials + TBA configuration to config.php</span>

<span class="comment"># 6) Point Apache at the public Neptune application</span>
<span class="comment"># Keep neptune_secure OUTSIDE the public web root.</span>

<span class="comment"># 7) Initialize Neptune once in your browser</span>
/Neptune/admin/install.php

<span class="comment"># 8) Create the first organization + owner, then</span>
<span class="comment"># remove/disable the installer on production.</span></pre>
                </div>

            </div>


            <div class="nlp-split nlp-mt-24">

                <article class="nlp-panel nlp-reveal">
                    <div class="nlp-eyebrow">
                        <i class="fa-brands fa-github"></i>
                        Git Workflow
                    </div>
                    <h3>Develop locally. Review changes. Deploy deliberately.</h3>
                    <p>
                        Keep the source in GitHub, make application changes in a development
                        environment, and deploy tested updates to the production or portable server.
                    </p>

                    <div class="nlp-checklist">
                        <div class="nlp-check">
                            <i class="fa-solid fa-code-branch"></i>
                            <div>
                                <strong>Clone or pull the repository</strong>
                                <span>Start from the current source instead of maintaining disconnected copies of the application.</span>
                            </div>
                        </div>
                        <div class="nlp-check">
                            <i class="fa-solid fa-vial"></i>
                            <div>
                                <strong>Test before production</strong>
                                <span>Lint PHP, test the affected workflow and keep a rollback copy before a risky event-week update.</span>
                            </div>
                        </div>
                        <div class="nlp-check">
                            <i class="fa-solid fa-lock"></i>
                            <div>
                                <strong>Keep secrets out of Git</strong>
                                <span>Database passwords, environment files and private keys belong in protected server configuration.</span>
                            </div>
                        </div>
                    </div>

                    <div class="nlp-hero-actions nlp-mt-24">
                        <a class="nlp-btn nlp-btn--secondary"
                           href="<?= e($githubUrl) ?>"
                           target="_blank"
                           rel="noopener noreferrer">
                            <i class="fa-brands fa-github"></i>
                            Open Repository
                        </a>
                    </div>
                </article>


                <article class="nlp-panel nlp-reveal">
                    <div class="nlp-eyebrow">
                        <i class="fa-solid fa-cloud"></i>
                        AWS / VPS Notes
                    </div>
                    <h3>Neptune does not require a special cloud provider.</h3>
                    <p>
                        The application needs a web server, PHP and MySQL. AWS is one way to
                        provide that stack, but another Linux VPS or suitable team-owned server
                        can run the same application.
                    </p>

                    <div class="nlp-checklist">
                        <div class="nlp-check">
                            <i class="fa-solid fa-globe"></i>
                            <div>
                                <strong>Hostname + HTTPS</strong>
                                <span>Use a stable domain and TLS certificate for an internet-facing production instance.</span>
                            </div>
                        </div>
                        <div class="nlp-check">
                            <i class="fa-solid fa-hard-drive"></i>
                            <div>
                                <strong>Back up before major updates</strong>
                                <span>Protect both the application state and database, especially before competition-week changes.</span>
                            </div>
                        </div>
                        <div class="nlp-check">
                            <i class="fa-solid fa-arrows-rotate"></i>
                            <div>
                                <strong>Restart/reload services carefully</strong>
                                <span>Validate configuration and PHP syntax before reloading Apache or applying a production package.</span>
                            </div>
                        </div>
                    </div>
                </article>

            </div>

        </div>
    </section>


    <!-- ================================================================
         SECURITY
         ================================================================ -->
    <section class="nlp-section nlp-section--soft" id="security">
        <div class="nlp-wrap">

            <div class="nlp-section-head nlp-reveal">
                <div class="nlp-eyebrow">
                    <i class="fa-solid fa-shield-halved"></i>
                    Architecture &amp; Security
                </div>
                <h2>Designed for team data, not a pile of public PHP files.</h2>
                <p>
                    Neptune separates public application files from protected configuration,
                    scopes operational data by organization, and uses common web security
                    controls throughout the application.
                </p>
            </div>

            <div class="nlp-security-grid">

                <article class="nlp-security nlp-reveal">
                    <i class="fa-solid fa-key"></i>
                    <h3>Password hashing</h3>
                    <p>User passwords are verified using PHP's password-hashing APIs rather than stored as readable passwords.</p>
                </article>

                <article class="nlp-security nlp-reveal">
                    <i class="fa-solid fa-file-shield"></i>
                    <h3>Private configuration</h3>
                    <p>Database and API configuration can remain outside the public web root instead of being directly served by Apache.</p>
                </article>

                <article class="nlp-security nlp-reveal">
                    <i class="fa-solid fa-database"></i>
                    <h3>Prepared database access</h3>
                    <p>Operational application queries use prepared statements rather than building unsafe SQL from browser input.</p>
                </article>

                <article class="nlp-security nlp-reveal">
                    <i class="fa-solid fa-user-shield"></i>
                    <h3>Role-aware access</h3>
                    <p>Administrative, scouting and organization functions are separated according to the user's authenticated role.</p>
                </article>

                <article class="nlp-security nlp-reveal">
                    <i class="fa-solid fa-building-shield"></i>
                    <h3>Organization scoping</h3>
                    <p>Operational data is associated with organizations so one organization's application view does not become another team's database view.</p>
                </article>

                <article class="nlp-security nlp-reveal">
                    <i class="fa-solid fa-ban"></i>
                    <h3>Read-only analysis controls</h3>
                    <p>Database exploration can remain read-only and organization-scoped rather than exposing unrestricted server administration.</p>
                </article>

            </div>
        </div>
    </section>


    <!-- ================================================================
         FAQ
         ================================================================ -->
    <section class="nlp-section">
        <div class="nlp-wrap">

            <div class="nlp-section-head nlp-reveal">
                <div class="nlp-eyebrow">
                    <i class="fa-solid fa-circle-question"></i>
                    Common Questions
                </div>
                <h2>Which Neptune setup fits your team?</h2>
            </div>

            <div class="nlp-faq">

                <details class="nlp-reveal">
                    <summary>Do we need to run our own server?</summary>
                    <div class="nlp-detail-body">
                        No. A team can register an organization on the hosted Neptune installation
                        and use the platform without managing the underlying server. Self-hosting is
                        available for teams that want their own infrastructure.
                    </div>
                </details>

                <details class="nlp-reveal">
                    <summary>Can Neptune run without relying on venue internet?</summary>
                    <div class="nlp-detail-body">
                        A portable Neptune server can be placed on a local event LAN so scout devices
                        communicate with a server physically at the venue. Internet-dependent services,
                        such as pulling new TBA data, naturally require an internet connection when used.
                    </div>
                </details>

                <details class="nlp-reveal">
                    <summary>Can we prepare an event before TBA publishes everything?</summary>
                    <div class="nlp-detail-body">
                        Yes. Neptune can work with manually created events so pre-scouting and pit work
                        can begin early. When official TBA data becomes available, the event can be linked
                        and synchronized rather than replaced with a duplicate.
                    </div>
                </details>

                <details class="nlp-reveal">
                    <summary>Can multiple organizations use the same Neptune installation?</summary>
                    <div class="nlp-detail-body">
                        Yes. Neptune supports organization-owned operational data and user accounts.
                        Sharing relationships can expose selected categories to another organization
                        without transferring ownership of the original scouting records.
                    </div>
                </details>

                <details class="nlp-reveal">
                    <summary>Does Neptune only work for one FRC game?</summary>
                    <div class="nlp-detail-body">
                        No. Neptune's Game Builder and game configuration model allow events to reference
                        a specific game definition, including its scouting actions and scoring behavior.
                    </div>
                </details>

                <details class="nlp-reveal">
                    <summary>Can developers customize Neptune?</summary>
                    <div class="nlp-detail-body">
                        Yes. Neptune is a PHP/MySQL application with its source managed through GitHub.
                        A local development installation is the safest place to test interface, game,
                        analytics and database changes before deploying them to a production server.
                    </div>
                </details>

            </div>
        </div>
    </section>


    <!-- ================================================================
         FINAL CTA
         ================================================================ -->
    <section class="nlp-final">
        <div class="nlp-wrap">

            <div class="nlp-final-box">
                <div class="nlp-final-grid">

                    <div>
                        <div class="nlp-eyebrow">
                            <i class="fa-solid fa-water"></i>
                            Start With Neptune
                        </div>

                        <h2>Use the hosted platform or make it your own.</h2>

                        <p>
                            Register your team and start configuring Neptune now, or use the
                            GitHub project to deploy a private cloud, portable event or local
                            development instance.
                        </p>
                    </div>

                    <div class="nlp-final-actions">
                        <a class="nlp-btn nlp-btn--primary" href="<?= e(base_url('register.php')) ?>">
                            <i class="fa-solid fa-rocket"></i>
                            Set Up Your Team
                        </a>

                        <a class="nlp-btn nlp-btn--secondary"
                           href="<?= e($githubUrl) ?>"
                           target="_blank"
                           rel="noopener noreferrer">
                            <i class="fa-brands fa-github"></i>
                            View GitHub
                        </a>

                        <button class="nlp-btn nlp-btn--secondary" type="button" data-nlp-login>
                            <i class="fa-solid fa-right-to-bracket"></i>
                            Sign In
                        </button>
                    </div>

                </div>
            </div>

        </div>
    </section>

</div>


<script>
(function () {
    const target = document.querySelector('[data-nlp-login-target]');
    if (!target) return;

    function goToLogin() {
        const siteHeader = document.querySelector('body > header, header');
        const headerHeight = siteHeader
            ? Math.max(0, siteHeader.getBoundingClientRect().height)
            : 0;

        const top = Math.max(
            0,
            target.getBoundingClientRect().top + window.scrollY - headerHeight - 14
        );

        const reduceMotion = window.matchMedia &&
            window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        window.scrollTo({
            top: top,
            behavior: reduceMotion ? 'auto' : 'smooth'
        });
    }

    document.querySelectorAll('[data-nlp-login]').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            goToLogin();
        });
    });
})();
</script>


<script>
(function () {
    const root = document.querySelector('.nlp');
    if (!root) return;

    const reduceMotion = window.matchMedia &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (!reduceMotion) {
        root.classList.add('nlp-motion-ready');

        const revealItems = root.querySelectorAll('.nlp-reveal');

        if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver(function (entries, obs) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) return;
                    entry.target.classList.add('nlp-inview');
                    obs.unobserve(entry.target);
                });
            }, {
                threshold: 0.12,
                rootMargin: '0px 0px -5% 0px'
            });

            revealItems.forEach(function (item) {
                observer.observe(item);
            });
        } else {
            revealItems.forEach(function (item) {
                item.classList.add('nlp-inview');
            });
        }

        /* Lightweight hero parallax. Uses requestAnimationFrame and CSS variables. */
        let ticking = false;

        function updateParallax() {
            const y = Math.min(window.scrollY || 0, 900);
            const center = window.innerWidth / 2;
            const x = ((window.__nlpPointerX || center) - center) * 0.035;

            root.style.setProperty('--nlp-parallax-y', y + 'px');
            root.style.setProperty('--nlp-parallax-x', x + 'px');
            ticking = false;
        }

        function requestParallax() {
            if (ticking) return;
            ticking = true;
            window.requestAnimationFrame(updateParallax);
        }

        window.addEventListener('scroll', requestParallax, { passive: true });

        if (window.matchMedia('(hover:hover) and (pointer:fine)').matches) {
            window.addEventListener('pointermove', function (event) {
                window.__nlpPointerX = event.clientX;
                requestParallax();
            }, { passive: true });
        }

        updateParallax();
    } else {
        root.querySelectorAll('.nlp-reveal').forEach(function (item) {
            item.classList.add('nlp-inview');
        });
    }
})();
</script>


<?php
require_once __DIR__ . '/../app/session_bootstrap.php';
require_once __DIR__ . '/../config/database.php';

$page_title = 'Smart Bridge for Tally';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($page_title) ?> | e-BAL</title>
    <style>
        :root{--navy:#0B1E33;--navy-light:#163352;--teal:#0F766E;--teal-light:#5EEAD4;--orange:#F59E0B;--orange-light:#FBBF24}
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        html{scroll-behavior:smooth;-webkit-text-size-adjust:100%}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen,Ubuntu,Cantarell,sans-serif;color:#1e293b;background:#f8fafc;line-height:1.5;-webkit-font-smoothing:antialiased}
        a{color:inherit;text-decoration:none}

        /* Topbar */
        .topbar{background:var(--navy);padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.06);position:sticky;top:0;z-index:100}
        .topbar .container{width:100%;max-width:1200px;margin:0 auto;padding:0 28px;display:flex;align-items:center;justify-content:space-between}
        .topbar-brand{display:flex;align-items:center;gap:8px}
        .topbar-logo{font-size:18px;font-weight:800;color:#fff;letter-spacing:-0.3px}
        .topbar-logo span{color:var(--teal-light)}
        .topbar-tagline{font-size:11px;color:#94a3b8;font-weight:400}
        .topbar-links{display:flex;align-items:center;gap:12px}
        .topbar-links a{font-size:12px;font-weight:500;color:#cbd5e1;transition:color .2s}
        .topbar-links a:hover{color:#fff}
        .topbar-links .btn-topbar{display:inline-block;padding:5px 14px;background:var(--teal);color:#fff;border-radius:5px;font-size:11px;font-weight:600;transition:background .2s}
        .topbar-links .btn-topbar:hover{background:#115e59}
        @media(max-width:480px){.topbar .container{padding:0 16px}.topbar-tagline{display:none}.topbar-links{gap:8px}}

        /* Container */
        .container{width:100%;max-width:1200px;margin:0 auto;padding:0 28px}
        @media(max-width:480px){.container{padding:0 16px}}

        /* Hero */
        .hero{background:linear-gradient(135deg,var(--navy) 0,#112a45 50%,var(--navy-light) 100%);padding:48px 0 52px;position:relative;overflow:hidden}
        .hero-content{text-align:center;max-width:680px;margin:0 auto}
        .hero-badge{display:inline-flex;align-items:center;gap:5px;background:rgba(94,234,212,0.12);color:var(--teal-light);font-size:10px;font-weight:700;padding:4px 12px;border-radius:999px;text-transform:uppercase;letter-spacing:0.6px;margin-bottom:10px;border:1px solid rgba(94,234,212,0.15)}
        .hero h1{font-size:40px;font-weight:800;color:#fff;letter-spacing:-0.9px;line-height:1.1;margin-bottom:6px}
        .hero h1 span{color:var(--teal-light)}
        .hero-sub{font-size:16px;font-weight:600;color:var(--teal-light);margin-bottom:12px}
        .hero-desc{font-size:14px;color:#94a3b8;line-height:1.6;margin-bottom:24px;max-width:540px;margin-left:auto;margin-right:auto}
        .hero-actions{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
        .hero-meta{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;max-width:500px;margin:24px auto 0;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.06);border-radius:8px;padding:14px;text-align:center}
        .hero-meta-item span{display:block;font-size:9px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.3px;margin-bottom:2px}
        .hero-meta-item strong{font-size:13px;color:#e2e8f0;font-weight:600}
        @media(max-width:600px){.hero{padding:32px 0 36px}.hero h1{font-size:28px}.hero-sub{font-size:14px}.hero-meta{grid-template-columns:1fr 1fr}.hero-actions{flex-direction:column;align-items:center}.hero-actions a{width:100%;max-width:280px;text-align:center}}

        /* Buttons */
        .btn-primary{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 24px;background:var(--teal);color:#fff;border-radius:7px;font-size:13px;font-weight:600;border:none;cursor:pointer;transition:all .2s}
        .btn-primary:hover{background:#115e59;box-shadow:0 4px 16px rgba(15,118,110,0.3)}
        .btn-secondary{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 24px;background:rgba(255,255,255,0.08);color:#e2e8f0;border-radius:7px;font-size:13px;font-weight:600;border:1px solid rgba(255,255,255,0.15);cursor:pointer;transition:all .2s}
        .btn-secondary:hover{background:rgba(255,255,255,0.14);color:#fff}
        .btn-outline{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 24px;background:transparent;color:var(--teal);border-radius:7px;font-size:13px;font-weight:600;border:2px solid var(--teal);cursor:pointer;transition:all .2s}
        .btn-outline:hover{background:var(--teal);color:#fff}
        .btn-lg{padding:14px 32px;font-size:15px;border-radius:8px}
        .btn-orange{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 24px;background:var(--orange);color:#fff;border-radius:7px;font-size:13px;font-weight:600;border:none;cursor:pointer;transition:all .2s}
        .btn-orange:hover{background:#D97706;box-shadow:0 4px 16px rgba(245,158,11,0.3)}

        /* Section */
        .section{padding:40px 0}
        .section-alt{background:#fff}
        .section-header{text-align:center;margin-bottom:28px}
        .section-header h2{font-size:26px;font-weight:800;color:var(--navy);letter-spacing:-0.4px;margin-bottom:6px}
        .section-header p{font-size:14px;color:#64748b;max-width:600px;margin:0 auto}
        @media(max-width:600px){.section{padding:28px 0}.section-header h2{font-size:22px}}

        /* Cards */
        .card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:24px;transition:box-shadow .2s}
        .card h3{font-size:16px;font-weight:700;color:var(--navy);margin-bottom:8px}
        .card p{font-size:13px;color:#64748b;line-height:1.5}

        /* Requirements Grid */
        .req-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;max-width:800px;margin:0 auto}
        .req-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:20px 18px;text-align:center;transition:box-shadow .2s,transform .2s}
        .req-card:hover{box-shadow:0 6px 18px rgba(0,0,0,0.04);transform:translateY(-1px)}
        .req-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:20px}
        .req-icon.os{background:#eff6ff;color:#2563eb}
        .req-icon.tally{background:#f0fdfa;color:var(--teal)}
        .req-icon.ram{background:#fef3c7;color:#d97706}
        .req-card h4{font-size:14px;font-weight:700;color:var(--navy);margin-bottom:4px}
        .req-card p{font-size:12px;color:#64748b}
        @media(max-width:600px){.req-grid{grid-template-columns:1fr}}

        /* Version Info */
        .version-box{max-width:800px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden}
        .version-header{background:linear-gradient(135deg,var(--navy),#112a45);padding:20px 24px;color:#fff;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
        .version-header h3{font-size:18px;font-weight:700;margin:0}
        .version-tag{background:var(--teal);color:#fff;font-size:11px;font-weight:700;padding:4px 12px;border-radius:999px}
        .version-details{padding:20px 24px;display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
        .version-detail{text-align:center}
        .version-detail span{display:block;font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.3px;margin-bottom:2px}
        .version-detail strong{font-size:14px;color:var(--navy);font-weight:600}
        .version-changelog{padding:0 24px 20px}
        .version-changelog h4{font-size:14px;font-weight:700;color:var(--navy);margin-bottom:10px}
        .version-changelog ul{list-style:none;padding:0;margin:0}
        .version-changelog li{font-size:13px;color:#475569;padding:6px 0;border-bottom:1px solid #f1f5f9;display:flex;align-items:flex-start;gap:8px}
        .version-changelog li:last-child{border-bottom:none}
        .version-changelog li::before{content:"";display:inline-block;width:6px;height:6px;background:var(--teal);border-radius:50%;margin-top:6px;flex-shrink:0}
        @media(max-width:600px){.version-details{grid-template-columns:1fr}.version-header{flex-direction:column;text-align:center}}

        /* Download CTA */
        .download-cta{background:linear-gradient(135deg,var(--navy) 0,#112a45 50%,var(--navy-light) 100%);padding:48px 0;text-align:center}
        .download-cta h2{font-size:26px;font-weight:800;color:#fff;letter-spacing:-0.3px;margin-bottom:8px}
        .download-cta p{font-size:14px;color:#94a3b8;max-width:480px;margin:0 auto 20px;line-height:1.5}
        .download-cta .btn-primary{font-size:16px;padding:14px 36px}

        /* Steps */
        .steps-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;max-width:900px;margin:0 auto}
        .step-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:20px 18px;position:relative;transition:box-shadow .2s,transform .2s}
        .step-card:hover{box-shadow:0 6px 18px rgba(0,0,0,0.04);transform:translateY(-1px)}
        .step-num{width:32px;height:32px;background:var(--navy);color:var(--teal-light);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;margin-bottom:12px}
        .step-card h4{font-size:14px;font-weight:700;color:var(--navy);margin-bottom:4px}
        .step-card p{font-size:12px;color:#64748b;line-height:1.5}
        @media(max-width:768px){.steps-grid{grid-template-columns:1fr}}
        @media(max-width:480px){.steps-grid{grid-template-columns:1fr}}

        /* FAQ */
        .faq-list{max-width:740px;margin:0 auto}
        .faq-item{background:#fff;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:10px;overflow:hidden}
        .faq-q{padding:16px 20px;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:12px;font-size:14px;font-weight:600;color:var(--navy);transition:background .2s}
        .faq-q:hover{background:#f8fafc}
        .faq-q::after{content:"+"  ;font-size:18px;font-weight:700;color:var(--teal);flex-shrink:0;transition:transform .2s}
        .faq-item.open .faq-q::after{transform:rotate(45deg)}
        .faq-a{padding:0 20px;max-height:0;overflow:hidden;transition:max-height .3s ease,padding .3s ease}
        .faq-item.open .faq-a{max-height:300px;padding:0 20px 16px}
        .faq-a p{font-size:13px;color:#64748b;line-height:1.6}
        @media(max-width:600px){.faq-q{font-size:13px;padding:14px 16px}}

        /* Support */
        .support-box{max-width:600px;margin:0 auto;text-align:center;background:#f0fdfa;border:1px solid #a7f3d0;border-radius:10px;padding:24px}
        .support-box h3{font-size:16px;font-weight:700;color:var(--navy);margin-bottom:6px}
        .support-box p{font-size:13px;color:#64748b;margin-bottom:14px}
        .support-links{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}

        /* Footer */
        .footer{background:#071526;padding:32px 0 22px;border-top:1px solid rgba(255,255,255,0.04)}
        .footer .container{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
        .footer-brand{font-size:14px;font-weight:700;color:#fff}
        .footer-brand span{color:var(--teal-light)}
        .footer-copy{font-size:11px;color:#64748b}
        @media(max-width:600px){.footer .container{flex-direction:column;text-align:center}}
    </style>
</head>
<body>

<!-- Top Bar -->
<header class="topbar">
    <div class="container">
        <div class="topbar-brand">
            <span class="topbar-logo">e<span>-BAL</span></span>
            <span class="topbar-tagline">Smart Bridge Download Centre</span>
        </div>
        <div class="topbar-links">
            <a href="<?= BASE_URL ?>login.php">Login</a>
            <a href="<?= BASE_URL ?>support.php">Support</a>
            <a href="<?= BASE_URL ?>landing.php" class="btn-topbar">Back to Home</a>
        </div>
    </div>
</header>

<!-- Hero -->
<section class="hero">
    <div class="container">
        <div class="hero-content">
            <div class="hero-badge">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="10" height="10"><polyline points="20 6 9 17 4 12"/></svg>
                Tally Integration Bridge
            </div>
            <h1>Smart Bridge <span>for Tally</span></h1>
            <p class="hero-sub">Seamless Trial Balance Import from Tally to e-BAL</p>
            <p class="hero-desc">Connect your Tally.ERP 9 or Tally Prime directly to e-BAL. Auto-sync trial balance data, import ledgers with one click, and manage multi-company workflows effortlessly.</p>
            <div class="hero-actions">
                <a href="<?= BASE_URL ?>downloads/e-bal-bridge-setup.exe" class="btn-primary btn-lg">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Download Smart Bridge
                </a>
                <a href="#installation-guide" class="btn-secondary btn-lg">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><circle cx="12" cy="12" r="10"/><polyline points="12 16 16 12 12 8"/><line x1="12" y1="16" x2="12" y2="8"/></svg>
                    Installation Guide
                </a>
            </div>
            <div class="hero-meta">
                <div class="hero-meta-item">
                    <span>Version</span>
                    <strong>1.0.0</strong>
                </div>
                <div class="hero-meta-item">
                    <span>Released</span>
                    <strong>June 2026</strong>
                </div>
                <div class="hero-meta-item">
                    <span>Size</span>
                    <strong>~15 MB</strong>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- System Requirements -->
<section class="section section-alt">
    <div class="container">
        <div class="section-header">
            <h2>System Requirements</h2>
            <p>Ensure your system meets the following requirements before installing Smart Bridge.</p>
        </div>
        <div class="req-grid">
            <div class="req-card">
                <div class="req-icon os">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="22" height="22"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                </div>
                <h4>Operating System</h4>
                <p>Windows 10 or later (64-bit recommended)</p>
            </div>
            <div class="req-card">
                <div class="req-icon tally">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="22" height="22"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                </div>
                <h4>Tally Software</h4>
                <p>Tally.ERP 9 or Tally Prime (any edition)</p>
            </div>
            <div class="req-card">
                <div class="req-icon ram">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="22" height="22"><rect x="4" y="4" width="16" height="16" rx="2" ry="2"/><rect x="9" y="9" width="6" height="6"/><line x1="9" y1="1" x2="9" y2="4"/><line x1="15" y1="1" x2="15" y2="4"/><line x1="9" y1="20" x2="9" y2="23"/><line x1="15" y1="20" x2="15" y2="23"/></svg>
                </div>
                <h4>System Resources</h4>
                <p>4 GB RAM, 100 MB free disk space</p>
            </div>
        </div>
    </div>
</section>

<!-- Version Info -->
<section class="section">
    <div class="container">
        <div class="section-header">
            <h2>Version Information</h2>
            <p>Current release details and changelog for Smart Bridge.</p>
        </div>
        <div class="version-box">
            <div class="version-header">
                <h3>Smart Bridge for Tally</h3>
                <span class="version-tag">v1.0.0</span>
            </div>
            <div class="version-details">
                <div class="version-detail">
                    <span>Version</span>
                    <strong>1.0.0</strong>
                </div>
                <div class="version-detail">
                    <span>Release Date</span>
                    <strong>June 2026</strong>
                </div>
                <div class="version-detail">
                    <span>File Size</span>
                    <strong>~15 MB</strong>
                </div>
            </div>
            <div class="version-changelog">
                <h4>What's New in v1.0.0</h4>
                <ul>
                    <li>Initial public release of Smart Bridge for Tally</li>
                    <li>Auto-sync trial balance from Tally.ERP 9 and Tally Prime</li>
                    <li>One-click import of ledger data into e-BAL</li>
                    <li>Multi-company support for managing multiple entities</li>
                    <li>Real-time data fetch via Tally ODBC driver</li>
                    <li>Secure encrypted connection between Tally and e-BAL</li>
                    <li>Background sync with status notifications</li>
                </ul>
            </div>
        </div>
    </div>
</section>

<!-- Download CTA -->
<section class="download-cta">
    <div class="container">
        <h2>Ready to Get Started?</h2>
        <p>Download the Smart Bridge installer and connect Tally to e-BAL in minutes.</p>
        <a href="<?= BASE_URL ?>downloads/e-bal-bridge-setup.exe" class="btn-primary">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Download e-BAL Bridge Setup (v1.0.0)
        </a>
    </div>
</section>

<!-- Installation Guide -->
<section class="section section-alt" id="installation-guide">
    <div class="container">
        <div class="section-header">
            <h2>Installation Guide</h2>
            <p>Follow these simple steps to install and configure Smart Bridge on your system.</p>
        </div>
        <div class="steps-grid">
            <div class="step-card">
                <div class="step-num">1</div>
                <h4>Download the Installer</h4>
                <p>Click the download button above to save the <code>e-bal-bridge-setup.exe</code> installer to your computer.</p>
            </div>
            <div class="step-card">
                <div class="step-num">2</div>
                <h4>Run as Administrator</h4>
                <p>Right-click the downloaded file and select <strong>"Run as administrator"</strong> to start the setup wizard.</p>
            </div>
            <div class="step-card">
                <div class="step-num">3</div>
                <h4>Follow Setup Wizard</h4>
                <p>Accept the license agreement, choose the installation directory, and click <strong>Install</strong> to proceed.</p>
            </div>
            <div class="step-card">
                <div class="step-num">4</div>
                <h4>Launch Smart Bridge</h4>
                <p>After installation, launch Smart Bridge from the Start Menu or desktop shortcut.</p>
            </div>
            <div class="step-card">
                <div class="step-num">5</div>
                <h4>Enter e-BAL Credentials</h4>
                <p>Enter your e-BAL login credentials (email and password) when prompted by the bridge application.</p>
            </div>
            <div class="step-card">
                <div class="step-num">6</div>
                <h4>Connect to Tally</h4>
                <p>Ensure Tally is running. Smart Bridge will detect the Tally ODBC connection and sync your data automatically.</p>
            </div>
        </div>
    </div>
</section>

<!-- Features -->
<section class="section">
    <div class="container">
        <div class="section-header">
            <h2>Key Features</h2>
            <p>Smart Bridge brings powerful integration capabilities between Tally and e-BAL.</p>
        </div>
        <div class="req-grid">
            <div class="req-card">
                <div class="req-icon tally">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="22" height="22"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                </div>
                <h4>Auto-Sync Trial Balance</h4>
                <p>Automatically fetches and syncs trial balance data from Tally in real-time.</p>
            </div>
            <div class="req-card">
                <div class="req-icon os">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="22" height="22"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                </div>
                <h4>One-Click Import</h4>
                <p>Import entire trial balance with a single click. No manual data entry required.</p>
            </div>
            <div class="req-card">
                <div class="req-icon ram">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="22" height="22"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </div>
                <h4>Multi-Company Support</h4>
                <p>Manage and sync data for multiple companies from a single bridge instance.</p>
            </div>
        </div>
    </div>
</section>

<!-- Troubleshooting FAQ -->
<section class="section section-alt" id="faq-section">
    <div class="container">
        <div class="section-header">
            <h2>Troubleshooting FAQ</h2>
            <p>Common issues and their solutions when using Smart Bridge.</p>
        </div>
        <div class="faq-list">
            <div class="faq-item">
                <div class="faq-q" onclick="this.parentElement.classList.toggle('open')">Smart Bridge cannot detect Tally</div>
                <div class="faq-a"><p>Ensure Tally is running and the ODBC connection is enabled. Go to Tally &rarr; Gateway of Tally &rarr; F12 &rarr; Advanced Configuration &rarr; Enable ODBC. The default port is 9000. Also check that no firewall is blocking the connection.</p></div>
            </div>
            <div class="faq-item">
                <div class="faq-q" onclick="this.parentElement.classList.toggle('open')">Connection timeout when syncing data</div>
                <div class="faq-a"><p>This usually happens when Tally has a large dataset. Close other applications to free up system resources. If the issue persists, try restarting Tally and Smart Bridge, then attempt the sync again.</p></div>
            </div>
            <div class="faq-item">
                <div class="faq-q" onclick="this.parentElement.classList.toggle('open')">Login credentials not accepted</div>
                <div class="faq-a"><p>Verify that you are using the same email and password registered on e-BAL. If you have recently changed your password, restart Smart Bridge to refresh the session. Use the "Forgot Password" link on the login page if needed.</p></div>
            </div>
            <div class="faq-item">
                <div class="faq-q" onclick="this.parentElement.classList.toggle('open')">Trial balance data looks incomplete</div>
                <div class="faq-a"><p>Ensure the correct financial year is selected in both Tally and e-BAL. The bridge syncs data for the active financial year only. Check that all ledgers are properly grouped in Tally before syncing.</p></div>
            </div>
            <div class="faq-item">
                <div class="faq-q" onclick="this.parentElement.classList.toggle('open')">Installation fails with permission error</div>
                <div class="faq-a"><p>Right-click the installer and select "Run as administrator". Ensure you have write permissions to the installation directory. Temporarily disable antivirus software if it is blocking the installation.</p></div>
            </div>
            <div class="faq-item">
                <div class="faq-q" onclick="this.parentElement.classList.toggle('open')">How do I uninstall Smart Bridge?</div>
                <div class="faq-a"><p>Go to Windows Settings &rarr; Apps &amp; Features, search for "e-BAL Smart Bridge", and click Uninstall. Alternatively, use the uninstaller from the installation directory or the Start Menu.</p></div>
            </div>
        </div>
    </div>
</section>

<!-- Support Contact -->
<section class="section">
    <div class="container">
        <div class="section-header">
            <h2>Need Help?</h2>
            <p>Our support team is here to assist you with any issues.</p>
        </div>
        <div class="support-box">
            <h3>Contact Support</h3>
            <p>If you encounter any issues not covered above, reach out to our support team.</p>
            <div class="support-links">
                <a href="<?= BASE_URL ?>support.php" class="btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    Contact Support
                </a>
                <a href="mailto:support@ebal.etaxadv.com" class="btn-outline">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    support@ebal.etaxadv.com
                </a>
            </div>
        </div>
    </div>
</section>

<!-- Footer -->
<footer class="footer">
    <div class="container">
        <div class="footer-brand">e<span>-BAL</span></div>
        <div class="footer-copy">&copy; <?= date('Y') ?> e-BAL &mdash; A product by E Tax Advisors Private Limited</div>
    </div>
</footer>

</body>
</html>

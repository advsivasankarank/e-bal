<?php
require_once __DIR__ . '/../app/session_bootstrap.php';
require_once __DIR__ . '/../config/database.php';

$page_title = 'Support Centre';
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
        .hero{background:linear-gradient(135deg,var(--navy) 0,#112a45 50%,var(--navy-light) 100%);padding:48px 0 52px;text-align:center}
        .hero-content{max-width:640px;margin:0 auto}
        .hero h1{font-size:40px;font-weight:800;color:#fff;letter-spacing:-0.9px;line-height:1.1;margin-bottom:8px}
        .hero h1 span{color:var(--teal-light)}
        .hero-sub{font-size:16px;color:#94a3b8;margin-bottom:24px;line-height:1.5}

        /* Search */
        .search-box{max-width:520px;margin:0 auto;position:relative}
        .search-box input{width:100%;padding:14px 20px 14px 46px;border-radius:10px;border:2px solid rgba(255,255,255,0.15);background:rgba(255,255,255,0.08);color:#fff;font-size:14px;outline:none;transition:border-color .2s}
        .search-box input::placeholder{color:#94a3b8}
        .search-box input:focus{border-color:var(--teal-light)}
        .search-box svg{position:absolute;left:16px;top:50%;transform:translateY(-50%);color:#94a3b8}
        @media(max-width:480px){.hero h1{font-size:28px}.hero-sub{font-size:14px}}

        /* Buttons */
        .btn-primary{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 24px;background:var(--teal);color:#fff;border-radius:7px;font-size:13px;font-weight:600;border:none;cursor:pointer;transition:all .2s;text-decoration:none}
        .btn-primary:hover{background:#115e59;box-shadow:0 4px 16px rgba(15,118,110,0.3)}
        .btn-secondary{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 24px;background:rgba(255,255,255,0.08);color:#e2e8f0;border-radius:7px;font-size:13px;font-weight:600;border:1px solid rgba(255,255,255,0.15);cursor:pointer;transition:all .2s;text-decoration:none}
        .btn-secondary:hover{background:rgba(255,255,255,0.14);color:#fff}
        .btn-outline{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 24px;background:transparent;color:var(--teal);border-radius:7px;font-size:13px;font-weight:600;border:2px solid var(--teal);cursor:pointer;transition:all .2s;text-decoration:none}
        .btn-outline:hover{background:var(--teal);color:#fff}

        /* Section */
        .section{padding:40px 0}
        .section-alt{background:#fff}
        .section-header{text-align:center;margin-bottom:28px}
        .section-header h2{font-size:26px;font-weight:800;color:var(--navy);letter-spacing:-0.4px;margin-bottom:6px}
        .section-header p{font-size:14px;color:#64748b;max-width:600px;margin:0 auto}
        @media(max-width:600px){.section{padding:28px 0}.section-header h2{font-size:22px}}

        /* Quick Links Grid */
        .quick-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;max-width:800px;margin:0 auto}
        .quick-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:20px 18px;text-align:center;transition:box-shadow .2s,transform .2s;cursor:pointer;text-decoration:none}
        .quick-card:hover{box-shadow:0 6px 18px rgba(0,0,0,0.04);transform:translateY(-2px)}
        .quick-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:20px}
        .quick-icon.install{background:#eff6ff;color:#2563eb}
        .quick-icon.guide{background:#f0fdfa;color:var(--teal)}
        .quick-icon.faq{background:#fef3c7;color:#d97706}
        .quick-icon.contact{background:#fce7f3;color:#db2777}
        .quick-card h4{font-size:13px;font-weight:700;color:var(--navy);margin-bottom:2px}
        .quick-card p{font-size:11px;color:#64748b}
        @media(max-width:640px){.quick-grid{grid-template-columns:repeat(2,1fr)}}
        @media(max-width:400px){.quick-grid{grid-template-columns:1fr}}

        /* FAQ */
        .faq-list{max-width:740px;margin:0 auto}
        .faq-item{background:#fff;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:10px;overflow:hidden}
        .faq-q{padding:16px 20px;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:12px;font-size:14px;font-weight:600;color:var(--navy);transition:background .2s;user-select:none}
        .faq-q:hover{background:#f8fafc}
        .faq-q::after{content:"+";font-size:18px;font-weight:700;color:var(--teal);flex-shrink:0;transition:transform .2s}
        .faq-item.open .faq-q::after{transform:rotate(45deg)}
        .faq-a{padding:0 20px;max-height:0;overflow:hidden;transition:max-height .3s ease,padding .3s ease}
        .faq-item.open .faq-a{max-height:400px;padding:0 20px 16px}
        .faq-a p{font-size:13px;color:#64748b;line-height:1.6}
        @media(max-width:600px){.faq-q{font-size:13px;padding:14px 16px}}

        /* Contact */
        .contact-grid{display:grid;grid-template-columns:1fr 1fr;gap:28px;align-items:start;max-width:900px;margin:0 auto}
        .contact-info-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:24px}
        .contact-info-card h3{font-size:16px;font-weight:700;color:var(--navy);margin-bottom:14px}
        .contact-detail{display:flex;align-items:flex-start;gap:10px;margin-bottom:14px}
        .contact-detail-icon{width:36px;height:36px;background:#f0fdfa;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--teal)}
        .contact-detail-text strong{display:block;font-size:13px;color:var(--navy);margin-bottom:1px}
        .contact-detail-text span{font-size:12px;color:#64748b;line-height:1.4}
        .contact-detail-text a{color:var(--teal);font-weight:600}
        .contact-detail-text a:hover{text-decoration:underline}

        .contact-form-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:24px}
        .contact-form-card h3{font-size:16px;font-weight:700;color:var(--navy);margin-bottom:14px}
        .form-group{margin-bottom:14px}
        .form-group label{display:block;margin-bottom:6px;font-size:13px;font-weight:600;color:var(--navy)}
        .form-group input,.form-group select,.form-group textarea{width:100%;padding:10px 14px;border-radius:8px;border:1px solid #d1d9e0;background:#fff;color:#1e293b;font-size:13px;transition:border-color .2s,box-shadow .2s;font-family:inherit}
        .form-group input:focus,.form-group textarea:focus{outline:none;border-color:var(--teal);box-shadow:0 0 0 3px rgba(15,118,110,0.1)}
        .form-group textarea{min-height:100px;resize:vertical}
        @media(max-width:768px){.contact-grid{grid-template-columns:1fr}}

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
            <span class="topbar-tagline">Support Centre</span>
        </div>
        <div class="topbar-links">
            <a href="<?= BASE_URL ?>login.php">Login</a>
            <a href="<?= BASE_URL ?>bridge_download.php">Download Bridge</a>
            <a href="<?= BASE_URL ?>landing.php" class="btn-topbar">Back to Home</a>
        </div>
    </div>
</header>

<!-- Hero -->
<section class="hero">
    <div class="container">
        <div class="hero-content">
            <h1>How Can We <span>Help You?</span></h1>
            <p class="hero-sub">Search our knowledge base, browse FAQs, or get in touch with our support team.</p>
            <div class="search-box">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" placeholder="Search for help articles, guides, FAQs...">
            </div>
        </div>
    </div>
</section>

<!-- Quick Links -->
<section class="section section-alt">
    <div class="container">
        <div class="section-header">
            <h2>Quick Links</h2>
            <p>Jump to the most common resources and guides.</p>
        </div>
        <div class="quick-grid">
            <a href="<?= BASE_URL ?>bridge_download.php" class="quick-card">
                <div class="quick-icon install">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="22" height="22"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                </div>
                <h4>Installation Guide</h4>
                <p>Download &amp; install Smart Bridge</p>
            </a>
            <a href="#" class="quick-card">
                <div class="quick-icon guide">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="22" height="22"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                </div>
                <h4>Getting Started</h4>
                <p>First steps with e-BAL</p>
            </a>
            <a href="#faq-section" class="quick-card">
                <div class="quick-icon faq">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="22" height="22"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                </div>
                <h4>FAQ</h4>
                <p>Frequently asked questions</p>
            </a>
            <a href="#contact-section" class="quick-card">
                <div class="quick-icon contact">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="22" height="22"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                </div>
                <h4>Contact Support</h4>
                <p>Get in touch with us</p>
            </a>
        </div>
    </div>
</section>

<!-- FAQ Section -->
<section class="section" id="faq-section">
    <div class="container">
        <div class="section-header">
            <h2>Frequently Asked Questions</h2>
            <p>Find quick answers to the most common questions about e-BAL and Smart Bridge.</p>
        </div>
        <div class="faq-list">
            <div class="faq-item">
                <div class="faq-q" onclick="this.parentElement.classList.toggle('open')">How do I install Smart Bridge?</div>
                <div class="faq-a"><p>Download the installer from our <a href="<?= BASE_URL ?>bridge_download.php" style="color:var(--teal);font-weight:600">Smart Bridge download page</a>. Run the installer as Administrator and follow the setup wizard. Once installed, launch Smart Bridge and enter your e-BAL credentials to connect.</p></div>
            </div>
            <div class="faq-item">
                <div class="faq-q" onclick="this.parentElement.classList.toggle('open')">How do I connect to Tally?</div>
                <div class="faq-a"><p>Ensure Tally.ERP 9 or Tally Prime is running with ODBC enabled (F12 &rarr; Advanced Configuration &rarr; Enable ODBC on port 9000). Launch Smart Bridge, enter your e-BAL credentials, and click "Connect". The bridge will automatically detect Tally via the ODBC driver.</p></div>
            </div>
            <div class="faq-item">
                <div class="faq-q" onclick="this.parentElement.classList.toggle('open')">How do I import trial balance?</div>
                <div class="faq-a"><p>Once connected to Tally, navigate to the Data Dashboard in e-BAL. Select the company and financial year, then click "Fetch Trial Balance". The Smart Bridge will transfer the data from Tally to e-BAL automatically. You can also use the "Import" button for a one-click sync.</p></div>
            </div>
            <div class="faq-item">
                <div class="faq-q" onclick="this.parentElement.classList.toggle('open')">How do I generate financial statements?</div>
                <div class="faq-a"><p>After importing trial balance data and completing the mapping process, go to the Reports Dashboard. Select the company and click "Generate Reports". e-BAL will produce Balance Sheet, Profit &amp; Loss, Cash Flow, and other Schedule III compliant statements in PDF, Excel, or Word format.</p></div>
            </div>
            <div class="faq-item">
                <div class="faq-q" onclick="this.parentElement.classList.toggle('open')">How do I upgrade my plan?</div>
                <div class="faq-a"><p>Navigate to <a href="<?= BASE_URL ?>upgrade.php" style="color:var(--teal);font-weight:600">Upgrade Plan</a> from the top navigation. Review the available plans (Base, Pro, Elite) and click "Pay" next to your preferred plan. Payment is processed securely through Razorpay. Your license activates automatically after successful payment.</p></div>
            </div>
            <div class="faq-item">
                <div class="faq-q" onclick="this.parentElement.classList.toggle('open')">How do I reset my password?</div>
                <div class="faq-a"><p>Click "Forgot your password?" on the <a href="<?= BASE_URL ?>login.php" style="color:var(--teal);font-weight:600">login page</a>. Enter your registered email address and we'll send you a password reset link. The link is valid for 24 hours. Check your spam folder if you don't receive the email.</p></div>
            </div>
            <div class="faq-item">
                <div class="faq-q" onclick="this.parentElement.classList.toggle('open')">What payment methods do you accept?</div>
                <div class="faq-a"><p>We accept all major debit cards, credit cards, UPI, net banking, and wallets through our payment partner Razorpay. All transactions are secure and encrypted. For enterprise or bulk licensing, contact our sales team for custom payment arrangements.</p></div>
            </div>
            <div class="faq-item">
                <div class="faq-q" onclick="this.parentElement.classList.toggle('open')">How do I contact support?</div>
                <div class="faq-a"><p>You can reach us via email at <a href="mailto:support@ebal.etaxadv.com" style="color:var(--teal);font-weight:600">support@ebal.etaxadv.com</a> or use the contact form below. Our support team is available Monday to Saturday, 10 AM to 7 PM IST. For urgent issues, please mention "URGENT" in your subject line.</p></div>
            </div>
        </div>
    </div>
</section>

<!-- Contact Section -->
<section class="section section-alt" id="contact-section">
    <div class="container">
        <div class="section-header">
            <h2>Get in Touch</h2>
            <p>Can't find what you're looking for? Our team is here to help.</p>
        </div>
        <div class="contact-grid">
            <div class="contact-info-card">
                <h3>Contact Information</h3>
                <div class="contact-detail">
                    <div class="contact-detail-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    </div>
                    <div class="contact-detail-text">
                        <strong>Support Email</strong>
                        <a href="mailto:support@ebal.etaxadv.com">support@ebal.etaxadv.com</a>
                    </div>
                </div>
                <div class="contact-detail">
                    <div class="contact-detail-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    </div>
                    <div class="contact-detail-text">
                        <strong>Sales Email</strong>
                        <a href="mailto:sales@ebal.etaxadv.com">sales@ebal.etaxadv.com</a>
                    </div>
                </div>
                <div class="contact-detail">
                    <div class="contact-detail-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    </div>
                    <div class="contact-detail-text">
                        <strong>Phone</strong>
                        <span>+91-XXXXXXXXXX</span>
                    </div>
                </div>
                <div class="contact-detail">
                    <div class="contact-detail-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <div class="contact-detail-text">
                        <strong>Office Hours</strong>
                        <span>Monday &ndash; Saturday, 10:00 AM &ndash; 7:00 PM IST</span>
                    </div>
                </div>
            </div>

            <div class="contact-form-card">
                <h3>Send Us a Message</h3>
                <form method="post" action="#">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <div class="form-group">
                        <label for="contact-name">Your Name</label>
                        <input type="text" id="contact-name" name="name" placeholder="Enter your full name" required>
                    </div>
                    <div class="form-group">
                        <label for="contact-email">Email Address</label>
                        <input type="email" id="contact-email" name="email" placeholder="Enter your email address" required>
                    </div>
                    <div class="form-group">
                        <label for="contact-subject">Subject</label>
                        <input type="text" id="contact-subject" name="subject" placeholder="What is this about?" required>
                    </div>
                    <div class="form-group">
                        <label for="contact-message">Message</label>
                        <textarea id="contact-message" name="message" placeholder="Describe your issue or question in detail..." required></textarea>
                    </div>
                    <button type="submit" class="btn-primary" style="width:100%">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                        Send Message
                    </button>
                </form>
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

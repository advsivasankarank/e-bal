<?php
$page_title = 'Privacy Policy';
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
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen,Ubuntu,Cantarell,sans-serif;color:#1e293b;background:#f8fafc;line-height:1.6;-webkit-font-smoothing:antialiased}
        a{color:inherit;text-decoration:none}

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

        .container{width:100%;max-width:1200px;margin:0 auto;padding:0 28px}
        @media(max-width:480px){.container{padding:0 16px}}

        .hero{background:linear-gradient(135deg,var(--navy) 0,#112a45 50%,var(--navy-light) 100%);padding:48px 0 52px;text-align:center}
        .hero h1{font-size:36px;font-weight:800;color:#fff;letter-spacing:-0.9px;line-height:1.1;margin-bottom:8px}
        .hero h1 span{color:var(--teal-light)}
        .hero-sub{font-size:14px;color:#94a3b8;margin-bottom:6px;line-height:1.5}
        .hero-date{font-size:12px;color:#64748b}
        @media(max-width:600px){.hero h1{font-size:28px}}

        .content-section{padding:40px 0}
        .content-section:nth-child(even){background:#fff}
        .content-inner{max-width:800px;margin:0 auto}
        .content-inner h2{font-size:20px;font-weight:700;color:var(--navy);margin-bottom:12px;padding-bottom:8px;border-bottom:2px solid #e2e8f0}
        .content-inner h3{font-size:16px;font-weight:600;color:var(--navy);margin-top:20px;margin-bottom:8px}
        .content-inner p{font-size:13px;color:#475569;line-height:1.7;margin-bottom:12px}
        .content-inner ul{margin:8px 0 12px 20px}
        .content-inner ul li{font-size:13px;color:#475569;line-height:1.7;margin-bottom:6px}
        .content-inner a{color:var(--teal);font-weight:600}
        .content-inner a:hover{text-decoration:underline}
        .content-inner strong{color:var(--navy);font-weight:600}
        @media(max-width:600px){.content-section{padding:28px 0}.content-inner h2{font-size:18px}}

        .back-link{text-align:center;padding:24px 0}
        .back-link a{display:inline-flex;align-items:center;gap:6px;padding:10px 22px;border-radius:7px;font-size:13px;font-weight:600;background:var(--teal);color:#fff;transition:all .2s}
        .back-link a:hover{background:#115e59;box-shadow:0 4px 16px rgba(15,118,110,0.3)}
        .back-link a svg{width:14px;height:14px}

        .footer{background:#071526;padding:32px 0 22px;border-top:1px solid rgba(255,255,255,0.04)}
        .footer .container{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
        .footer-brand{font-size:14px;font-weight:700;color:#fff}
        .footer-brand span{color:var(--teal-light)}
        .footer-copy{font-size:11px;color:#64748b}
        .footer-links{display:flex;gap:16px}
        .footer-links a{font-size:11px;color:#94a3b8;transition:color .2s}
        .footer-links a:hover{color:var(--teal-light)}
        @media(max-width:600px){.footer .container{flex-direction:column;text-align:center}.footer-links{justify-content:center}}
    </style>
</head>
<body>

<header class="topbar">
    <div class="container">
        <div class="topbar-brand">
            <span class="topbar-logo">e<span>-BAL</span></span>
            <span class="topbar-tagline">Privacy Policy</span>
        </div>
        <div class="topbar-links">
            <a href="login.php">Login</a>
            <a href="landing.php" class="btn-topbar">Back to Home</a>
        </div>
    </div>
</header>

<section class="hero">
    <div class="container">
        <h1>Privacy <span>Policy</span></h1>
        <p class="hero-sub">Your privacy is important to us. This policy explains how we collect, use, and protect your information.</p>
        <p class="hero-date">Effective Date: June 2026 &bull; Last Updated: June 2026</p>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div class="content-inner">
            <h2>1. Information We Collect</h2>
            <p>We collect information necessary to provide the e-BAL platform and deliver our financial statement preparation services. The information we collect falls into the following categories:</p>

            <h3>Account Data</h3>
            <p>When you create an account, we collect your name, email address, phone number, firm name, designation, and billing information. This data is essential for account management, authentication, and communication.</p>

            <h3>Usage Data</h3>
            <p>We automatically collect certain information when you use the platform, including your IP address, browser type, device information, pages visited, features used, and timestamps of activity. This helps us understand how users interact with e-BAL and improve the experience.</p>

            <h3>Tally Data</h3>
            <p>When you import Trial Balance data from Tally through Smart Bridge, we store ledger names, group classifications, balances, and associated financial data. This data is used solely to generate financial statements and is processed within your account environment.</p>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div class="content-inner">
            <h2>2. How We Use Your Information</h2>
            <p>We use the information we collect for the following purposes:</p>
            <ul>
                <li><strong>Service Provision:</strong> To operate, maintain, and deliver the e-BAL platform, including Trial Balance import, ledger mapping, financial statement generation, and report export.</li>
                <li><strong>Communication:</strong> To send account-related notifications, service updates, billing information, and responses to your support queries. We may also send product updates and promotional communications, which you can opt out of at any time.</li>
                <li><strong>Platform Improvement:</strong> To analyse usage patterns, diagnose technical issues, and develop new features and improvements to enhance the platform for all users.</li>
                <li><strong>Security:</strong> To detect and prevent fraud, unauthorised access, and other malicious activities that may harm our platform or users.</li>
                <li><strong>Legal Compliance:</strong> To comply with applicable Indian laws, regulations, and legal processes.</li>
            </ul>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div class="content-inner">
            <h2>3. Data Security</h2>
            <p>We implement robust security measures to protect your data:</p>
            <ul>
                <li><strong>Encryption:</strong> All data in transit is encrypted using TLS 1.2 or higher. Sensitive data at rest is encrypted using AES-256 encryption standards.</li>
                <li><strong>Access Controls:</strong> Access to user data is restricted to authorised personnel on a need-to-know basis. We enforce strict authentication protocols and role-based access controls across all systems.</li>
                <li><strong>Indian Data Residency:</strong> All user data, including Tally financial data, is hosted on servers located within India. Your data does not leave Indian jurisdiction.</li>
                <li><strong>Regular Audits:</strong> We conduct periodic security assessments and vulnerability scans to ensure the ongoing integrity of our systems.</li>
                <li><strong>Incident Response:</strong> We maintain a documented incident response plan to address any security breaches promptly and in accordance with applicable laws.</li>
            </ul>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div class="content-inner">
            <h2>4. Data Retention</h2>
            <p>We retain your data according to the following policy:</p>
            <ul>
                <li><strong>Active Accounts:</strong> All account data, Tally data, and generated reports are retained for the duration of your active subscription. You can request data export or deletion at any time during your subscription.</li>
                <li><strong>Post-Cancellation:</strong> Upon subscription cancellation, all user data and generated reports are permanently deleted within 30 days. We recommend exporting your data before cancellation.</li>
                <li><strong>Backup Retention:</strong> System backups may retain data for up to 60 days for disaster recovery purposes, after which they are permanently purged.</li>
                <li><strong>Legal Holds:</strong> In cases where data is subject to legal proceedings or regulatory requirements, we may retain such data for the duration required by applicable law.</li>
            </ul>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div class="content-inner">
            <h2>5. Your Rights</h2>
            <p>As a user of e-BAL, you have the following rights regarding your personal data:</p>
            <ul>
                <li><strong>Right to Access:</strong> You may request a copy of all personal data we hold about you, including account information and Tally data, in a structured, machine-readable format.</li>
                <li><strong>Right to Correction:</strong> You may request correction of inaccurate or incomplete personal data through your account settings or by contacting us directly.</li>
                <li><strong>Right to Deletion:</strong> You may request deletion of your personal data at any time, subject to any legal obligations that require us to retain certain records. Upon deletion, your account will be permanently closed.</li>
                <li><strong>Right to Withdraw Consent:</strong> You may withdraw consent for data processing at any time by deleting your account or contacting us.</li>
            </ul>
            <p>To exercise any of these rights, please contact us at <a href="mailto:privacy@ebal.etaxadv.com">privacy@ebal.etaxadv.com</a>.</p>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div class="content-inner">
            <h2>6. Cookies and Tracking</h2>
            <p>We use cookies and similar tracking technologies to maintain your session, remember your preferences, and analyse platform usage. Essential cookies are required for the platform to function correctly and cannot be disabled. Analytics cookies help us understand how users interact with e-BAL and are used solely for platform improvement. You can manage cookie preferences through your browser settings, though disabling essential cookies may affect platform functionality.</p>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div class="content-inner">
            <h2>7. Third-Party Services</h2>
            <p>We use the following third-party services that may process your data:</p>
            <ul>
                <li><strong>Razorpay:</strong> We use Razorpay for payment processing. Razorpay collects and processes payment information (card details, UPI IDs, bank details) in accordance with PCI-DSS compliance standards. We do not store your payment card details on our servers. Razorpay's privacy policy governs their handling of payment data.</li>
                <li><strong>Hosting Provider:</strong> Our platform is hosted on infrastructure provided by a certified Indian cloud hosting provider. All data is processed and stored within India, ensuring compliance with Indian data residency requirements.</li>
                <li><strong>Analytics Services:</strong> We may use anonymised analytics services to understand platform usage patterns. These services do not receive personally identifiable information.</li>
            </ul>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div class="content-inner">
            <h2>8. Changes to This Policy</h2>
            <p>We may update this Privacy Policy from time to time to reflect changes in our practices, legal requirements, or operational needs. When we make material changes, we will notify you by email or by posting a prominent notice on the platform. The updated policy will be effective from the date specified in the "Last Updated" field at the top of this page. We encourage you to review this policy periodically. Continued use of the platform after changes are posted constitutes acceptance of the revised policy.</p>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div class="content-inner">
            <h2>9. Contact Us</h2>
            <p>If you have any questions, concerns, or requests regarding this Privacy Policy or our data practices, please contact us:</p>
            <p><strong>Data Protection Officer</strong><br>
            E Tax Advisors Private Limited<br>
            Email: <a href="mailto:privacy@ebal.etaxadv.com">privacy@ebal.etaxadv.com</a></p>
            <p>We aim to respond to all privacy-related inquiries within 5 business days.</p>
        </div>
    </div>
</section>

<section class="back-link">
    <a href="login.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
        Login to e-BAL
    </a>
</section>

<footer class="footer">
    <div class="container">
        <div class="footer-brand">e<span>-BAL</span></div>
        <div class="footer-links">
            <a href="privacy_policy.php">Privacy Policy</a>
            <a href="terms_of_service.php">Terms of Service</a>
            <a href="refund_policy.php">Refund Policy</a>
        </div>
        <div class="footer-copy">&copy; <?= date('Y') ?> e-BAL &mdash; A product by E Tax Advisors Private Limited</div>
    </div>
</footer>

</body>
</html>

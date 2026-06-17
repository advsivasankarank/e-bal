<?php
$page_title = 'Terms of Service';
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
            <span class="topbar-tagline">Terms of Service</span>
        </div>
        <div class="topbar-links">
            <a href="login.php">Login</a>
            <a href="landing.php" class="btn-topbar">Back to Home</a>
        </div>
    </div>
</header>

<section class="hero">
    <div class="container">
        <h1>Terms of <span>Service</span></h1>
        <p class="hero-sub">Please read these terms carefully before using the e-BAL platform.</p>
        <p class="hero-date">Effective Date: June 2026 &bull; Last Updated: June 2026</p>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div class="content-inner">
            <h2>1. Acceptance of Terms</h2>
            <p>By accessing or using the e-BAL platform, you agree to be bound by these Terms of Service and all applicable laws and regulations. If you do not agree with any of these terms, you are prohibited from using or accessing the platform. These terms apply to all users of the platform, including Chartered Accountants, Tax Practitioners, Audit Firms, and other financial professionals.</p>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div class="content-inner">
            <h2>2. Description of Service</h2>
            <p>e-BAL is a cloud-based Financial Statement Preparation Platform designed for Chartered Accountants, Tax Practitioners, and Audit Firms in India. The platform enables users to:</p>
            <ul>
                <li>Import Trial Balance data from Tally Prime, Tally ERP 9, or via XML/Excel/CSV files</li>
                <li>Map ledger accounts to financial statement line items using AI-assisted classification</li>
                <li>Generate professional financial statements including Balance Sheet, Profit &amp; Loss Account, Cash Flow Statement, Schedules, and Notes to Accounts</li>
                <li>Produce statements compliant with Companies Act Schedule III, LLP, Partnership, Proprietorship, Trust, and Society formats</li>
                <li>Export reports in PDF, Excel, and Word formats with comparative year data</li>
            </ul>
            <p>The platform also includes Smart Bridge, a desktop utility that facilitates secure data transfer between Tally and the e-BAL cloud platform.</p>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div class="content-inner">
            <h2>3. User Accounts</h2>
            <p>To use e-BAL, you must create a user account. By creating an account, you agree to the following:</p>
            <ul>
                <li><strong>Account Responsibilities:</strong> You are responsible for maintaining the confidentiality of your login credentials and for all activities that occur under your account. You must notify us immediately of any unauthorised use of your account.</li>
                <li><strong>One Account Per Organisation:</strong> Each organisation or firm is permitted one e-BAL account. Multiple users within a firm may access the account as permitted by the account administrator, subject to the limits of the subscribed plan.</li>
                <li><strong>Accurate Information:</strong> You agree to provide accurate, current, and complete information during registration and to keep your account information up to date.</li>
                <li><strong>Eligibility:</strong> You must be a licensed professional or a registered entity to use the platform. e-BAL is intended for use by Chartered Accountants, Tax Practitioners, Audit Firms, and similar financial professionals.</li>
            </ul>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div class="content-inner">
            <h2>4. Subscription and Payment</h2>
            <p>e-BAL operates on an annual subscription model with the following terms:</p>
            <ul>
                <li><strong>Annual Billing:</strong> Subscriptions are billed annually in advance. The subscription fee for your selected plan is due at the time of purchase and at each annual renewal.</li>
                <li><strong>Auto-Renewal:</strong> Subscriptions automatically renew at the end of each billing period unless cancelled at least 30 days before the renewal date. You will be charged the then-current subscription fee upon renewal.</li>
                <li><strong>GST Invoicing:</strong> All fees are quoted exclusive of GST. applicable GST will be charged in addition to the subscription fee as per Indian tax laws. Valid GST invoices will be provided for all payments.</li>
                <li><strong>Payment Processing:</strong> All payments are processed securely through Razorpay. We do not store your payment card details. By providing payment information, you authorise Razorpay to process payments on your behalf.</li>
                <li><strong>Price Changes:</strong> We reserve the right to modify subscription fees at any time. Price changes will take effect at the start of your next billing cycle, and we will notify you at least 30 days in advance of any price increase.</li>
            </ul>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div class="content-inner">
            <h2>5. Intellectual Property</h2>
            <p>The intellectual property rights in e-BAL are allocated as follows:</p>
            <ul>
                <li><strong>User Data:</strong> You retain full ownership of all data you import into e-BAL, including Trial Balance data, ledger information, and other financial data. You grant us a limited licence to process this data solely for the purpose of providing the platform services to you.</li>
                <li><strong>e-BAL Platform:</strong> E Tax Advisors Private Limited retains all ownership rights to the e-BAL platform, including its software, algorithms, AI-assisted mapping logic, report templates, user interface design, and all related intellectual property.</li>
                <li><strong>Generated Reports:</strong> Financial statements and reports generated by e-BAL are owned by you. You may use, modify, and distribute these reports as you see fit in the course of your professional practice.</li>
                <li><strong>Restrictions:</strong> You may not copy, modify, distribute, sell, lease, or reverse-engineer any part of the e-BAL platform or its underlying technology.</li>
            </ul>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div class="content-inner">
            <h2>6. Acceptable Use</h2>
            <p>You agree to use e-BAL in compliance with all applicable laws and regulations. The following activities are strictly prohibited:</p>
            <ul>
                <li>Reverse engineering, decompiling, or disassembling any part of the platform or its underlying technology</li>
                <li>Sharing your account credentials with unauthorised persons or allowing non-registered users to access the platform through your account</li>
                <li>Using the platform for any illegal purpose, including but not limited to fraudulent financial reporting or tax evasion</li>
                <li>Attempting to gain unauthorised access to other user accounts, systems, or networks connected to the platform</li>
                <li>Uploading malicious code, viruses, or any harmful software to the platform</li>
                <li>Using automated tools (bots, scrapers) to access or interact with the platform without our written consent</li>
                <li>Reselling, sublicensing, or providing the platform as a service to third parties without authorisation</li>
            </ul>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div class="content-inner">
            <h2>7. Limitation of Liability</h2>
            <p>While we strive to provide accurate and reliable financial statement generation, please note the following:</p>
            <ul>
                <li><strong>Not a Substitute for Professional Judgment:</strong> e-BAL is a tool to assist in financial statement preparation. It does not replace the professional judgment, review, or oversight of a qualified Chartered Accountant or financial professional. You are solely responsible for reviewing and verifying all generated reports before use.</li>
                <li><strong>Accuracy of Data:</strong> The quality and accuracy of generated financial statements depend on the accuracy of the Trial Balance data imported and the mappings configured by the user. e-BAL is not liable for errors arising from incorrect source data or incorrect mappings.</li>
                <li><strong>No Warranty:</strong> The platform is provided "as is" and "as available" without warranties of any kind, either express or implied, including but not limited to implied warranties of merchantability or fitness for a particular purpose.</li>
                <li><strong>Limitation of Damages:</strong> To the maximum extent permitted by applicable law, E Tax Advisors Private Limited shall not be liable for any indirect, incidental, special, consequential, or punitive damages, or any loss of profits or revenues, whether incurred directly or indirectly, arising from your use of the platform.</li>
            </ul>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div class="content-inner">
            <h2>8. Termination</h2>
            <p>Either party may terminate the agreement under the following circumstances:</p>
            <ul>
                <li><strong>User Cancellation:</strong> You may cancel your subscription at any time through your account settings. Cancellation takes effect at the end of the current billing period. You will continue to have access to the platform until the subscription expires.</li>
                <li><strong>Termination for Misuse:</strong> We reserve the right to suspend or terminate your account immediately if you breach these Terms of Service, engage in fraudulent activity, or misuse the platform in any way.</li>
                <li><strong>Effect of Termination:</strong> Upon termination, your right to use the platform ceases. We will retain your data for 30 days after termination to allow for data export, after which all data will be permanently deleted.</li>
            </ul>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div class="content-inner">
            <h2>9. Governing Law</h2>
            <p>These Terms of Service are governed by and construed in accordance with the laws of India. Any disputes arising out of or in connection with these terms shall be subject to the exclusive jurisdiction of the courts in Maharashtra, India. By using the platform, you agree to submit to the jurisdiction of the courts of Maharashtra for the resolution of any disputes.</p>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div class="content-inner">
            <h2>10. Contact Us</h2>
            <p>If you have any questions about these Terms of Service, please contact us:</p>
            <p><strong>Legal Department</strong><br>
            E Tax Advisors Private Limited<br>
            Email: <a href="mailto:legal@ebal.etaxadv.com">legal@ebal.etaxadv.com</a></p>
            <p>We aim to respond to all legal inquiries within 5 business days.</p>
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

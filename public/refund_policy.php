<?php
$page_title = 'Refund Policy';
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

        .highlight-box{background:#f0fdfa;border:1px solid #a7f3d0;border-radius:10px;padding:20px;margin:16px 0}
        .highlight-box p{margin:0;font-size:13px;color:#0f766e;line-height:1.7}
        .highlight-box strong{color:#0B1E33}

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
            <span class="topbar-tagline">Refund Policy</span>
        </div>
        <div class="topbar-links">
            <a href="login.php">Login</a>
            <a href="landing.php" class="btn-topbar">Back to Home</a>
        </div>
    </div>
</header>

<section class="hero">
    <div class="container">
        <h1>Refund <span>Policy</span></h1>
        <p class="hero-sub">We want you to be satisfied with e-BAL. This policy outlines our refund terms.</p>
        <p class="hero-date">Effective Date: June 2026 &bull; Last Updated: June 2026</p>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div class="content-inner">
            <h2>1. Overview</h2>
            <p>e-BAL operates on an annual subscription model. When you subscribe to e-BAL, you gain access to the platform's full range of features, including Trial Balance import, ledger mapping, financial statement generation, and report export. We stand behind the quality of our platform and offer a refund policy to ensure your satisfaction.</p>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div class="content-inner">
            <h2>2. Refund Eligibility</h2>
            <p>You are eligible for a full refund under the following conditions:</p>
            <ul>
                <li>The refund request is made <strong>within 7 days</strong> of your initial subscription purchase date.</li>
                <li>You have <strong>not generated 3 or more reports</strong> during the refund period. If you have generated fewer than 3 reports, you remain eligible for a refund.</li>
                <li>The refund request is submitted by the primary account holder who made the original payment.</li>
            </ul>

            <div class="highlight-box">
                <p><strong>Important:</strong> Once 3 or more reports have been generated from your account, the subscription is considered fully utilised and is no longer eligible for a refund. This ensures fair usage while giving you ample opportunity to evaluate the platform.</p>
            </div>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div class="content-inner">
            <h2>3. How to Request a Refund</h2>
            <p>To request a refund, follow these steps:</p>
            <ul>
                <li>Send an email to <a href="mailto:support@ebal.etaxadv.com">support@ebal.etaxadv.com</a> with the subject line "Refund Request".</li>
                <li>Include your <strong>invoice number</strong> (found in your invoice history or the payment confirmation email from Razorpay).</li>
                <li>Provide the <strong>email address</strong> associated with your e-BAL account.</li>
                <li>Optionally, include a brief reason for the refund request to help us improve our service.</li>
            </ul>
            <p>Our support team will acknowledge your request within 1 business day and process the refund within 5-7 business days.</p>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div class="content-inner">
            <h2>4. Refund Processing</h2>
            <p>Once your refund is approved:</p>
            <ul>
                <li><strong>Processing Time:</strong> The refund will be processed within <strong>5-7 business days</strong> from the date of approval.</li>
                <li><strong>Payment Method:</strong> The refund will be credited to the <strong>original payment method</strong> used during the subscription purchase. If you paid via credit/debit card, the refund will be credited back to the same card. For UPI or net banking payments, the refund will be credited to the original bank account.</li>
                <li><strong>Confirmation:</strong> You will receive an email confirmation once the refund has been processed, including the transaction reference number.</li>
                <li><strong>Bank Processing:</strong> After we process the refund, it may take an additional 2-5 business days for the amount to reflect in your account, depending on your bank or card issuer's processing times.</li>
            </ul>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div class="content-inner">
            <h2>5. Non-Refundable Items</h2>
            <p>The following are not eligible for a refund:</p>
            <ul>
                <li><strong>Partial Year Usage:</strong> If you have used the platform for a portion of the subscription period and the refund request is made after 7 days of the purchase date, the subscription fee is non-refundable. There are no prorated refunds for partial-year usage.</li>
                <li><strong>Add-on Services:</strong> Any additional services, custom integrations, or premium support packages purchased separately from the base subscription are non-refundable.</li>
                <li><strong>Renewal Payments:</strong> Auto-renewal payments are non-refundable once processed. To avoid renewal charges, cancel your subscription at least 30 days before the renewal date.</li>
                <li><strong>Reports Generated:</strong> If 3 or more reports have been generated from your account, the subscription is considered fully utilised and no refund will be issued.</li>
            </ul>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div class="content-inner">
            <h2>6. Subscription Cancellation</h2>
            <p>If you choose not to renew your subscription:</p>
            <ul>
                <li>You may cancel your subscription at any time through your account settings or by contacting support.</li>
                <li><strong>Access Continues:</strong> Upon cancellation, your access to the e-BAL platform continues until the end of your current billing period. You will not lose access immediately after cancellation.</li>
                <li><strong>Data Export:</strong> We recommend exporting all your reports and data before the subscription expires. You can download your generated reports in PDF, Excel, or Word format from the Export Centre.</li>
                <li><strong>Data Deletion:</strong> After the subscription period ends, all your account data and generated reports will be permanently deleted within 30 days.</li>
            </ul>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div class="content-inner">
            <h2>7. Contact Us</h2>
            <p>If you have any questions about our refund policy or need assistance with a refund request, please contact us:</p>
            <p><strong>Support Team</strong><br>
            E Tax Advisors Private Limited<br>
            Email: <a href="mailto:support@ebal.etaxadv.com">support@ebal.etaxadv.com</a></p>
            <p>Our support team is available Monday to Saturday, 10:00 AM to 7:00 PM IST. We aim to respond to all refund-related inquiries within 1 business day.</p>
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

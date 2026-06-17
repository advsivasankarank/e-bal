<?php
/**
 * Payment Success Email Template
 * Sent after successful payment processing
 */
?>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: Arial, sans-serif;
            color: #333;
            line-height: 1.6;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        .header {
            background: #4CAF50;
            color: white;
            padding: 20px;
            border-radius: 5px 5px 0 0;
            text-align: center;
        }
        .content {
            padding: 20px;
            background: #f9f9f9;
        }
        .details-box {
            background: white;
            border: 1px solid #ddd;
            padding: 15px;
            margin: 10px 0;
            border-radius: 5px;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: bold;
            color: #666;
        }
        .detail-value {
            color: #333;
        }
        .footer {
            padding: 15px;
            text-align: center;
            font-size: 12px;
            color: #666;
            border-top: 1px solid #ddd;
        }
        .success-icon {
            font-size: 48px;
            color: #4CAF50;
            text-align: center;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>✓ Payment Confirmed</h1>
        <p>Your subscription is now active!</p>
    </div>
    
    <div class="content">
        <p>Hi <strong><?= htmlspecialchars($user_name) ?></strong>,</p>
        
        <p>Thank you for your payment! Your <?= htmlspecialchars($company_name) ?> subscription has been successfully activated.</p>
        
        <div class="details-box">
            <div class="detail-row">
                <span class="detail-label">Plan:</span>
                <span class="detail-value"><?= htmlspecialchars($plan_name) ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Amount Paid:</span>
                <span class="detail-value">₹<?= number_format($amount_inr / 100, 2) ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">License Expires:</span>
                <span class="detail-value"><?= htmlspecialchars($license_expires) ?></span>
            </div>
        </div>
        
        <p><strong>What's Next?</strong></p>
        <ul>
            <li>Log in to your dashboard to access all premium features</li>
            <li>Set up your company profile with complete details</li>
            <li>Invite team members to collaborate</li>
            <li>Start using advanced features available in your plan</li>
        </ul>
        
        <p>If you need any help or have questions, feel free to contact us at <strong><?= htmlspecialchars($support_email) ?></strong>.</p>
        
        <p>Best regards,<br>
        The <?= htmlspecialchars($company_name) ?> Team</p>
    </div>
    
    <div class="footer">
        <p>© 2024 <?= htmlspecialchars($company_name) ?>. All rights reserved.</p>
        <p>This is an automated email. Please do not reply directly.</p>
    </div>
</div>
</body>
</html>

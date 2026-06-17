<?php
/**
 * Subscription Renewal Reminder Email Template
 * Sent 7 days before subscription expires
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
            background: #FF9800;
            color: white;
            padding: 20px;
            border-radius: 5px 5px 0 0;
            text-align: center;
        }
        .content {
            padding: 20px;
            background: #f9f9f9;
        }
        .alert-box {
            background: #fff3cd;
            border: 1px solid #ffc107;
            padding: 15px;
            margin: 10px 0;
            border-radius: 5px;
            color: #856404;
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
        .footer {
            padding: 15px;
            text-align: center;
            font-size: 12px;
            color: #666;
            border-top: 1px solid #ddd;
        }
        .button {
            display: inline-block;
            padding: 10px 20px;
            background: #FF9800;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin: 10px 0;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Subscription Renewal Reminder</h1>
    </div>
    
    <div class="content">
        <p>Hi <strong><?= htmlspecialchars($user_name) ?></strong>,</p>
        
        <div class="alert-box">
            <strong>⚠️ Your subscription is expiring soon!</strong>
            <p style="margin-top: 8px; margin-bottom: 0;">Your <?= htmlspecialchars($plan_name) ?> plan will expire on <strong><?= htmlspecialchars($expiry_date) ?></strong>.</p>
        </div>
        
        <p>To ensure uninterrupted access to your <?= htmlspecialchars($company_name) ?> account, we recommend renewing your subscription now.</p>
        
        <div class="details-box">
            <div class="detail-row">
                <span><strong>Current Plan:</strong></span>
                <span><?= htmlspecialchars($plan_name) ?></span>
            </div>
            <div class="detail-row">
                <span><strong>Renewal Amount:</strong></span>
                <span>₹<?= number_format($amount_inr / 100, 2) ?></span>
            </div>
            <div class="detail-row">
                <span><strong>Expiring On:</strong></span>
                <span><?= htmlspecialchars($expiry_date) ?></span>
            </div>
        </div>
        
        <p><strong>What happens if you don't renew?</strong></p>
        <ul>
            <li>Your account will be suspended after the expiry date</li>
            <li>You won't be able to access your data</li>
            <li>You may lose access to premium features</li>
        </ul>
        
        <p style="text-align: center;">
            <a href="<?= htmlspecialchars(defined('BASE_URL') ? BASE_URL . 'upgrade.php' : '#') ?>" class="button">Renew Now</a>
        </p>
        
        <p>Need help choosing the right plan? Our support team is here to assist you at <strong><?= htmlspecialchars($support_email) ?></strong>.</p>
        
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

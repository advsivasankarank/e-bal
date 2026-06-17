<?php
/**
 * Subscription Expiry Warning Email Template
 * Sent on the expiry date of subscription
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
            background: #f44336;
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
            background: #ffebee;
            border: 1px solid #ef5350;
            padding: 15px;
            margin: 10px 0;
            border-radius: 5px;
            color: #c62828;
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
            background: #f44336;
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
        <h1>⚠️ Subscription Expired</h1>
    </div>
    
    <div class="content">
        <p>Hi <strong><?= htmlspecialchars($user_name) ?></strong>,</p>
        
        <div class="alert-box">
            <strong>Your subscription has expired!</strong>
            <p style="margin-top: 8px; margin-bottom: 0;">Your <?= htmlspecialchars($plan_name) ?> subscription is no longer active. Your account access is limited.</p>
        </div>
        
        <p><strong>What has changed:</strong></p>
        <ul>
            <li>Your account is now in read-only mode</li>
            <li>You cannot create or modify entities</li>
            <li>Premium features are disabled</li>
            <li>Your data is safe and preserved</li>
        </ul>
        
        <p><strong>How to restore access:</strong></p>
        <p>Simply renew your subscription to regain full access to all features and your data. Renewing is quick and easy.</p>
        
        <p style="text-align: center;">
            <a href="<?= htmlspecialchars(defined('BASE_URL') ? BASE_URL . 'upgrade.php' : '#') ?>" class="button">Renew Subscription</a>
        </p>
        
        <p>We'd love to have you back! If you have any questions or need assistance, our support team is ready to help. Contact us at <strong><?= htmlspecialchars($support_email) ?></strong>.</p>
        
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

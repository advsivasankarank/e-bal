<?php
/**
 * Welcome Email Template
 * Sent to new users after account creation
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 5px 5px 0 0;
            text-align: center;
        }
        .content {
            padding: 20px;
            background: #f9f9f9;
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
            background: #667eea;
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
        <h1>Welcome to <?= htmlspecialchars($company_name) ?></h1>
    </div>
    
    <div class="content">
        <p>Hi <strong><?= htmlspecialchars($user_name) ?></strong>,</p>
        
        <p>Thank you for signing up with <?= htmlspecialchars($company_name) ?>! We're excited to have you on board.</p>
        
        <p>Your account is now ready to use. Here's what you can do next:</p>
        
        <ul>
            <li>Log in to your dashboard</li>
            <li>Set up your company profile</li>
            <li>Choose a plan that fits your needs</li>
            <li>Start managing your business data</li>
        </ul>
        
        <p style="text-align: center;">
            <a href="<?= htmlspecialchars($base_url) ?>index.php" class="button">Go to Dashboard</a>
        </p>
        
        <p>If you have any questions or need assistance, our support team is here to help. Feel free to reach out to us at <strong><?= htmlspecialchars($support_email) ?></strong>.</p>
        
        <p>Best regards,<br>
        The <?= htmlspecialchars($company_name) ?> Team</p>
    </div>
    
    <div class="footer">
        <p>© 2024 <?= htmlspecialchars($company_name) ?>. All rights reserved.</p>
        <p>You're receiving this email because you created an account with us.</p>
    </div>
</div>
</body>
</html>

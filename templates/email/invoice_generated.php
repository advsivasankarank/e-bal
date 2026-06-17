<?php
/**
 * Invoice Generated Email Template
 * Sent when an invoice is generated
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
        .invoice-box {
            background: white;
            border: 1px solid #ddd;
            padding: 15px;
            margin: 10px 0;
            border-radius: 5px;
        }
        .invoice-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        .invoice-row:last-child {
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
        <h1>Invoice Generated</h1>
    </div>
    
    <div class="content">
        <p>Hi <strong><?= htmlspecialchars($user_name) ?></strong>,</p>
        
        <p>Your tax invoice has been generated and is ready for download.</p>
        
        <div class="invoice-box">
            <div class="invoice-row">
                <span><strong>Invoice Number:</strong></span>
                <span><?= htmlspecialchars($invoice_number) ?></span>
            </div>
            <div class="invoice-row">
                <span><strong>Invoice Date:</strong></span>
                <span><?= htmlspecialchars($invoice_date) ?></span>
            </div>
            <div class="invoice-row">
                <span><strong>Amount:</strong></span>
                <span>₹<?= number_format($amount / 100, 2) ?></span>
            </div>
        </div>
        
        <p style="text-align: center;">
            <a href="<?= htmlspecialchars($download_url) ?>" class="button">Download Invoice (PDF)</a>
        </p>
        
        <p>You can also view and download all your invoices from your account dashboard at any time.</p>
        
        <p>If you have any questions regarding this invoice, please contact us at <strong><?= htmlspecialchars($support_email) ?></strong>.</p>
        
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

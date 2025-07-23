<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your Email - ORDO Vendor Registration</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f8f9fa;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 30px 20px;
            text-align: center;
            color: white;
        }
        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 600;
        }
        .content {
            padding: 40px 30px;
        }
        .greeting {
            font-size: 18px;
            margin-bottom: 20px;
            color: #2c3e50;
        }
        .message {
            font-size: 16px;
            margin-bottom: 30px;
            line-height: 1.7;
        }
        .verification-steps {
            background-color: #f8f9ff;
            border-left: 4px solid #667eea;
            padding: 20px;
            margin: 25px 0;
            border-radius: 0 6px 6px 0;
        }
        .verification-steps h3 {
            margin-top: 0;
            color: #667eea;
            font-size: 18px;
        }
        .verification-steps ol {
            margin-bottom: 0;
            padding-left: 20px;
        }
        .verification-steps li {
            margin-bottom: 8px;
            color: #555;
        }
        .cta-button {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white !important;
            text-decoration: none;
            padding: 16px 32px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 16px;
            text-align: center;
            margin: 20px 0;
            transition: transform 0.2s ease;
        }
        .cta-button:hover {
            transform: translateY(-2px);
        }
        .security-note {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 6px;
            padding: 15px;
            margin: 25px 0;
            font-size: 14px;
            color: #856404;
        }
        .footer {
            background-color: #f8f9fa;
            padding: 30px;
            text-align: center;
            font-size: 14px;
            color: #6c757d;
            border-top: 1px solid #e9ecef;
        }
        .footer a {
            color: #667eea;
            text-decoration: none;
        }
        .logo {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        @media (max-width: 600px) {
            .container {
                margin: 0;
                border-radius: 0;
            }
            .header, .content, .footer {
                padding: 20px;
            }
            .cta-button {
                display: block;
                width: 100%;
                box-sizing: border-box;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">ORDO</div>
            <h1>Verify Your Email Address</h1>
        </div>

        <div class="content">
            <div class="greeting">
                Hello {{ $user->name }},
            </div>

            <div class="message">
                Welcome to ORDO! Thank you for registering as a vendor on our platform. To complete your vendor registration and start offering your services, we need to verify your email address.
            </div>

            <div style="text-align: center;">
                <a href="{{ $verificationUrl }}" class="cta-button">
                    Verify Email Address
                </a>
            </div>

            <div class="verification-steps">
                <h3>🚀 What happens next?</h3>
                <ol>
                    <li><strong>Email Verification</strong> - Click the button above to verify your email</li>
                    <li><strong>Identity Verification</strong> - Upload a clear photo of your ID or passport</li>
                    <li><strong>Liveness Check</strong> - Take a selfie to confirm you're a real person</li>
                    <li><strong>Business Documents</strong> - Submit your business registration and tax certificates</li>
                    <li><strong>Admin Review</strong> - Our team reviews your application (1-3 business days)</li>
                </ol>
            </div>

            <div class="security-note">
                <strong>Security Note:</strong> This verification link will expire in 24 hours for your security. If you didn't create an account with ORDO, please ignore this email.
            </div>

            <div class="message">
                If you're having trouble clicking the button above, copy and paste the following URL into your web browser:
                <br><br>
                <code style="background-color: #f1f3f4; padding: 8px; border-radius: 4px; font-size: 12px; word-break: break-all;">{{ $verificationUrl }}</code>
            </div>
        </div>

        <div class="footer">
            <p>
                This email was sent to {{ $user->email }}. If you have any questions, please contact our support team at 
                <a href="mailto:support@ordo.co.za">support@ordo.co.za</a>.
            </p>
            <p>
                <a href="{{ config('app.url') }}">Visit ORDO</a> | 
                <a href="{{ config('app.url') }}/privacy">Privacy Policy</a> | 
                <a href="{{ config('app.url') }}/terms">Terms of Service</a>
            </p>
            <p style="margin-top: 20px; font-size: 12px; color: #9ca3af;">
                © {{ date('Y') }} ORDO. All rights reserved.
            </p>
        </div>
    </div>
</body>
</html>
<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;

class EmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        EmailTemplate::updateOrCreate(
            ['slug' => 'password-reset'],
            [
                'name' => 'Password Reset',
                'subject' => 'Reset Your Password — {{app_name}}',
                'body_html' => $this->passwordResetTemplate(),
            ]
        );

        EmailTemplate::updateOrCreate(
            ['slug' => 'new-message'],
            [
                'name' => 'New Message (Offline)',
                'subject' => '{{sender_name}} sent you a message — {{app_name}}',
                'body_html' => $this->newMessageTemplate(),
            ]
        );
    }

    private function newMessageTemplate(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>New Message</title>
</head>
<body style="margin:0;padding:0;background-color:#020617;font-family:'Outfit',Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#020617;padding:40px 20px;">
<tr>
<td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;">

<!-- Logo -->
<tr>
<td align="center" style="padding-bottom:32px;">
<table role="presentation" cellpadding="0" cellspacing="0">
<tr>
<td style="vertical-align:middle;">
<img src="https://pitchflow.devifyo.cloud/logo.svg" width="36" height="36" alt="PitchFlow" style="display:block;border:0;border-radius:8px;" />
</td>
<td style="padding-left:10px;font-size:20px;font-weight:700;color:#f1f5f9;">
{{app_name}}
</td>
</tr>
</table>
</td>
</tr>

<!-- Card -->
<tr>
<td style="background-color:#1e293b;border:1px solid rgba(51,65,85,0.5);border-radius:16px;padding:40px 36px;">

<!-- Heading -->
<h1 style="margin:0 0 8px;font-size:22px;font-weight:700;color:#f1f5f9;">
New message from {{sender_name}}
</h1>
<p style="margin:0 0 24px;font-size:14px;color:#94a3b8;line-height:1.6;">
You received a new message while you were away.
</p>

<!-- Message preview -->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 28px;">
<tr>
<td style="background-color:#0f172a;border:1px solid rgba(51,65,85,0.5);border-radius:12px;padding:16px 20px;">
<p style="margin:0;font-size:13px;font-weight:600;color:#84cc16;">{{sender_name}}</p>
<p style="margin:8px 0 0;font-size:14px;color:#e2e8f0;line-height:1.5;">{{message_preview}}</p>
</td>
</tr>
</table>

<!-- Button -->
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 28px;">
<tr>
<td align="center" style="background-color:#84cc16;border-radius:12px;">
<a href="{{app_url}}/dashboard" target="_blank" style="display:inline-block;padding:14px 32px;font-size:14px;font-weight:600;color:#020617;text-decoration:none;border-radius:12px;">
Open Messages
</a>
</td>
</tr>
</table>

<!-- Note -->
<p style="margin:0;font-size:13px;color:#64748b;line-height:1.5;">
You're receiving this because you were offline when the message was sent.
</p>

</td>
</tr>

<!-- Footer -->
<tr>
<td align="center" style="padding-top:32px;">
<p style="margin:0;font-size:12px;color:#475569;line-height:1.6;">
&copy; {{year}} {{app_name}}. All rights reserved.
</p>
</td>
</tr>

</table>
</td>
</tr>
</table>
</body>
</html>
HTML;
    }

    private function passwordResetTemplate(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Your Password</title>
</head>
<body style="margin:0;padding:0;background-color:#020617;font-family:'Outfit',Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#020617;padding:40px 20px;">
<tr>
<td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;">

<!-- Logo -->
<tr>
<td align="center" style="padding-bottom:32px;">
<table role="presentation" cellpadding="0" cellspacing="0">
<tr>
<td style="vertical-align:middle;">
<img src="https://pitchflow.devifyo.cloud/logo.svg" width="36" height="36" alt="PitchFlow" style="display:block;border:0;border-radius:8px;" />
</td>
<td style="padding-left:10px;font-size:20px;font-weight:700;color:#f1f5f9;">
{{app_name}}
</td>
</tr>
</table>
</td>
</tr>

<!-- Card -->
<tr>
<td style="background-color:#1e293b;border:1px solid rgba(51,65,85,0.5);border-radius:16px;padding:40px 36px;">

<!-- Heading -->
<h1 style="margin:0 0 8px;font-size:22px;font-weight:700;color:#f1f5f9;">
Reset your password
</h1>
<p style="margin:0 0 28px;font-size:14px;color:#94a3b8;line-height:1.6;">
We received a request to reset the password for your account. Click the button below to choose a new password.
</p>

<!-- Button -->
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 28px;">
<tr>
<td align="center" style="background-color:#84cc16;border-radius:12px;">
<a href="{{reset_url}}" target="_blank" style="display:inline-block;padding:14px 32px;font-size:14px;font-weight:600;color:#020617;text-decoration:none;border-radius:12px;">
Reset Password
</a>
</td>
</tr>
</table>

<!-- Expiry note -->
<p style="margin:0 0 24px;font-size:13px;color:#64748b;line-height:1.5;">
This link will expire in {{expiry_minutes}} minutes. If you didn't request a password reset, you can safely ignore this email.
</p>

<!-- Divider -->
<hr style="border:none;border-top:1px solid rgba(51,65,85,0.5);margin:24px 0;">

<!-- Fallback URL -->
<p style="margin:0;font-size:12px;color:#475569;line-height:1.5;">
If the button above doesn't work, copy and paste this link into your browser:
</p>
<p style="margin:8px 0 0;font-size:12px;word-break:break-all;">
<a href="{{reset_url}}" style="color:#84cc16;text-decoration:underline;">{{reset_url}}</a>
</p>

</td>
</tr>

<!-- Footer -->
<tr>
<td align="center" style="padding-top:32px;">
<p style="margin:0;font-size:12px;color:#475569;line-height:1.6;">
&copy; {{year}} {{app_name}}. All rights reserved.
</p>
</td>
</tr>

</table>
</td>
</tr>
</table>
</body>
</html>
HTML;
    }
}

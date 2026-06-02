<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * Branded transactional email for OTP delivery.
 *
 * Uses Brevo HTTP API on Render (SMTP ports are blocked). Falls back to Laravel Mail locally.
 */
class PharmCareMail
{
    public static function sendOtp(string $to, string $code, string $purpose, int $expiresMinutes): void
    {
        $subject = match ($purpose) {
            'registration' => 'PHARMICARE Drugstore — Email Verification Code',
            'password_reset' => 'PHARMICARE Drugstore — Password Reset Code',
            default => 'PHARMICARE Drugstore — Verification Code',
        };

        $intro = match ($purpose) {
            'registration' => 'Thank you for registering with PHARMICARE Drugstore. Use the verification code below to confirm your email address and complete your account setup.',
            'password_reset' => 'We received a request to reset the password for your PHARMICARE Drugstore account. Use the code below to continue.',
            default => 'Use the verification code below to continue.',
        };

        $body = self::htmlTemplate(
            'Verification code',
            $intro,
            $code,
            "This code will expire in {$expiresMinutes} minutes for your security.",
            'If you did not request this code, you can safely ignore this email. No changes will be made to your account.'
        );

        $plain = "Your verification code for PHARMICARE Drugstore is {$code}.\n\n"
            ."This code will expire in {$expiresMinutes} minutes for your security.\n\n"
            ."If you did not request this code, please ignore this email.";

        if (config('services.brevo.key')) {
            self::sendViaBrevoApi($to, $subject, $body, $plain);

            return;
        }

        try {
            Mail::html($body, function ($message) use ($to, $subject) {
                $message->to($to)->subject($subject);
            });
        } catch (\Throwable) {
            Mail::raw($plain, function ($message) use ($to, $subject) {
                $message->to($to)->subject($subject);
            });
        }
    }

    private static function sendViaBrevoApi(string $to, string $subject, string $html, string $plain): void
    {
        $response = Http::timeout(20)
            ->withHeaders([
                'api-key' => config('services.brevo.key'),
                'accept' => 'application/json',
            ])
            ->post('https://api.brevo.com/v3/smtp/email', [
                'sender' => [
                    'name' => config('mail.from.name'),
                    'email' => config('mail.from.address'),
                ],
                'to' => [['email' => $to]],
                'subject' => $subject,
                'htmlContent' => $html,
                'textContent' => $plain,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'Brevo API error ('.$response->status().'): '.$response->body()
            );
        }
    }

    private static function htmlTemplate(
        string $heading,
        string $intro,
        string $code,
        string $expiryNote,
        string $securityNote
    ): string {
        $codeEsc = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        $headingEsc = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
        $introEsc = htmlspecialchars($intro, ENT_QUOTES, 'UTF-8');
        $expiryEsc = htmlspecialchars($expiryNote, ENT_QUOTES, 'UTF-8');
        $securityEsc = htmlspecialchars($securityNote, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$headingEsc}</title>
</head>
<body style="margin:0;padding:0;background:#F4F8FF;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#F4F8FF;padding:32px 16px;">
    <tr>
      <td align="center">
        <table width="100%" style="max-width:520px;background:#FFFFFF;border-radius:14px;overflow:hidden;box-shadow:0 4px 24px rgba(48,80,169,0.12);">
          <tr>
            <td style="background:#3050A9;padding:28px 32px;text-align:center;">
              <div style="color:#FFFFFF;font-size:22px;font-weight:800;letter-spacing:0.3px;">PHARMICARE Drugstore</div>
              <div style="color:rgba(255,255,255,0.85);font-size:13px;margin-top:6px;">Secure account services</div>
            </td>
          </tr>
          <tr>
            <td style="padding:32px;">
              <h1 style="margin:0 0 16px;color:#191E33;font-size:20px;font-weight:800;">{$headingEsc}</h1>
              <p style="margin:0 0 24px;color:#6C6D70;font-size:15px;line-height:1.6;">{$introEsc}</p>
              <div style="text-align:center;margin:0 0 8px;color:#6C6D70;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;">Your verification code</div>
              <div style="text-align:center;margin:0 0 24px;padding:20px;background:#F4F8FF;border-radius:12px;border:1px solid #E5E7EB;">
                <span style="font-size:32px;font-weight:800;letter-spacing:8px;color:#3050A9;">{$codeEsc}</span>
              </div>
              <p style="margin:0 0 12px;color:#6C6D70;font-size:14px;line-height:1.5;">{$expiryEsc}</p>
              <p style="margin:0;color:#9CA3AF;font-size:13px;line-height:1.5;">{$securityEsc}</p>
            </td>
          </tr>
          <tr>
            <td style="padding:20px 32px;background:#FAFBFC;border-top:1px solid #E5E7EB;text-align:center;">
              <p style="margin:0;color:#9CA3AF;font-size:12px;">&copy; PHARMICARE Drugstore. All rights reserved.</p>
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

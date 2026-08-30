<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Email Verification</title>
</head>
<body style="margin:0; padding:0; font-family:Arial, Helvetica, sans-serif; color:#333;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="padding:40px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:480px; border:0; border-collapse:separate;">
          <tr>
            <td style="padding:0;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#ffffff; border:1px solid #e6e6e6; border-radius:8px; overflow:hidden;">
                <tr>
                  <td align="center" style="padding:30px 24px 10px;">
                    <h2 style="margin:0; font-size:20px; color:#111; font-weight:600;">Verify Your Email</h2>
                    <p style="margin:8px 0 0; font-size:14px; color:#666; line-height:1.3;">for your server rental account</p>
                  </td>
                </tr>
                <tr>
                  <td align="center" style="padding:18px 24px;">
                    <p style="margin:0 0 12px; font-size:15px; color:#444;">Enter the code below to continue:</p>
                    <div style="display:inline-block; padding:12px 20px; border-radius:6px; background:#f6fbfb; font-size:28px; letter-spacing:6px; font-weight:700; color:#1da5b8;">
                      {{ $code }}
                    </div>
                  </td>
                </tr>
                <tr>
                  <td align="center" style="padding:12px 24px;">
                    <p style="margin:0; font-size:13px; color:#999;">This code expires in 2 minutes</p>
                  </td>
                </tr>
                <tr>
                  <td align="center" style="padding:18px 24px; border-top:1px solid #eee;">
                    <p style="margin:0; font-size:12px; color:#aaa;">© 2025 ServerNet Cloud. All rights reserved.</p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
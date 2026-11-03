<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Invitation Back-office BANGO / BANGO Back-office Invitation</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f4f6f8; margin: 0; padding: 24px; color: #1a202c;">
    <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.06);">
        <!-- Header -->
        <div style="background-color: #0f172a; padding: 24px 32px; text-align: center;">
            <h1 style="color: #ffffff; margin: 0; font-size: 24px; font-weight: 700; letter-spacing: 1px;">BANGO</h1>
            <p style="color: #94a3b8; margin: 6px 0 0; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;">Portail d'Administration / Administration Portal</p>
        </div>

        <div style="padding: 32px;">
            <!-- Section Française -->
            <div style="margin-bottom: 32px; border-bottom: 1px solid #e2e8f0; padding-bottom: 28px;">
                <h2 style="color: #0f172a; font-size: 18px; margin-top: 0; margin-bottom: 12px;">Bienvenue dans l'équipe BANGO</h2>
                <p style="color: #475569; font-size: 15px; line-height: 1.6; margin: 0 0 16px;">Bonjour,</p>
                <p style="color: #475569; font-size: 15px; line-height: 1.6; margin: 0 0 16px;">
                    Vous avez été invité(e) à rejoindre le back-office BANGO avec le rôle de <strong style="color: #0f172a;">{{ $roleTitle }}</strong>.
                </p>
                <p style="color: #475569; font-size: 15px; line-height: 1.6; margin: 0 0 24px;">
                    Pour activer votre compte, définir votre mot de passe et configurer votre double authentification (2FA TOTP), cliquez sur le bouton ci-dessous :
                </p>

                <div style="text-align: center; margin: 24px 0;">
                    <a href="{{ $activationUrl }}" style="background-color: #2563eb; color: #ffffff; padding: 12px 28px; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 15px; display: inline-block;">
                        Activer mon compte opérateur
                    </a>
                </div>

                <p style="color: #64748b; font-size: 13px; line-height: 1.5; margin: 16px 0 8px;">
                    Ou copiez et collez ce lien sécurisé dans votre navigateur :
                </p>
                <p style="word-break: break-all; color: #2563eb; font-size: 12px; margin: 0 0 20px;">
                    <a href="{{ $activationUrl }}" style="color: #2563eb;">{{ $activationUrl }}</a>
                </p>

                <div style="background-color: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px 16px; border-radius: 4px; margin: 16px 0;">
                    <p style="color: #92400e; font-size: 13px; margin: 0; line-height: 1.5;">
                        ⏳ <strong>Date limite d'activation :</strong> Ce lien expirera le <strong>{{ $expiresAtFormattedFr }}</strong>.
                    </p>
                </div>

                <p style="color: #94a3b8; font-size: 12px; margin: 16px 0 0; line-height: 1.4;">
                    Si vous n'attendiez pas cette invitation, veuillez ignorer ce message ou contacter immédiatement la direction.
                </p>
            </div>

            <!-- English Section -->
            <div>
                <h2 style="color: #0f172a; font-size: 18px; margin-top: 0; margin-bottom: 12px;">Welcome to the BANGO Team</h2>
                <p style="color: #475569; font-size: 15px; line-height: 1.6; margin: 0 0 16px;">Hello,</p>
                <p style="color: #475569; font-size: 15px; line-height: 1.6; margin: 0 0 16px;">
                    You have been invited to join the BANGO back-office with the role of <strong style="color: #0f172a;">{{ $roleTitleEn }}</strong>.
                </p>
                <p style="color: #475569; font-size: 15px; line-height: 1.6; margin: 0 0 24px;">
                    To activate your account, set your password, and configure two-factor authentication (2FA TOTP), click the button below:
                </p>

                <div style="text-align: center; margin: 24px 0;">
                    <a href="{{ $activationUrl }}" style="background-color: #0f172a; color: #ffffff; padding: 12px 28px; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 15px; display: inline-block;">
                        Activate my operator account
                    </a>
                </div>

                <p style="color: #64748b; font-size: 13px; line-height: 1.5; margin: 16px 0 8px;">
                    Or copy and paste this secure link into your browser:
                </p>
                <p style="word-break: break-all; color: #2563eb; font-size: 12px; margin: 0 0 20px;">
                    <a href="{{ $activationUrl }}" style="color: #2563eb;">{{ $activationUrl }}</a>
                </p>

                <div style="background-color: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px 16px; border-radius: 4px; margin: 16px 0;">
                    <p style="color: #92400e; font-size: 13px; margin: 0; line-height: 1.5;">
                        ⏳ <strong>Activation deadline:</strong> This link will expire on <strong>{{ $expiresAtFormattedEn }}</strong>.
                    </p>
                </div>

                <p style="color: #94a3b8; font-size: 12px; margin: 16px 0 0; line-height: 1.4;">
                    If you were not expecting this invitation, please ignore this email or contact administration immediately.
                </p>
            </div>
        </div>

        <!-- Footer -->
        <div style="background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 16px 32px; text-align: center;">
            <p style="color: #94a3b8; font-size: 12px; margin: 0;">
                © {{ date('Y') }} BANGO — Tous droits réservés / All rights reserved.
            </p>
        </div>
    </div>
</body>
</html>

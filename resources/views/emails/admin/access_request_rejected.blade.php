<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Demande d'accès au back-office BANGO / BANGO Back-office Access Request</title>
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
                <h2 style="color: #0f172a; font-size: 18px; margin-top: 0; margin-bottom: 12px;">Mise à jour concernant votre demande d'accès</h2>
                <p style="color: #475569; font-size: 15px; line-height: 1.6; margin: 0 0 16px;">Bonjour {{ $name }},</p>
                <p style="color: #475569; font-size: 15px; line-height: 1.6; margin: 0 0 16px;">
                    Nous vous informons que votre demande d'accès au back-office BANGO pour le rôle de <strong style="color: #0f172a;">{{ $roleTitle }}</strong> a été examinée et n'a pas été retenue par la direction.
                </p>

                @if(!empty($reason))
                <div style="background-color: #f8fafc; border-left: 4px solid #94a3b8; padding: 12px 16px; border-radius: 4px; margin: 16px 0;">
                    <p style="color: #334155; font-size: 14px; margin: 0; line-height: 1.5;">
                        <strong>Motif précisé :</strong> {{ $reason }}
                    </p>
                </div>
                @endif

                <p style="color: #64748b; font-size: 13px; line-height: 1.5; margin: 16px 0 0;">
                    Si vous avez des questions ou s'il s'agit d'une erreur, veuillez vous rapprocher de votre responsable d'équipe ou de la direction.
                </p>
            </div>

            <!-- English Section -->
            <div>
                <h2 style="color: #0f172a; font-size: 18px; margin-top: 0; margin-bottom: 12px;">Update Regarding Your Access Request</h2>
                <p style="color: #475569; font-size: 15px; line-height: 1.6; margin: 0 0 16px;">Hello {{ $name }},</p>
                <p style="color: #475569; font-size: 15px; line-height: 1.6; margin: 0 0 16px;">
                    We inform you that your request for access to the BANGO back-office for the role of <strong style="color: #0f172a;">{{ $roleTitleEn }}</strong> has been reviewed and was not approved by management.
                </p>

                @if(!empty($reason))
                <div style="background-color: #f8fafc; border-left: 4px solid #94a3b8; padding: 12px 16px; border-radius: 4px; margin: 16px 0;">
                    <p style="color: #334155; font-size: 14px; margin: 0; line-height: 1.5;">
                        <strong>Reason provided:</strong> {{ $reason }}
                    </p>
                </div>
                @endif

                <p style="color: #64748b; font-size: 13px; line-height: 1.5; margin: 16px 0 0;">
                    If you have questions or believe this is an error, please reach out to your team lead or management.
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

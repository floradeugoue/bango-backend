<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Accusé de réception : Demande d'accès au back-office BANGO / BANGO Back-office Access Request</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f4f6f8; margin: 0; padding: 24px; color: #1a202c;">
    <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.06);">
        <!-- Header -->
        <div style="background-color: #0f172a; padding: 24px 32px; text-align: center;">
            <h1 style="color: #ffffff; margin: 0; font-size: 24px; font-weight: 700; letter-spacing: 1px;">BANGO</h1>
            <p style="color: #94a3b8; margin: 6px 0 0; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;">Portail d'Administration / Administration Portal</p>
        </div>

        <div style="padding: 32px;">
            <!-- Badge Référence -->
            <div style="text-align: center; margin-bottom: 24px;">
                <span style="display: inline-block; background-color: #eff6ff; color: #2563eb; font-weight: 700; font-size: 14px; padding: 6px 14px; border-radius: 9999px; border: 1px solid #bfdbfe;">
                    Dossier n° {{ $reference }}
                </span>
            </div>

            <!-- Section Française -->
            <div style="margin-bottom: 32px; border-bottom: 1px solid #e2e8f0; padding-bottom: 28px;">
                <h2 style="color: #0f172a; font-size: 18px; margin-top: 0; margin-bottom: 12px;">Accusé de réception de votre demande d'accès</h2>
                <p style="color: #475569; font-size: 15px; line-height: 1.6; margin: 0 0 16px;">Bonjour {{ $name }},</p>
                <p style="color: #475569; font-size: 15px; line-height: 1.6; margin: 0 0 16px;">
                    Nous vous confirmons que votre demande d'accès au back-office BANGO sous la référence <strong style="color: #0f172a;">{{ $reference }}</strong> a bien été enregistrée et transmise à la direction pour examen.
                </p>

                <div style="background-color: #f8fafc; border-left: 4px solid #2563eb; padding: 12px 16px; border-radius: 4px; margin: 16px 0;">
                    <p style="color: #334155; font-size: 14px; margin: 0 0 6px; line-height: 1.5;">
                        <strong>Rôle demandé :</strong> {{ $roleTitle }}
                    </p>
                    <p style="color: #334155; font-size: 14px; margin: 0 0 6px; line-height: 1.5;">
                        <strong>Département :</strong> {{ $accessRequest->department }}
                    </p>
                    <p style="color: #334155; font-size: 14px; margin: 0; line-height: 1.5;">
                        <strong>Délai de traitement estimé :</strong> sous {{ $expectedDelayFr }}
                    </p>
                </div>

                <p style="color: #64748b; font-size: 13px; line-height: 1.5; margin: 16px 0 0;">
                    Dès que votre demande aura été examinée par la direction, vous recevrez une notification par e-mail avec les instructions pour activer votre compte ou les éventuelles précisions.
                </p>
            </div>

            <!-- English Section -->
            <div>
                <h2 style="color: #0f172a; font-size: 18px; margin-top: 0; margin-bottom: 12px;">Acknowledgment of Your Access Request</h2>
                <p style="color: #475569; font-size: 15px; line-height: 1.6; margin: 0 0 16px;">Hello {{ $name }},</p>
                <p style="color: #475569; font-size: 15px; line-height: 1.6; margin: 0 0 16px;">
                    We confirm that your request for access to the BANGO back-office under reference <strong style="color: #0f172a;">{{ $reference }}</strong> has been successfully received and submitted to management for review.
                </p>

                <div style="background-color: #f8fafc; border-left: 4px solid #2563eb; padding: 12px 16px; border-radius: 4px; margin: 16px 0;">
                    <p style="color: #334155; font-size: 14px; margin: 0 0 6px; line-height: 1.5;">
                        <strong>Requested role:</strong> {{ $roleTitleEn }}
                    </p>
                    <p style="color: #334155; font-size: 14px; margin: 0 0 6px; line-height: 1.5;">
                        <strong>Department:</strong> {{ $accessRequest->department }}
                    </p>
                    <p style="color: #334155; font-size: 14px; margin: 0; line-height: 1.5;">
                        <strong>Estimated turnaround time:</strong> within {{ $expectedDelayEn }}
                    </p>
                </div>

                <p style="color: #64748b; font-size: 13px; line-height: 1.5; margin: 16px 0 0;">
                    As soon as management has reviewed your request, you will receive an email notification with instructions to activate your access or further details.
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


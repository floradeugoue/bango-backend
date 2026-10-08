<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <h2>Réinitialisation de votre mot de passe</h2>
        <p>Bonjour,</p>
        <p>Vous avez demandé la réinitialisation de votre mot de passe BANGO. Cliquez sur le bouton ci-dessous pour le modifier :</p>
        <p style="text-align: center; margin: 30px 0;">
            <a href="{{ $resetLink }}" style="background-color: #000; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 4px; font-weight: bold;">
                Réinitialiser mon mot de passe
            </a>
        </p>
        <p>Ou copiez/collez ce lien dans votre navigateur :</p>
        <p style="word-break: break-all; color: #666;"><a href="{{ $resetLink }}">{{ $resetLink }}</a></p>
        <p>Ce lien est valide pendant 60 minutes.</p>
        <p>Si vous n'avez pas demandé de réinitialisation, veuillez ignorer cet email. Votre mot de passe restera inchangé.</p>
        <hr style="border: none; border-top: 1px solid #eee; margin: 30px 0;">
        <p style="font-size: 12px; color: #999;">L'équipe BANGO</p>
    </div>
</body>
</html>

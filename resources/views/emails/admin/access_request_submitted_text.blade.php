BANGO — Portail d'Administration / Administration Portal
======================================================

Accusé de réception de votre demande d'accès (Réf : {{ $reference }})
---------------------------------------------------------------------

Bonjour {{ $name }},

Nous vous confirmons que votre demande d'accès au back-office BANGO sous la référence {{ $reference }} a bien été enregistrée et transmise à la direction pour examen.

- Rôle demandé : {{ $roleTitle }}
- Département : {{ $accessRequest->department }}
- Délai de traitement estimé : sous {{ $expectedDelayFr }}

Vous recevrez une notification par e-mail dès qu'une décision aura été prise par la direction.

---------------------------------------------------------------------

Acknowledgment of Your Access Request (Ref: {{ $reference }})
---------------------------------------------------------------------

Hello {{ $name }},

We confirm that your request for access to the BANGO back-office under reference {{ $reference }} has been successfully received and submitted to management for review.

- Requested role: {{ $roleTitleEn }}
- Department: {{ $accessRequest->department }}
- Estimated turnaround time: within {{ $expectedDelayEn }}

You will receive an email notification as soon as management has reviewed your request.

======================================================
© {{ date('Y') }} BANGO — Tous droits réservés / All rights reserved.


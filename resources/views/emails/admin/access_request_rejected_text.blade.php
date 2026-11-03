BANGO - Portail d'Administration / Administration Portal
================================================================

[FR] MISE À JOUR CONCERNANT VOTRE DEMANDE D'ACCÈS
----------------------------------------------------------------
Bonjour {{ $name }},

Nous vous informons que votre demande d'accès au back-office BANGO pour le rôle de {{ $roleTitle }} n'a pas été retenue par la direction.

@if(!empty($reason))
Motif précisé : {{ $reason }}
@endif

Si vous avez des questions ou s'il s'agit d'une erreur, veuillez vous rapprocher de votre responsable d'équipe ou de la direction.


[EN] UPDATE REGARDING YOUR ACCESS REQUEST
----------------------------------------------------------------
Hello {{ $name }},

We inform you that your request for access to the BANGO back-office for the role of {{ $roleTitleEn }} was not approved by management.

@if(!empty($reason))
Reason provided: {{ $reason }}
@endif

If you have questions or believe this is an error, please reach out to your team lead or management.

----------------------------------------------------------------
© {{ date('Y') }} BANGO — Tous droits réservés / All rights reserved.

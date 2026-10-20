<?php

namespace App\Models\Message;

/**
 * Modèle Message dédié à la relation latestOfMany('created_at').
 * L'utilisation de 'created_at' comme clé primaire évite l'agrégat MAX(id)
 * généré automatiquement par Laravel qui provoque l'erreur "function max(uuid) does not exist"
 * sous PostgreSQL.
 */
class LatestMessage extends Message
{
    protected $primaryKey = 'created_at';

    public $incrementing = false;

    protected $keyType = 'string';
}

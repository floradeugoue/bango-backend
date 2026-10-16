<?php

namespace App\Enums\Support;

enum ReportReason: string
{
    case Spam = 'spam';
    case Fraud = 'fraud';
    case Harassment = 'harassment';
    case InappropriateContent = 'inappropriate_content';
    case Copyright = 'copyright';
    case FakeListing = 'fake_listing';
    case Violence = 'violence';
    case HateSpeech = 'hate_speech';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Spam => 'Spam ou publicité abusive',
            self::Fraud => 'Arnaque ou tentative de fraude',
            self::Harassment => 'Harcèlement ou intimidation',
            self::InappropriateContent => 'Contenu inapproprié ou explicite',
            self::Copyright => 'Atteinte aux droits d\'auteur',
            self::FakeListing => 'Fausse annonce immobilière',
            self::Violence => 'Incitation à la violence',
            self::HateSpeech => 'Propos haineux ou discriminatoires',
            self::Other => 'Autre motif',
        };
    }
}

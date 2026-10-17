<?php

namespace App\Services\Admin;

class AdminPasswordPolicy
{
    public const MIN_LENGTH = 12;

    public const MAX_LENGTH = 128;

    /**
     * @return array<string, mixed>
     */
    public static function getPolicy(): array
    {
        return [
            'min_length' => self::MIN_LENGTH,
            'max_length' => self::MAX_LENGTH,
            'require_uppercase' => true,
            'require_lowercase' => true,
            'require_numbers' => true,
            'require_symbols' => true,
            'description' => 'Au moins 12 caractères, comprenant une majuscule, une minuscule, un chiffre et un caractère spécial.',
        ];
    }

    /**
     * Règles de validation Laravel standard pour les mots de passe opérateurs.
     *
     * @return array<mixed>
     */
    public static function validationRules(bool $confirmed = true): array
    {
        $rules = [
            'required',
            'string',
            'min:'.self::MIN_LENGTH,
            'max:'.self::MAX_LENGTH,
            'regex:/[A-Z]/', // Au moins une majuscule
            'regex:/[a-z]/', // Au moins une minuscule
            'regex:/[0-9]/', // Au moins un chiffre
            'regex:/[\W_]/', // Au moins un caractère spécial
        ];

        if ($confirmed) {
            $rules[] = 'confirmed';
        }

        return $rules;
    }

    /**
     * Messages de validation associés.
     *
     * @return array<string, string>
     */
    public static function validationMessages(): array
    {
        return [
            'password.min' => 'Le mot de passe doit comporter au moins '.self::MIN_LENGTH.' caractères.',
            'password.regex' => 'Le mot de passe doit contenir au moins une majuscule, une minuscule, un chiffre et un caractère spécial.',
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
        ];
    }
}

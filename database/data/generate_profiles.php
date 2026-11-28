<?php

// Script de génération des 100 profils camerounais et 150 posts pour Bango

$femaleFirstNames = [
    'Audrey', 'Brenda', 'Marcelle', 'Diane', 'Fabiola', 'Carine', 'Suzanne', 'Vanessa',
    'Kelly', 'Sandra', 'Christelle', 'Laetitia', 'Ornella', 'Raïssa', 'Estelle', 'Nadine',
    'Pascale', 'Sandrine', 'Gwladys', 'Ingrid', 'Gaëlle', 'Priscille', 'Syntyche', 'Valérie',
    'Danielle', 'Muriel', 'Larissa', 'Mireille', 'Josiane', 'Clarisse', 'Tatiana', 'Edith',
    'Béatrice', 'Hortense', 'Sonia', 'Chantal', 'Rosine', 'Jeanne', 'Armelle', 'Viviane',
    'Aline', 'Lydie', 'Gisèle', 'Fadimatou', 'Aïssatou', 'Aminatou', 'Maimouna', 'Hadidja',
    'Enow', 'Ngum',
];

$maleFirstNames = [
    'Yannick', 'Cedric', 'Boris', 'Steve', 'Ulrich', 'Junior', 'Christian', 'Paul',
    'Eric', 'Franck', 'Serge', 'Lionel', 'Alain', 'Thierry', 'Brice', 'Hervé',
    'Arnaud', 'Fabrice', 'Stéphane', 'Patrick', 'Guy', 'Donald', 'Dimitri', 'Gilles',
    'Rodolphe', 'Cyrille', 'Roméo', 'Wilfried', 'Bertrand', 'Yves', 'Didier', 'Gervais',
    'Kevin', 'Landry', 'Joel', 'Maxime', 'Arthur', 'Armel', 'Prosper', 'Ghislain',
    'Oumarou', 'Bello', 'Bouba', 'Mohamadou', 'Abba', 'Taku', 'Ashu', 'Tambe', 'Nfor', 'Chi',
];

$lastNames = [
    // Sawa / Littoral
    'Eboa', 'Moukoko', 'Dikalo', 'Bell', 'Mbappe', 'Manga', 'Eyoum', 'Priso', 'Soppo', 'Ekotto', 'Dina', 'Lobe', 'Kotto', 'Kouoh',
    // Beti / Centre & Sud
    'Atangana', 'Mbarga', 'Ndongo', 'Eyenga', 'Mballa', 'Ngono', 'Owona', 'Essomba', 'Onana', 'Belibi', 'Zambo', 'Biyong', 'Mengue', 'Bella', 'Abega', 'Biloa',
    // Bamiléké & Grassfields
    'Fotso', 'Kamga', 'Tagne', 'Pokam', 'Nguemo', 'Simo', 'Fotsing', 'Tchinda', 'Kuete', 'Kengne', 'Chendjou', 'Wambo', 'Defo', 'Talla', 'Fokam', 'Djeumeni',
    // Anglophone (Nord-Ouest / Sud-Ouest)
    'Nfor', 'Enow', 'Ashu', 'Tambe', 'Taku', 'Ndive', 'Agbor', 'Besong', 'Tanyi', 'Fon', 'Chi', 'Ewane', 'Njoh',
    // Grand Nord
    'Oumarou', 'Bello', 'Aminatou', 'Fadimatou', 'Bouba', 'Abba', 'Mohamadou', 'Alhadji', 'Aissatou', 'Sadjo',
];

$doualaHoods = ['Akwa', 'Bonanjo', 'Bonapriso', 'Makepe', 'Kotto', 'Deido', 'Logpom', 'Bonamoussadi', 'Bali', 'Bepanda', 'Denver', 'Ndogbong'];
$yaoundeHoods = ['Bastos', 'Biyem-Assi', 'Ngoa-Ekélé', 'Santa Barbara', 'Omnisports', 'Mendong', 'Essos', 'Odza', 'Tsinga', 'Mimboman', 'Emana', 'Koweït'];

$femaleAvatars = [
    'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1531746020798-e6953c6e8e04?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1589156280159-27698a70f29e?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1573497019940-1c28c88b4f3e?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1508214751196-bcfd4ca60f91?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1548142813-c348350df52b?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1573496799652-408c2ac9fe98?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1531123897727-8f129e1688ce?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1580489944761-15a19d654956?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1567532939604-b6b5b0db2604?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1607746882042-944635dfe10e?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1595152772835-219674b2a8a6?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1614283233556-f35b0c801ef1?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1615813967515-e1838c1c5116?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1622253692010-333f2da6031d?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1524504388940-b1c1722653e1?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1517841905240-472988babdf9?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1529626455594-4ff0802cfb7e?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1544005313-94ddf0286df2?w=500&auto=format&fit=crop&q=80',
];

$maleAvatars = [
    'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1522529599102-193c0d76b5b6?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1506794778202-cad84cf45f1d?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1531384441138-2736e62e0919?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1541823709867-1b206113eafd?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1534751516642-a171edd26503?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1566492031773-4f4e44671857?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1568602471122-7832951cc4c5?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1584999734482-05c30ac0b001?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1615109398623-88346a601842?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1618077360395-f3068be8e001?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1607990281513-2c110aef5ba8?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1539571696357-5a69c17a67c6?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1501196354995-cbb51c65aaea?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1560250097-0b93528c311a?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1519085360753-af0119f7cbe7?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1528892952291-009c663ce843?w=500&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1513956589380-bad6acb9b9d4?w=500&auto=format&fit=crop&q=80',
];

$bios = [
    'Étudiant à Ngoa-Ekélé 🎓 Passionné de tech, d\'IA et de football ⚽',
    'Douala girl in love with fashion & food 🥘✨ Bienvenue sur mon Bango !',
    'Entrepreneur & créateur de contenu sur Yaoundé 🇨🇲 On avance toujours.',
    'Amoureux inconditionnel du poisson braisé et des couchers de soleil à Kribi 🌊',
    'Architecte d\'intérieur & passionnée de déco afro-moderne 🛋️🌿',
    'Passionné de streetwear africain et de culture urbaine camerounaise 🇨🇲🔥',
    'Pharmacienne en formation, amoureuse des livres et de musique live 🎷',
    'Développeur web à Akwa. Le code, le café et le mboa 💻☕',
    'Créatrice de mode & accessoires pagne. Rendez-vous au sommet ✨👗',
    'Footballeur du dimanche matin et analyste de comptoir le soir ⚽😂',
    'Toujours à la recherche des meilleurs restaurants et snacks du 237 🍴',
    'Vidéaste & photographe urbain à Douala. Capturer l\'âme de ma ville 📸',
    'Enseignante passionnée, engagée pour l\'éducation des jeunes filles 📚',
    'Gestionnaire de projets & amatrice de randonnée au Mont Cameroun ⛰️',
    'Artiste chanteur indépendant. Vivre de sa passion ou rien 🎤🎵',
    'Avocat stagiaire, défenseur des droits et grand amateur de jazz ⚖️',
    'Graphiste freelance & illustrateur. Je transforme vos idées en visuels 🎨',
    'Fitness enthusiast & coach bien-être. Discipline et régularité 💪🏾',
    'Commerçante à Bonamoussadi. Qualité, sourire et livraison partout 🛍️',
    'Journaliste en herbe, curieux de tout ce qui bouge au Cameroun 🎙️',
];

// Génération des 100 profils
$profiles = [];
$usedHandles = [];

for ($i = 0; $i < 100; $i++) {
    $isFemale = ($i % 2 === 0);
    $firstName = $isFemale ? $femaleFirstNames[($i / 2) % count($femaleFirstNames)] : $maleFirstNames[(int) ($i / 2) % count($maleFirstNames)];
    $lastName = $lastNames[$i % count($lastNames)];
    $fullName = "{$firstName} {$lastName}";

    $baseHandle = strtolower(preg_replace('/[^a-z0-9]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', "{$firstName}_{$lastName}")));
    $handle = $baseHandle;
    $suffix = 1;
    while (isset($usedHandles[$handle])) {
        $handle = "{$baseHandle}_{$suffix}";
        $suffix++;
    }
    $usedHandles[$handle] = true;

    $isDouala = ($i % 5 !== 0 && $i % 5 !== 3); // ~60% Douala, 40% Yaoundé
    $city = $isDouala ? 'Douala' : 'Yaoundé';
    $citySlug = $isDouala ? 'douala' : 'yaounde';
    $hood = $isDouala ? $doualaHoods[$i % count($doualaHoods)] : $yaoundeHoods[$i % count($yaoundeHoods)];

    $avatarList = $isFemale ? $femaleAvatars : $maleAvatars;
    $avatar = $avatarList[$i % count($avatarList)];
    $bio = $bios[$i % count($bios)];

    $profiles[] = [
        'index' => $i + 1,
        'email' => "user{$i}_bango@bangoapp.com",
        'name' => $fullName,
        'display_name' => $fullName,
        'handle' => $handle,
        'gender' => $isFemale ? 'F' : 'M',
        'city' => $city,
        'city_slug' => $citySlug,
        'neighbourhood' => $hood,
        'bio' => $bio,
        'avatar_url' => $avatar,
        'phone' => '+23769'.str_pad($i + 1000, 7, '0', STR_PAD_LEFT),
        'is_verified' => ($i % 4 === 0),
        'badge' => ($i % 8 === 0) ? 'creator' : 'none',
    ];
}

file_put_contents(__DIR__.'/bango_community_profiles.json', json_encode($profiles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo 'Generated '.count($profiles)." community profiles.\n";

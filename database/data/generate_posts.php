<?php

// Script pour compiler 150 posts réalistes pour Bango Square

$postsData = [
    // 1-15 : Débats culinaires & Gastronomie
    [
        'body' => "Question très sérieuse pour commencer le week-end : Le meilleur beignet-haricot-bouillie du Cameroun se trouve à Douala ou à Yaoundé ? Citez vos meilleurs spots en commentaires, on doit régler ce débat aujourd'hui ! 👇🏾🇨🇲",
        'photo' => 'https://images.unsplash.com/photo-1567620905732-2d1ec7ab7445?w=1080&auto=format&fit=crop&q=80',
        'place' => 'Douala, Cameroun',
    ],
    [
        'body' => "Rien n'égale l'odeur du poisson braisé bien pimenté au carrefour Deido un vendredi soir après une longue semaine de boulot. Accompagné de bâton de manioc ou de plantain mûr ? Vous êtes dans quelle team ? 🔥🐟",
        'photo' => 'https://images.unsplash.com/photo-1555939594-58d7cb561ad1?w=1080&auto=format&fit=crop&q=80',
        'place' => 'Deido, Douala',
    ],
    [
        'body' => "Le Ndolè avec le plantain mûr frit (dodo) c'est une combinaison divine. Mais ceux qui mangent le Ndolè avec le riz là... expliquez-nous votre projet s'il vous plaît 😂🤦🏾‍♂️",
        'photo' => 'https://images.unsplash.com/photo-1540420773420-3366772f4999?w=1080&auto=format&fit=crop&q=80',
        'place' => 'Bonapriso, Douala',
    ],
    [
        'body' => "Le Taro sauce jaune du dimanche à Bafoussam ou à l'Ouest en général... Le goût de ça ne trompe jamais. Qui en a mangé aujourd'hui ? 🍲✨",
        'photo' => null,
        'place' => 'Bafoussam, Cameroun',
    ],
    [
        'body' => "Ceux qui n'ont jamais goûté le véritable Eru préparé à Buea ou Limbe avec le bon water fufu et la peau de bœuf bien tendre... vous ratez une dimension du bonheur dans ce pays ! 🇨🇲🌿",
        'photo' => null,
        'place' => 'Buea, Cameroun',
    ],
    [
        'body' => "Un bon Koki bien chaud avec la banane vapeur le samedi matin. Simple, traditionnel, efficace. Vous préférez avec beaucoup d'huile de palme ou modéré ?",
        'photo' => null,
        'place' => 'Yaoundé, Cameroun',
    ],
    [
        'body' => "Le Mbongo Tchobi préparé avec du machoiran frais... La sauce noire la plus savoureuse d'Afrique centrale. Nos grands-mères du littoral ont du génie culinaire ! 🙏🏾🖤",
        'photo' => null,
        'place' => 'Douala, Cameroun',
    ],
    [
        'body' => "Arrêtons l'hypocrisie : le sandwich spaghetti-omelette-mayo vendu au carrefour la nuit par les 'mallams' a sauvé plus de vies d'étudiants que beaucoup de remèdes médicaux. Respectons ces chefs de l'ombre ! 🥖🍳😂",
        'photo' => null,
        'place' => 'Ngoa-Ekélé, Yaoundé',
    ],
    [
        'body' => 'Votre boisson locale préférée pour accompagner un bon repas : Foléré bien glacé, Jus de baobab ou Matango bien frais du matin ?',
        'photo' => null,
        'place' => 'Yaoundé, Cameroun',
    ],
    [
        'body' => "Le Kondre de porc ou de chèvre bien mijoté avec les plantains entiers... Quand tu finis de manger ça à 14h, ta seule envie jusqu'à 18h c'est de dormir sous le ventilateur ! 😴🥘",
        'photo' => null,
        'place' => 'Bafoussam, Cameroun',
    ],

    // 11-25 : Histoires de transport & Vie de rue
    [
        'body' => "Aujourd’hui dans le taxi à Akwa, le chauffeur a pris 4 personnes à l’arrière, et quand la police a sifflé au carrefour, il a dit au client du milieu : 'Mon frère baisse-toi, fais comme si tu cherchais ta chaussure'. On vit dans une simulation au 237 😂😭",
        'photo' => null,
        'place' => 'Akwa, Douala',
    ],
    [
        'body' => 'Les chauffeurs de bend-skin (motos) de Douala ont leur propre code de la route que même les ingénieurs de la NASA ne peuvent pas décoder. Tu es assis derrière lui, tu récites le psaume 23 et les prières de tes ancêtres en même temps 🛵💨🙏🏾',
        'photo' => null,
        'place' => 'Rond-Point Ndokoti, Douala',
    ],
    [
        'body' => "Être coincé dans l'embouteillage de Ndokoti sous une pluie torrentielle à 18h30 avec un taximan qui écoute les vieux succès de Petit Pays en boucle. C'est ça la vraie expérience Douala ! 🌧️🚗🎶",
        'photo' => 'https://images.unsplash.com/photo-1509099836639-18ba1795216d?w=1080&auto=format&fit=crop&q=80',
        'place' => 'Ndokoti, Douala',
    ],
    [
        'body' => 'À Yaoundé, la montée de Bastos le matin ressemble parfois à une épreuve de formule 1. Tout le monde est pressé, mais personne ne sait exactement où il va !',
        'photo' => null,
        'place' => 'Bastos, Yaoundé',
    ],
    [
        'body' => 'Ce moment gênant dans le taxi où le chauffeur et le passager de devant commencent un débat politique houleux, et les 4 personnes derrière sont prises en otage sans pouvoir descendre 🤐🚕',
        'photo' => null,
        'place' => 'Poste Centrale, Yaoundé',
    ],
    [
        'body' => "Douala sous le soleil de midi : l'asphalte fume, le taximan refuse d'allumer la clim 'pour économiser le carburant', et la dame à côté de toi a un sac de bâtons de manioc encore chauds. Bienvenue dans le sauna naturel le plus authentique du monde ! ☀️🌡️",
        'photo' => null,
        'place' => 'Bonanjo, Douala',
    ],
    [
        'body' => "Petite marche matinale sur les berges du Wouri. L'air marin de Douala au lever du soleil a quelque chose d'apaisant. Bonne journée à tous les travailleurs qui se lèvent tôt pour bâtir ce pays ! 🌅🇨🇲",
        'photo' => 'https://images.unsplash.com/photo-1577717903315-1691ae25ab3f?w=1080&auto=format&fit=crop&q=80',
        'place' => 'Pont sur le Wouri, Douala',
    ],
    [
        'body' => "Quand tu donnes un billet de 10 000 FCFA à un taximan à 6h30 du matin pour une course de 300 FCFA... Le regard qu'il te lance peut éteindre une ampoule ! 😒💵",
        'photo' => null,
        'place' => 'Mokolo, Yaoundé',
    ],
    [
        'body' => "Le carrefour Deido un samedi soir, c'est un festival à ciel ouvert. La musique sort de chaque bar, les odeurs de grillades partout, les gens qui rient. L'énergie de cette ville est inarrêtable ! 🥳✨",
        'photo' => 'https://images.unsplash.com/photo-1517457373958-b7bdd4587205?w=1080&auto=format&fit=crop&q=80',
        'place' => 'Deido, Douala',
    ],
    [
        'body' => "Les nids-de-poule pendant la saison des pluies... tu crois que c'est une petite flaque d'eau, tu mets le pied, ta jambe disparaît jusqu'au genou ! Faites attention où vous marchez en ce moment les amis 😅🌧️",
        'photo' => null,
        'place' => 'Biyem-Assi, Yaoundé',
    ],

    // 21-35 : Humour & Vie Quotidienne au Mboa
    [
        'body' => "Le fameux 'Je suis déjà en route' d’un Camerounais signifie en réalité qu'il vient de poser son téléphone pour chercher où il a mis la serviette de bain. Pourquoi nous sommes comme ça ? Soyons sérieux deux minutes ! 🤦🏾‍♂️🚿😂",
        'photo' => null,
        'place' => 'Douala, Cameroun',
    ],
    [
        'body' => "Dans une famille camerounaise, quand un oncle commence sa phrase par : 'À votre âge en 1985...', préparez-vous à écouter une histoire où il marchait 42 km pieds nus dans la forêt pour aller à l'école sous une pluie de grêle ! 👴🏾📖🤣",
        'photo' => null,
        'place' => 'Yaoundé, Cameroun',
    ],
    [
        'body' => "Le silence lourd qui tombe dans un salon de coiffure quand la coiffeuse tire tes cheveux avec le peigne et dit calmement : 'Tes cheveux sont un peu durs ma chérie'... Courage à nos sœurs ! 💇🏾‍♀️😭",
        'photo' => null,
        'place' => 'Makepe, Douala',
    ],
    [
        'body' => "Tu prêtes l'argent à quelqu'un au mboa, et quand vient le jour de réclamer, c'est toi qui as honte et qui demandes pardon d'avoir besoin de ton propre argent. La sorcellerie relationnelle à son apogée ! 💸😤",
        'photo' => null,
        'place' => 'Yaoundé, Cameroun',
    ],
    [
        'body' => "Les mamans camerounaises quand tu cherches un objet dans la maison : 'Si je viens là-bas et que je trouve ça, tu vas voir !'. Et le pire c'est qu'elle vient et l'objet apparaît magiquement devant elle ! Sorcellerie maternelle certifiée 😂👩🏾‍🦱",
        'photo' => null,
        'place' => 'Douala, Cameroun',
    ],
    [
        'body' => "Les mariages traditionnels chez nous : la dot est prévue pour 10h, la famille du marié arrive à 15h, la cérémonie commence à 18h, et le repas est servi à minuit passé. Mais l'ambiance et la joie sont incomparables ! 💍🎉💃🏾",
        'photo' => null,
        'place' => 'Bafoussam, Cameroun',
    ],
    [
        'body' => "Quand tu visites quelqu'un à l'improviste et qu'on te dit : 'Assieds-toi, on va juste te réchauffer un peu de riz de midi'... C'est ça l'hospitalité camerounaise, personne ne repart le ventre vide ! ❤️🥘",
        'photo' => null,
        'place' => 'Yaoundé, Cameroun',
    ],
    [
        'body' => "Tu achètes un truc au marché Mokolo, tu marchandes pendant 30 minutes de 5000 à 2500 FCFA. Tu rentres fier chez toi, et ta petite sœur te dit : 'Ah le gars là t'a gâté, ça coûte 1500 normalement'. Douleur aiguë dans la poitrine ! 💔🛍️",
        'photo' => 'https://images.unsplash.com/photo-1580674285054-bed31e145f59?w=1080&auto=format&fit=crop&q=80',
        'place' => 'Marché Mokolo, Yaoundé',
    ],
    [
        'body' => "Le Camerounais est capable de saluer quelqu'un en disant : 'Le grand ! Tu as maigri hein, il y a problème ?' ou bien 'Tu as pris du ventre, la vie te réussit !'. Aucun filtre, zéro protocole, toujours dans la vérité crue ! 😆",
        'photo' => null,
        'place' => 'Douala, Cameroun',
    ],
    [
        'body' => "L'art de découper un poulet en 36 morceaux lors d'une fête de famille pour que chaque cousin, tante et enfant du voisin ait sa part. Nos mamans méritent un prix Nobel de géométrie appliquée ! 🍗📐😂",
        'photo' => null,
        'place' => 'Yaoundé, Cameroun',
    ],

    // 31-45 : Étudiants & Universités
    [
        'body' => "Les amphis de 1000 places à Ngoa-Ekélé sous le soleil de 13h... Il faut un diplôme spécial d'endurance physique rien que pour supporter l'atmosphère ! Courage à tous les étudiants de Yaoundé 1 qui se battent pour leur avenir 🎓💪🏾",
        'photo' => null,
        'place' => 'Ngoa-Ekélé, Yaoundé',
    ],
    [
        'body' => "La fameuse photocopie de 25 pages vendue à 250 FCFA devant le campus avec l'encre qui s'efface sur tes doigts pendant que tu révises l'examen du lendemain. La belle époque étudiante ! 📑😅",
        'photo' => null,
        'place' => 'Université de Douala, PK17',
    ],
    [
        'body' => "Le calme légendaire et le climat frais de Buea autour de l'Université (UB). Rien de mieux pour étudier sereinement avec la vue sur le Mont Cameroun. Qui a fait ses études à Buea ici ? ⛰️🎓",
        'photo' => null,
        'place' => 'Buea, Cameroun',
    ],
    [
        'body' => "Fin de mois d'un étudiant à Yaoundé : il reste 1 kilo de riz, 2 cubes Maggi, un demi-litre d'huile et beaucoup d'espoir et de prières. Mais on garde toujours le sourire et la dignité ! ✊🏾🙏🏾",
        'photo' => null,
        'place' => 'Biyem-Assi, Yaoundé',
    ],
    [
        'body' => "Ce prof à l'université qui te dit : '18 c'est pour Dieu, 16 c'est pour moi le professeur, 14 c'est pour les génies, vous autres vous vous battez entre 8 et 11'. Pourquoi tant de méchanceté gratuite monsieur ? 😭📚",
        'photo' => null,
        'place' => 'Ngoa-Ekélé, Yaoundé',
    ],

    // 41-55 : Mode, Beauté & Culture Africaine
    [
        'body' => "Le tissu Toghu des Grassfields du Nord-Ouest est l'une des plus belles étoffes traditionnelles de tout le continent africain. Les broderies, les motifs, le poids du vêtement... C'est de l'art pur ! 🇨🇲👑✨",
        'photo' => null,
        'place' => 'Bamenda, Cameroun',
    ],
    [
        'body' => "Les femmes du littoral dans leur Kaba Ngondo majestueux lors d'une cérémonie officielle... L'élégance, la prestance et la grâce à l'état pur. Respectons nos traditions ! 👗🌊",
        'photo' => null,
        'place' => 'Bonanjo, Douala',
    ],
    [
        'body' => 'Team tresses africaines (nappy/braids) ou team perruque pour le week-end ? Les deux sont magnifiques, mais le naturel a un charme qui ne ment jamais ✨❤️',
        'photo' => null,
        'place' => 'Bonamoussadi, Douala',
    ],
    [
        'body' => "Petite séance photo improvisée dans les rues de Bonapriso. La lumière dorée de 17h au Cameroun donne des tons chauds incomparables. Qu'en pensez-vous ? 📸✨",
        'photo' => 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=1080&auto=format&fit=crop&q=80',
        'place' => 'Bonapriso, Douala',
    ],
    [
        'body' => "Consommons camerounais ! Nos jeunes créateurs de mode font des merveilles avec les tissus locaux et le cuir. N'attendons pas que l'extérieur valide nos talents pour les soutenir 🛍️🇨🇲",
        'photo' => null,
        'place' => 'Akwa, Douala',
    ],

    // 51-65 : Football & Sport
    [
        'body' => "Le 237 et le football, c'est une histoire d'amour passionnelle et toxique à la fois ! Quand les Lions Indomptables gagnent, le pays entier est en fête. Quand ils perdent, même la nourriture perd son goût ! 🦁⚽🇨🇲",
        'photo' => null,
        'place' => 'Omnisports, Yaoundé',
    ],
    [
        'body' => "Règles du foot au quartier au Cameroun : 1. Le propriétaire du ballon choisit son camp. 2. Pas d'arbitre, le joueur qui crie le plus fort a la faute. 3. Le match s'arrête quand la nuit tombe ou que la maman du propriétaire du ballon l'appelle ! 😂⚽",
        'photo' => null,
        'place' => 'Bepanda, Douala',
    ],
    [
        'body' => "Samuel Eto'o, Roger Milla, Thomas Nkono, Rigobert Song... Si vous deviez choisir LA légende ultime qui incarne le plus l'esprit guerrier du Cameroun, vous votez pour qui ? 🇨🇲🏆",
        'photo' => null,
        'place' => 'Douala, Cameroun',
    ],
    [
        'body' => "Séance de footing ce matin sur le parcours Vita de Douala. Beaucoup de courage à tous ceux qui ont décidé de reprendre leur santé en main cette année ! La santé n'a pas de prix 🏃🏾‍♂️💪🏾",
        'photo' => null,
        'place' => 'Parcours Vita, Douala',
    ],
    [
        'body' => "L'ambiance dans un snack-bar quand le Cameroun joue en Coupe d'Afrique des Nations... Tout le monde devient sélectionneur national, les analyses tactiques fusent de partout entre deux bières fraîches ! 🍻⚽",
        'photo' => null,
        'place' => 'Essos, Yaoundé',
    ],

    // 61-75 : Nature & Tourisme Cameroun
    [
        'body' => "Les chutes de la Lobé à Kribi qui se jettent directement dans l'océan Atlantique. L'un des rares spectacles naturels de ce genre sur la planète. Qui a prévu un week-end à Kribi bientôt ? 🌊🏖️",
        'photo' => 'https://images.unsplash.com/photo-1469474968028-56623f02e42e?w=1080&auto=format&fit=crop&q=80',
        'place' => 'Chutes de la Lobé, Kribi',
    ],
    [
        'body' => "L'ascension du Mont Cameroun (Char des Dieux) à plus de 4000 mètres d'altitude. Une aventure physique et spirituelle intense. Qui parmi vous a déjà atteint le sommet ? ⛰️🧗🏾‍♂️",
        'photo' => null,
        'place' => 'Buea, Mont Cameroun',
    ],
    [
        'body' => "Le parc national de Waza dans l'Extrême-Nord : la beauté du Sahel camerounais, les girafes, les éléphants... Le Cameroun est véritablement toute l'Afrique dans un seul pays ! 🦒🐘🇨🇲",
        'photo' => null,
        'place' => 'Waza, Extrême-Nord',
    ],
    [
        'body' => "Les plages de sable noir de Limbe avec le poisson frais braisé au bord de l'eau en écoutant les vagues. La définition même du repos de l'esprit ! 🐟🌊",
        'photo' => null,
        'place' => 'Down Beach, Limbe',
    ],
    [
        'body' => "La falaise de Dschang et ses virages vertigineux avec une vue panoramique sur les plaines de l'Ouest. Notre pays regorge de paysages à couper le souffle !",
        'photo' => null,
        'place' => 'Dschang, Cameroun',
    ],

    // 71-85 : Musique & Ambiance
    [
        'body' => 'Le Makossa des années 80 (Eboa Lotin, Ekambi Brillant, Toto Guillaume, Sam Fan Thomas, Moni Bilé)... Cette musique avait une âme, une basse puissante et des paroles poétiques. Qui écoute encore ces classiques le dimanche ? 🎷🎶',
        'photo' => null,
        'place' => 'Bali, Douala',
    ],
    [
        'body' => 'Le Bikutsi moderne avec ses rythmes endiablés qui te font bouger même si tu as mal aux jambes. Dès que la guitare commence à pleurer, tout le monde est sur la piste ! 🎸🔥💃🏾',
        'photo' => null,
        'place' => 'Yaoundé, Cameroun',
    ],
    [
        'body' => "La scène urbaine camerounaise actuelle a un talent fou : Stanley Enow, Locko, Tenor, Blanche Bailly, Ko-c, Kameni, Cysoul... Donnons-leur la force qu'ils méritent sur Bango ! 🎧🇨🇲",
        'photo' => 'https://images.unsplash.com/photo-1492684223066-81342ee5ff30?w=1080&auto=format&fit=crop&q=80',
        'place' => 'Douala, Cameroun',
    ],
    [
        'body' => "Quelle est selon vous LA meilleure chanson d'amour jamais enregistrée par un artiste camerounais ? Citez le titre et l'artiste en commentaire ! ❤️🎵",
        'photo' => null,
        'place' => 'Yaoundé, Cameroun',
    ],
    [
        'body' => "Un bon concert live dans un cabaret de Bastos ou de Bonapriso avec un groupe qui reprend les classiques africains... Il n'y a pas meilleure façon de terminer un samedi soir 🎺🎤",
        'photo' => null,
        'place' => 'Bastos, Yaoundé',
    ],

    // 81-100 : Business, Tech & Pensées d'avenir
    [
        'body' => "Bango est officiellement lancé ! C'est une fierté de voir une plateforme développée pour célébrer notre culture, notre créativité et nos voix sans intermédiaire. Faisons grandir cette communauté ensemble ! 🚀📱🇨🇲",
        'photo' => null,
        'place' => 'Douala, Cameroun',
    ],
    [
        'body' => "Gros respect à tous les jeunes entrepreneurs camerounais qui se battent chaque jour malgré les coupures d'électricité, les tracas administratifs et les défis du quotidien. Vous êtes la fierté de cette nation ! 💡💼✊🏾",
        'photo' => null,
        'place' => 'Akwa, Douala',
    ],
    [
        'body' => "Si tu devais donner un seul conseil à un jeune diplômé camerounais qui cherche du travail aujourd'hui, ce serait quoi ?",
        'photo' => null,
        'place' => 'Yaoundé, Cameroun',
    ],
    [
        'body' => "La Silicon Mountain à Buea prouve chaque jour que le génie technologique africain n'a rien à envier au reste du monde. Des solutions locales pour des réalités africaines ! 💻🌍",
        'photo' => null,
        'place' => 'Buea, Cameroun',
    ],
    [
        'body' => "Règle d'or en business au pays : ne mélange jamais l'argent de ta marchandise avec l'argent du pain familial ! La rigueur financière est la clé de la longévité d'un commerce 📊💵",
        'photo' => null,
        'place' => 'Bonamoussadi, Douala',
    ],
    [
        'body' => "Un nouveau jour qui commence est une nouvelle opportunité de faire mieux qu'hier. Que vos efforts d'aujourd'hui portent de grands fruits. Force et courage à tous ! 🌅✨",
        'photo' => null,
        'place' => 'Douala, Cameroun',
    ],
    [
        'body' => "Prenez le temps d'appeler vos parents ou ceux qui ont veillé sur vous au village. Un simple coup de fil de 3 minutes peut illuminer toute leur semaine ❤️📞",
        'photo' => null,
        'place' => 'Yaoundé, Cameroun',
    ],
    [
        'body' => 'La vraie richesse ne se mesure pas au nombre de bouteilles ouvertes en boîte de nuit, mais à la paix de ton esprit et à ta capacité à aider ceux qui ont moins que toi 🙏🏾✨',
        'photo' => null,
        'place' => 'Douala, Cameroun',
    ],
    [
        'body' => "Le sourire d'un enfant camerounais qui court sous la première pluie après des semaines de grande chaleur... La joie se trouve souvent dans les choses les plus simples de la vie 🌧️😊",
        'photo' => null,
        'place' => 'Kribi, Cameroun',
    ],
    [
        'body' => "Un bon dimanche en famille autour d'un grand plat commun. Que la paix et l'harmonie règnent dans tous vos foyers ! Bon repos à chacun d'entre vous 🥘🙏🏾",
        'photo' => null,
        'place' => 'Yaoundé, Cameroun',
    ],
];

// Compléter automatiquement jusqu'à 150 avec des variantes et micro-anecdotes locales
$topics = [
    'food' => [
        "J'ai testé un nouveau coin de beignets au carrefour Ange Raphaël ce matin... croustillants à l'extérieur, moelleux à l'intérieur ! Qui veut l'adresse ?",
        "Ceux qui mettent le sucre dans le haricot rouge des beignets, expliquez votre démarche s'il vous plaît ! C'est un crime culinaire ou une innovation ? 😂",
        'Le poisson bar braisé avec la marinade secrète de maman... rien à ajouter, 10/10 sans débat !',
        "Le bouillon de pattes de bœuf un dimanche matin à 8h pour dissiper les effets de la fête d'hier soir... remède certifié par la médecine traditionnelle urbaine !",
        'Plat de riz sénégalais ou bon Jollof rice façon camerounaise ? Nos mamans savent donner une saveur unique à chaque grain !',
        'La sauce gombo gluante bien assaisonnée avec du crabe et du poisson fumé. Qui valide ?',
        "Les prunes (safous) grillées sur la braise avec le maïs frais en saison des pluies... le goûter officiel de toute l'Afrique centrale !",
        'Les beignets de maïs (Koki maïs) bien sucrés emballés dans les feuilles de bananier. Un délice ancestral !',
        'Une bonne assiette de spaghettis sautés au saucisson et légumes au carrefour à minuit, servi dans du papier journal propre... le goût de ça dépasse les 5 étoiles !',
        "Le soya bien pimenté emballé dans du papier avec les oignons crus... L'odeur sur la braise attire à 200 mètres !",
    ],
    'life' => [
        "Pourquoi quand tu dis à un chauffeur de moto à Douala d'aller doucement, c'est là qu'il accélère en disant 'Mami tu as peur de quoi ?' 😂😭",
        "Le Camerounais qui arrive au guichet de banque avec 12 pièces de justificatifs, et le caissier lui demande : 'Où est l'acte de naissance de votre grand-oncle ?' 🤦🏾‍♂️",
        'Tu es posé tranquillement chez toi le samedi matin, et la voisine commence à écouter le gospel à un volume qui fait vibrer les fenêtres de tout le quartier 📢⛪',
        "La rapidité avec laquelle les informations circulent dans un quartier de Yaoundé dépasse la vitesse de la fibre optique de Camtel ! Le téléphone arabe au mboa c'est un métier !",
        'Quand tu voyages par car VIP de Douala à Yaoundé et que le pasteur monte à bord pour 45 minutes de prière avant le départ... Tu sais que le voyage est béni ! 🚌🙏🏾',
        "Le moment où le courant part au quartier pile au moment où l'arbitre siffle un penalty pour le Cameroun... Le cri de désespoir collectif qui monte dans la nuit ! 💡😱",
        "Tu dis 'Je ne bois pas ce soir', tu arrives au snack à 21h, et à 2h du matin c'est toi qui payes la tournée générale en chantant du Fally Ipupa à tue-tête 🍻🎤",
        'Les coiffeurs hommes du quartier qui sont en même temps psychologues, commentateurs sportifs et conseillers matrimoniaux certifiés 💈✂️',
        "Marchander le taxi de 250 à 200 FCFA avec une telle intensité dramatique qu'on croirait qu'il s'agit des négociations de l'accord de paix de l'ONU ! 🚕",
        "Les enfants du quartier qui jouent au foot sous la pluie battante sans chaussures avec un ballon en plastique déchiré... La vraie insouciance de l'enfance !",
    ],
    'inspire' => [
        'Ne laisse personne te faire croire que tes rêves sont trop grands pour un jeune qui grandit ici. Le talent africain est universel 🌟✊🏾',
        "Le succès ne vient pas du jour au lendemain, c'est l'accumulation de petites victoires invisibles au quotidien. Garde la foi et avance !",
        "Entourez-vous de personnes qui parlent de projets, d'opportunités et de solutions plutôt que de ceux qui ne voient que les blocages 💡",
        "Être gentil ne coûte rien, mais cela peut changer toute la journée d'une personne que vous croisez sur votre chemin ✨",
        "La jeunesse camerounaise a une résilience extraordinaire. Malgré les vents contraires, on trouve toujours le moyen de créer et d'avancer 🇨🇲💪🏾",
        "Prenez soin de votre paix intérieure. Tout ce qui vous coûte votre tranquillité d'esprit est trop cher 🧘🏾‍♂️🌿",
        "L'éducation et la curiosité sont les plus grands passeports pour la liberté. Lisez, apprenez, formez-vous chaque jour 📚",
        'Célébrez vos petites victoires, même celles que les autres ne remarquent pas. Chaque pas compte !',
        'Partagez vos bénédictions. Une main qui donne est une main qui reçoit toujours en abondance 🙏🏾❤️',
        "Bango c'est notre espace d'expression. Exprimez vos idées, racontez vos histoires, montrez vos talents !",
    ],
];

$allPosts = $postsData;
$currentIndex = count($allPosts);

while (count($allPosts) < 150) {
    foreach ($topics as $category => $list) {
        if (count($allPosts) >= 150) {
            break;
        }
        $text = $list[count($allPosts) % count($list)];
        $isDouala = (count($allPosts) % 2 === 0);
        $place = $isDouala ? 'Douala, Cameroun' : 'Yaoundé, Cameroun';

        $allPosts[] = [
            'body' => $text,
            'photo' => null,
            'place' => $place,
        ];
    }
}

// Ajout des métadonnées pour chaque post
$finalPosts = [];
foreach ($allPosts as $idx => $p) {
    $postNum = $idx + 1;
    $finalPosts[] = [
        'id' => 'bango_seed_post_'.str_pad($postNum, 3, '0', STR_PAD_LEFT),
        'number' => $postNum,
        'body' => $p['body'],
        'photo_url' => $p['photo'] ?? null,
        'place_label' => $p['place'],
        'language' => 'fr',
    ];
}

file_put_contents(__DIR__.'/bango_square_posts.json', json_encode($finalPosts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo 'Generated '.count($finalPosts)." Square posts in bango_square_posts.json.\n";

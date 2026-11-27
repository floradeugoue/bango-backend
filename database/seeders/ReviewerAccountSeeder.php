<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\Media\MediaProcessingStatus;
use App\Enums\Message\ConversationType;
use App\Enums\Message\MessageType;
use App\Enums\Reel\ReelStatus;
use App\Enums\Square\PostAudience;
use App\Enums\Square\PostMediaKind;
use App\Models\Auth\Role;
use App\Models\Geo\City;
use App\Models\Geo\Country;
use App\Models\Geo\Neighbourhood;
use App\Models\Message\Conversation;
use App\Models\Message\ConversationMember;
use App\Models\Message\Message;
use App\Models\Reel\Reel;
use App\Models\Reel\Series;
use App\Models\Square\Post;
use App\Models\Square\PostMedia;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ReviewerAccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $cameroun = Country::where('code', 'CM')->first();
        $douala = City::where('slug', 'douala')->first();
        $bonapriso = Neighbourhood::where('name', 'Bonapriso')->first()
            ?? Neighbourhood::where('city_id', $douala?->id)->first();
        $userRole = Role::where('slug', 'user')->first() ?? Role::first();

        // 1. COMPTE SYSTÈME OFFICIEL : ASSISTANT BANGO
        $assistant = User::getAssistant();

        // 2. COMPTE DE DÉMONSTRATION REVIEWER (APPLE / GOOGLE)
        $reviewer = User::updateOrCreate(
            ['email' => 'reviewer@bangoapp.com'],
            [
                'name' => 'Kouamé N\'Dri',
                'display_name' => 'Kouamé N\'Dri',
                'handle' => 'kouame_bango',
                'password' => Hash::make('BangoReview2026!'),
                'email_verified_at' => Carbon::now(),
                'phone' => '+237690000001',
                'phone_verified_at' => Carbon::now(),
                'birth_date' => '1995-06-15',
                'terms_accepted_at' => Carbon::parse('2026-01-01 10:00:00'),
                'onboarding_completed_at' => Carbon::parse('2026-01-01 10:00:30'),
                'onboarding_progress' => [
                    'terms' => true,
                    'profile' => true,
                    'location' => true,
                    'interests' => true,
                ],
                'country_id' => $cameroun?->id,
                'city_id' => $douala?->id,
                'neighbourhood_id' => $bonapriso?->id,
                'country_code' => 'CM',
                'city' => 'Douala',
                'neighbourhood' => $bonapriso?->name ?? 'Bonapriso',
                'role_id' => $userRole?->id,
                'account_type' => AccountType::Personal,
                'is_verified' => true,
                'badge' => 'none',
                'bio' => 'Passionné d\'innovations, de culture urbaine et de gastronomie africaine. Bienvenue sur mon profil BANGO ! 🇨🇲🇨🇮',
                'avatar_url' => 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=400&q=80',
                'allow_downloads' => true,
            ]
        );

        // 3. COMPTE INTERLOCUTEUR DU REVIEWER : AWA DIOP
        $awa = User::updateOrCreate(
            ['email' => 'awa.diop@bangoapp.com'],
            [
                'name' => 'Awa Diop',
                'display_name' => 'Awa Diop',
                'handle' => 'awa_diop',
                'password' => Hash::make('Bango2026!'),
                'email_verified_at' => Carbon::now(),
                'phone' => '+237690000002',
                'phone_verified_at' => Carbon::now(),
                'birth_date' => '1997-04-12',
                'terms_accepted_at' => Carbon::parse('2026-01-01 10:00:00'),
                'onboarding_completed_at' => Carbon::parse('2026-01-01 10:00:30'),
                'onboarding_progress' => ['terms' => true, 'profile' => true],
                'country_id' => $cameroun?->id,
                'city_id' => $douala?->id,
                'neighbourhood_id' => $bonapriso?->id,
                'role_id' => $userRole?->id,
                'account_type' => AccountType::Personal,
                'is_verified' => true,
                'bio' => 'Créatrice de mode & styliste urbaine à Douala. Passionnée par le wax moderne et l\'art africain ✨',
                'avatar_url' => 'https://images.unsplash.com/photo-1531746020798-e6953c6e8e04?auto=format&fit=crop&w=400&q=80',
            ]
        );

        // 4. ENTITÉ SÉRIE BANGO : « LA VOISINE »
        $series = Series::updateOrCreate(
            ['slug' => 'la-voisine'],
            [
                'title' => 'La Voisine',
                'description' => 'Comédie urbaine à Douala : les péripéties drôles et touchantes entre voisins de palier à Bonapriso.',
                'cover_url' => 'https://images.unsplash.com/photo-1517841905240-472988babdf9?auto=format&fit=crop&w=800&q=80',
                'user_id' => $reviewer->id,
                'is_active' => true,
            ]
        );

        // 5. RÉELS (AVEC ÉPISODES DE SÉRIE ET CONTENU AFRICAIN)
        // Épisode 1 de La Voisine
        Reel::updateOrCreate(
            [
                'user_id' => $reviewer->id,
                'series_id' => $series->id,
                'episode_number' => 1,
            ],
            [
                'id' => (string) Str::uuid(),
                'caption' => "Épisode 1 : Le jour de l'emménagement. Quand la voisine d'en face commence déjà avec ses réclamations de bon matin ! 😂🎬 #Serie_LaVoisine Ep1 #Humour #Douala #Bango",
                'video_url' => 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4',
                'thumbnail_url' => 'https://images.unsplash.com/photo-1531746020798-e6953c6e8e04?auto=format&fit=crop&w=600&q=80',
                'duration' => 35,
                'status' => ReelStatus::Ready,
                'views_count' => 2450,
                'likes_count' => 840,
                'comments_count' => 65,
                'published_at' => Carbon::now()->subDays(3),
                'allow_download' => true,
            ]
        );

        // Épisode 2 de La Voisine
        Reel::updateOrCreate(
            [
                'user_id' => $reviewer->id,
                'series_id' => $series->id,
                'episode_number' => 2,
            ],
            [
                'id' => (string) Str::uuid(),
                'caption' => 'Épisode 2 : La sauce gombo de la paix. Réconciliation ou piège ? 🍲😂 #Serie_LaVoisine Ep2 #HumourAfricain #Douala #Bango',
                'video_url' => 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerEscapes.mp4',
                'thumbnail_url' => 'https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?auto=format&fit=crop&w=600&q=80',
                'duration' => 28,
                'status' => ReelStatus::Ready,
                'views_count' => 1890,
                'likes_count' => 620,
                'comments_count' => 42,
                'published_at' => Carbon::now()->subDays(2),
                'allow_download' => true,
            ]
        );

        // Reel 3 : Danse urbaine à Akwa
        Reel::updateOrCreate(
            [
                'user_id' => $reviewer->id,
                'caption' => "Les pépites de Douala ont du génie ! Démo de danse urbaine et afrobeat en plein cœur d'Akwa 🔥🕺🏿 #Danse #AfroVibes #Akwa #Douala",
            ],
            [
                'id' => (string) Str::uuid(),
                'video_url' => 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerFun.mp4',
                'thumbnail_url' => 'https://images.unsplash.com/photo-1509967419530-da38b4704bc6?auto=format&fit=crop&w=600&q=80',
                'duration' => 42,
                'status' => ReelStatus::Ready,
                'views_count' => 3120,
                'likes_count' => 1100,
                'comments_count' => 98,
                'published_at' => Carbon::now()->subDay(),
                'allow_download' => true,
            ]
        );

        $series->refreshEpisodesCount();

        // 6. PUBLICATIONS DANS LE SQUARE
        // Post 1 : Poisson braisé à Bonapriso
        $postFood = Post::updateOrCreate(
            [
                'user_id' => $reviewer->id,
                'body' => 'Le meilleur poisson braisé de Douala reste incontestablement celui de Bonapriso ! 🔥🐟 Assaisonné avec du piment de chez nous, des bananes plantains frites (aloko) et des bâtons de manioc bien frais. Qui a une meilleure adresse à Akwa ou Makepe ? #Douala #StreetFood #PoissonBraise #Cameroun #Square',
            ],
            [
                'audience' => PostAudience::Public,
                'place_city' => 'Douala',
                'place_neighbourhood' => 'Bonapriso',
                'place_label' => 'Douala, Bonapriso',
                'likes_count' => 142,
                'comments_count' => 23,
                'views_count' => 890,
                'created_at' => Carbon::now()->subDays(2),
            ]
        );

        PostMedia::updateOrCreate(
            [
                'post_id' => $postFood->id,
            ],
            [
                'media_url' => 'https://images.unsplash.com/photo-1555939594-58d7cb561ad1?auto=format&fit=crop&w=800&q=80',
                'media_type' => PostMediaKind::Photo,
                'mime_type' => 'image/jpeg',
                'status' => MediaProcessingStatus::Completed,
                'display_order' => 0,
            ]
        );

        // Post 2 : Tech et Entrepreneuriat africain
        Post::updateOrCreate(
            [
                'user_id' => $reviewer->id,
                'body' => "Fier de voir l'énergie et la créativité de la jeunesse à Douala et Abidjan ! L'innovation ne vient pas d'ailleurs, elle est créée ici pour répondre à nos réalités quotidiennes. Force à tous les entrepreneurs du continent ! 🚀🇨🇲🇨🇮 #TechAfrique #Innovation #Bango #DoualaTech",
            ],
            [
                'audience' => PostAudience::Public,
                'place_city' => 'Douala',
                'place_neighbourhood' => 'Akwa',
                'place_label' => 'Douala, Akwa',
                'likes_count' => 98,
                'comments_count' => 14,
                'views_count' => 650,
                'created_at' => Carbon::now()->subDay(),
            ]
        );

        // 7. CONVERSATION AVEC L'ASSISTANT BANGO (SYNCHRONISÉE)
        $assistantConv = Conversation::firstOrCreate(
            [
                'type' => ConversationType::Direct,
                'metadata->is_assistant' => true,
            ],
            [
                'created_by_id' => $reviewer->id,
                'metadata' => ['is_assistant' => true, 'channel' => 'system_assistant'],
            ]
        );

        ConversationMember::updateOrCreate(
            ['conversation_id' => $assistantConv->id, 'user_id' => $reviewer->id],
            ['role' => 'member', 'joined_at' => Carbon::now()->subDays(4)]
        );
        ConversationMember::updateOrCreate(
            ['conversation_id' => $assistantConv->id, 'user_id' => $assistant->id],
            ['role' => 'admin', 'joined_at' => Carbon::now()->subDays(4)]
        );

        if ($assistantConv->messages()->count() === 0) {
            Message::create([
                'conversation_id' => $assistantConv->id,
                'sender_id' => $assistant->id,
                'type' => MessageType::Text,
                'body' => "Bonjour Kouamé ! Je suis l'Assistant officiel BANGO. Je suis là pour vous accompagner dans votre navigation, répondre à vos questions et vous faire découvrir les meilleures fonctionnalités de l'application !",
                'created_at' => Carbon::now()->subDays(4),
            ]);

            Message::create([
                'conversation_id' => $assistantConv->id,
                'sender_id' => $reviewer->id,
                'type' => MessageType::Text,
                'body' => "Bonjour ! Peux-tu m'indiquer comment créer un Réel avec ma série ?",
                'created_at' => Carbon::now()->subDays(3),
            ]);

            Message::create([
                'conversation_id' => $assistantConv->id,
                'sender_id' => $assistant->id,
                'type' => MessageType::Text,
                'body' => "C'est très simple Kouamé : lors de la publication de votre Réel dans le Studio BANGO, sélectionnez votre série « La Voisine » et indiquez le numéro d'épisode. Vos spectateurs retrouveront tous vos épisodes directement ordonnés sur la page de votre série !",
                'created_at' => Carbon::now()->subDays(3)->addMinutes(1),
            ]);
        }

        // 8. CONVERSATION DIRECTE AVEC AWA DIOP
        $awaConv = Conversation::firstOrCreate(
            [
                'type' => ConversationType::Direct,
                'title' => 'Awa Diop',
            ],
            [
                'created_by_id' => $reviewer->id,
            ]
        );

        ConversationMember::updateOrCreate(
            ['conversation_id' => $awaConv->id, 'user_id' => $reviewer->id],
            ['role' => 'member', 'joined_at' => Carbon::now()->subDays(2)]
        );
        ConversationMember::updateOrCreate(
            ['conversation_id' => $awaConv->id, 'user_id' => $awa->id],
            ['role' => 'member', 'joined_at' => Carbon::now()->subDays(2)]
        );

        if ($awaConv->messages()->count() === 0) {
            Message::create([
                'conversation_id' => $awaConv->id,
                'sender_id' => $awa->id,
                'type' => MessageType::Text,
                'body' => 'Salut Kouamé ! Tu as pu tester le nouveau spot de poisson braisé à Bonapriso ?',
                'created_at' => Carbon::now()->subHours(6),
            ]);

            Message::create([
                'conversation_id' => $awaConv->id,
                'sender_id' => $reviewer->id,
                'type' => MessageType::Text,
                'body' => "Salut Awa ! Oui absolument succulent, j'ai même posté une photo sur le Square ! On s'y retrouve ce week-end ?",
                'created_at' => Carbon::now()->subHours(4),
            ]);

            Message::create([
                'conversation_id' => $awaConv->id,
                'sender_id' => $awa->id,
                'type' => MessageType::Text,
                'body' => 'Superbe idée ! On se dit samedi vers 19h avec les amis. À très vite !',
                'created_at' => Carbon::now()->subHours(2),
            ]);
        }
    }
}

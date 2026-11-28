<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\Media\MediaProcessingStatus;
use App\Enums\Square\PostAudience;
use App\Enums\Square\PostMediaKind;
use App\Models\Auth\Role;
use App\Models\Geo\City;
use App\Models\Geo\Country;
use App\Models\Geo\Neighbourhood;
use App\Models\Square\Post;
use App\Models\Square\PostComment;
use App\Models\Square\PostLike;
use App\Models\Square\PostMedia;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class CommunityProfilesSeeder extends Seeder
{
    /**
     * Exécute le seeding des 100 profils camerounais et injecte le premier lot de posts Square.
     */
    public function run(): void
    {
        $profilesFile = database_path('data/bango_community_profiles.json');
        if (! file_exists($profilesFile)) {
            $this->command?->error("Fichier introuvable : {$profilesFile}");

            return;
        }

        $profiles = json_decode(file_get_contents($profilesFile), true);
        if (empty($profiles)) {
            $this->command?->error('Aucun profil à importer.');

            return;
        }

        $cameroun = Country::where('code', 'CM')->first();
        $douala = City::where('slug', 'douala')->first();
        $yaounde = City::where('slug', 'yaounde')->first();
        $userRole = Role::where('slug', 'user')->first() ?? Role::first();

        $this->command?->info('Création des 100 profils camerounais réels BANGO...');

        $createdUsers = [];
        $passwordHash = Hash::make('Bango2026!');

        foreach ($profiles as $p) {
            $cityModel = ($p['city_slug'] === 'douala') ? $douala : $yaounde;
            $hoodModel = Neighbourhood::where('name', $p['neighbourhood'])->first()
                ?? Neighbourhood::where('city_id', $cityModel?->id)->first();

            $user = User::updateOrCreate(
                ['handle' => $p['handle']],
                [
                    'name' => $p['name'],
                    'display_name' => $p['display_name'],
                    'email' => $p['email'],
                    'password' => $passwordHash,
                    'email_verified_at' => Carbon::now()->subDays(rand(5, 40)),
                    'phone' => $p['phone'],
                    'phone_verified_at' => Carbon::now()->subDays(rand(5, 40)),
                    'birth_date' => Carbon::now()->subYears(rand(20, 33))->subDays(rand(10, 300))->format('Y-m-d'),
                    'terms_accepted_at' => Carbon::now()->subDays(rand(5, 40)),
                    'onboarding_completed_at' => Carbon::now()->subDays(rand(5, 40)),
                    'onboarding_progress' => [
                        'terms' => true,
                        'profile' => true,
                        'location' => true,
                        'interests' => true,
                    ],
                    'country_id' => $cameroun?->id,
                    'country_code' => 'CM',
                    'city_id' => $cityModel?->id,
                    'city' => $p['city'],
                    'neighbourhood_id' => $hoodModel?->id,
                    'neighbourhood' => $p['neighbourhood'],
                    'role_id' => $userRole?->id,
                    'account_type' => AccountType::Personal,
                    'is_verified' => $p['is_verified'],
                    'badge' => $p['badge'],
                    'bio' => $p['bio'],
                    'avatar_url' => $p['avatar_url'],
                    'allow_downloads' => true,
                ]
            );

            $createdUsers[] = $user;
        }

        $this->command?->info('✅ '.count($createdUsers).' profils créés avec succès !');

        // Injection du premier lot de posts (les 25 premiers)
        $this->seedInitialPosts($createdUsers);
    }

    /**
     * Injecte les premiers posts dans le Square avec des dates étalées pour un feed vivant immédiat.
     *
     * @param  array<User>  $users
     */
    protected function seedInitialPosts(array $users): void
    {
        $postsFile = database_path('data/bango_square_posts.json');
        if (! file_exists($postsFile)) {
            return;
        }

        $allPosts = json_decode(file_get_contents($postsFile), true);
        if (empty($allPosts)) {
            return;
        }

        $initialBatch = array_slice($allPosts, 0, 25);
        $this->command?->info('Injection du premier lot de 25 posts sur le Square...');

        $now = Carbon::now();
        $injectedCount = 0;

        foreach ($initialBatch as $idx => $postData) {
            $idempotencyKey = $postData['id'];

            if (Post::where('idempotency_key', $idempotencyKey)->exists()) {
                continue;
            }

            // Auteur choisi parmi les profils créés
            $author = $users[$idx % count($users)];

            // Étalement des dates : du plus ancien (il y a 48h) au plus récent (il y a 5 min)
            $minutesAgo = (int) round((25 - $idx) * (48 * 60 / 25)) + rand(2, 15);
            $createdAt = (clone $now)->subMinutes($minutesAgo);

            $post = Post::create([
                'user_id' => $author->id,
                'body' => $postData['body'],
                'language' => $postData['language'] ?? 'fr',
                'audience' => PostAudience::Public,
                'comments_closed' => false,
                'allow_share' => true,
                'hide_likes' => false,
                'place_label' => $postData['place_label'],
                'place_city' => $author->city,
                'place_neighbourhood' => $author->neighbourhood,
                'idempotency_key' => $idempotencyKey,
                'likes_count' => rand(6, 42),
                'comments_count' => rand(2, 14),
                'views_count' => rand(80, 520),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            // Si photo associée
            if (! empty($postData['photo_url'])) {
                PostMedia::create([
                    'post_id' => $post->id,
                    'media_url' => $postData['photo_url'],
                    'poster_url' => $postData['photo_url'],
                    'media_type' => PostMediaKind::Photo,
                    'mime_type' => 'image/jpeg',
                    'file_size' => 245000,
                    'width' => 1080,
                    'height' => 1080,
                    'display_order' => 0,
                    'status' => MediaProcessingStatus::Completed,
                    'processed_at' => $createdAt,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
            }

            // Génération des vrais Likes réels (5 à 18 profils)
            $potentialLikers = array_filter($users, fn ($u) => $u->id !== $author->id);
            shuffle($potentialLikers);
            $likeCount = min(rand(5, 18), count($potentialLikers));
            $likers = array_slice($potentialLikers, 0, $likeCount);

            foreach ($likers as $liker) {
                PostLike::firstOrCreate([
                    'post_id' => $post->id,
                    'user_id' => $liker->id,
                ], [
                    'created_at' => (clone $createdAt)->addMinutes(rand(1, 40)),
                ]);
            }

            // Génération des vrais Commentaires réels (2 à 5 profils)
            $commentsLibraryFile = database_path('data/bango_comments.json');
            $commentsList = file_exists($commentsLibraryFile) ? json_decode(file_get_contents($commentsLibraryFile), true) : [];

            if (! empty($commentsList)) {
                $potentialCommenters = array_filter($potentialLikers, fn ($u) => ! in_array($u->id, array_column($likers, 'id')));
                if (empty($potentialCommenters)) {
                    $potentialCommenters = $potentialLikers;
                }
                shuffle($potentialCommenters);
                $commentCount = min(rand(2, 5), count($potentialCommenters));
                $commenters = array_slice($potentialCommenters, 0, $commentCount);

                foreach ($commenters as $cIdx => $commenter) {
                    $body = $commentsList[array_rand($commentsList)];
                    $commentCreatedAt = (clone $createdAt)->addMinutes(rand(5, 120));

                    PostComment::create([
                        'post_id' => $post->id,
                        'user_id' => $commenter->id,
                        'body' => $body,
                        'language' => 'fr',
                        'likes_count' => rand(0, 4),
                        'created_at' => $commentCreatedAt,
                        'updated_at' => $commentCreatedAt,
                    ]);
                }
            }

            // Synchronisation exacte des compteurs
            $actualLikes = PostLike::where('post_id', $post->id)->count();
            $actualComments = PostComment::where('post_id', $post->id)->count();
            $post->update([
                'likes_count' => $actualLikes,
                'comments_count' => $actualComments,
            ]);

            $injectedCount++;
        }

        $this->command?->info("✅ {$injectedCount} posts Square injectés avec succès (avec vrais likes et commentaires réels) !");
    }
}

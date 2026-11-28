<?php

namespace App\Console\Commands\Square;

use App\Enums\Media\MediaProcessingStatus;
use App\Enums\Square\PostAudience;
use App\Enums\Square\PostMediaKind;
use App\Models\Square\Post;
use App\Models\Square\PostMedia;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;

class InjectSquarePostsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'square:inject 
                            {--count=5 : Nombre de posts à injecter dans le Square}
                            {--all : Injecter tous les posts restants du réservoir}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Injecte progressivement de nouveaux posts du réservoir de 150 posts dans le fil Square';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $postsFile = database_path('data/bango_square_posts.json');
        if (! file_exists($postsFile)) {
            $this->error("Fichier de posts introuvable : {$postsFile}");

            return self::FAILURE;
        }

        $allPosts = json_decode(file_get_contents($postsFile), true);
        if (empty($allPosts)) {
            $this->error('Aucun post disponible dans le fichier.');

            return self::FAILURE;
        }

        // Récupération des clés déjà injectées
        $existingKeys = Post::whereIn('idempotency_key', array_column($allPosts, 'id'))
            ->pluck('idempotency_key')
            ->flip()
            ->all();

        // Filtrer les posts non encore injectés
        $pendingPosts = array_filter($allPosts, fn ($p) => ! isset($existingKeys[$p['id']]));

        $pendingCount = count($pendingPosts);
        $this->info('Nombre total de posts dans le réservoir : '.count($allPosts));
        $this->info('Posts déjà publiés : '.count($existingKeys));
        $this->info("Posts en attente d'injection : {$pendingCount}");

        if ($pendingCount === 0) {
            $this->warn('Tous les 150 posts ont déjà été injectés dans le Square !');

            return self::SUCCESS;
        }

        $countToInject = $this->option('all') ? $pendingCount : (int) $this->option('count');
        $countToInject = min($countToInject, $pendingCount);

        // Récupération des profils communautaires disponibles
        $communityUsers = User::where('email', 'like', '%_bango@bangoapp.com')->get();
        if ($communityUsers->isEmpty()) {
            $communityUsers = User::where('badge', '!=', 'admin')->limit(100)->get();
        }

        if ($communityUsers->isEmpty()) {
            $this->error('Aucun profil utilisateur trouvé pour publier les posts. Lancez d\'abord CommunityProfilesSeeder.');

            return self::FAILURE;
        }

        $postsToProcess = array_slice($pendingPosts, 0, $countToInject);
        $this->info("🚀 Injection de {$countToInject} post(s) en cours...");

        $now = Carbon::now();
        $injected = 0;

        foreach ($postsToProcess as $idx => $postData) {
            $author = $communityUsers->random();

            // Si plusieurs posts sont injectés en un lot, on décale légèrement de quelques minutes
            $createdAt = (clone $now)->subMinutes(($countToInject - $idx - 1) * rand(2, 6));

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
                'idempotency_key' => $postData['id'],
                'likes_count' => rand(1, 15),
                'comments_count' => rand(0, 4),
                'views_count' => rand(25, 180),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

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

            $injected++;
            $shortBody = mb_substr($postData['body'], 0, 45).'...';
            $this->line("  [{$postData['id']}] Publié par @{$author->handle} ({$author->city}) : \"{$shortBody}\"");
        }

        $remaining = $pendingCount - $injected;
        $this->info("✅ {$injected} post(s) injecté(s) avec succès ! Reste en réserve : {$remaining} post(s).");

        return self::SUCCESS;
    }
}

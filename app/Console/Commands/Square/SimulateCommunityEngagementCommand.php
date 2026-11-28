<?php

namespace App\Console\Commands\Square;

use App\Models\Square\Post;
use App\Models\Square\PostComment;
use App\Models\Square\PostLike;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SimulateCommunityEngagementCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'square:simulate-engagement
                            {--limit=20 : Nombre maximum de publications à animer}
                            {--force : Forcer l\'engagement même sur les posts ayant déjà des interactions}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Anime les publications du Square en générant de vrais likes et commentaires camerounais par les 100 comptes communautaires';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🤖 Animation communautaire du Square en cours...');

        // Récupération des profils communautaires
        $communityUsers = User::where('email', 'like', '%_bango@bangoapp.com')->get();
        if ($communityUsers->isEmpty()) {
            $communityUsers = User::where('badge', '!=', 'admin')->limit(100)->get();
        }

        if ($communityUsers->isEmpty()) {
            $this->error('Aucun profil communautaire disponible. Lancez d\'abord CommunityProfilesSeeder.');

            return self::FAILURE;
        }

        // Chargement des commentaires camerounais
        $commentsFile = database_path('data/bango_comments.json');
        $commentsList = file_exists($commentsFile) ? json_decode(file_get_contents($commentsFile), true) : [];

        $limit = (int) $this->option('limit');
        $force = (bool) $this->option('force');

        // Cibler les publications récentes qui manquent d'interactions (ou toutes si force)
        $postsQuery = Post::query()->latest();
        if (! $force) {
            $postsQuery->where(function ($q) {
                $q->where('likes_count', '<', 10)
                    ->orWhere('comments_count', '<', 3);
            });
        }

        $posts = $postsQuery->limit($limit)->get();

        if ($posts->isEmpty()) {
            $this->info('Aucune publication nécessitant une animation.');

            return self::SUCCESS;
        }

        $totalLikesAdded = 0;
        $totalCommentsAdded = 0;
        $now = Carbon::now();

        foreach ($posts as $post) {
            $authorId = $post->user_id;

            // 1. Ajouter des Likes (3 à 8 likes)
            $existingLikerIds = PostLike::where('post_id', $post->id)->pluck('user_id')->all();
            $eligibleLikers = $communityUsers->filter(fn ($u) => $u->id !== $authorId && ! in_array($u->id, $existingLikerIds))->shuffle();

            $likesToAddCount = rand(3, 8);
            $selectedLikers = $eligibleLikers->take($likesToAddCount);

            foreach ($selectedLikers as $liker) {
                PostLike::create([
                    'post_id' => $post->id,
                    'user_id' => $liker->id,
                    'created_at' => (clone $now)->subMinutes(rand(1, 30)),
                    'updated_at' => (clone $now)->subMinutes(rand(1, 30)),
                ]);
                $totalLikesAdded++;
            }

            // 2. Ajouter des Commentaires (1 à 3 commentaires)
            if (! empty($commentsList)) {
                $existingCommenterIds = PostComment::where('post_id', $post->id)->pluck('user_id')->all();
                $eligibleCommenters = $communityUsers->filter(fn ($u) => $u->id !== $authorId && ! in_array($u->id, $existingCommenterIds))->shuffle();

                $commentsToAddCount = rand(1, 3);
                $selectedCommenters = $eligibleCommenters->take($commentsToAddCount);

                foreach ($selectedCommenters as $commenter) {
                    $body = $commentsList[array_rand($commentsList)];
                    $commentCreatedAt = (clone $now)->subMinutes(rand(2, 60));

                    PostComment::create([
                        'post_id' => $post->id,
                        'user_id' => $commenter->id,
                        'body' => $body,
                        'language' => 'fr',
                        'likes_count' => rand(0, 3),
                        'created_at' => $commentCreatedAt,
                        'updated_at' => $commentCreatedAt,
                    ]);
                    $totalCommentsAdded++;
                }
            }

            // 3. Mise à jour des compteurs réels du post
            $actualLikes = PostLike::where('post_id', $post->id)->count();
            $actualComments = PostComment::where('post_id', $post->id)->count();
            $post->update([
                'likes_count' => $actualLikes,
                'comments_count' => $actualComments,
            ]);

            $this->line("  → Post #{$post->id} mis à jour : {$actualLikes} likes, {$actualComments} commentaires");
        }

        $this->info('✅ Animation terminée avec succès !');
        $this->info("   • Nouveaux likes créés : {$totalLikesAdded}");
        $this->info("   • Nouveaux commentaires créés : {$totalCommentsAdded}");

        return self::SUCCESS;
    }
}

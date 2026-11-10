<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Corriger les conversations déjà créées :
        // Celles dont tous les messages de l'auteur portent un page_id prennent ce page_id.
        $conversations = DB::table('conversations')
            ->whereNull('page_id')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('messages')
                    ->whereColumn('messages.conversation_id', 'conversations.id')
                    ->whereColumn('messages.sender_id', 'conversations.created_by_id')
                    ->whereNotNull('messages.page_id');
            })
            ->get();

        foreach ($conversations as $conv) {
            $authorMessages = DB::table('messages')
                ->where('conversation_id', $conv->id)
                ->where('sender_id', $conv->created_by_id)
                ->get();

            if ($authorMessages->isEmpty()) {
                continue;
            }

            $pageIds = $authorMessages->pluck('page_id')->unique();
            if ($pageIds->count() === 1 && $pageIds->first() !== null) {
                $pageId = $pageIds->first();
                DB::table('conversations')
                    ->where('id', $conv->id)
                    ->update([
                        'page_id' => $pageId,
                        'type' => 'page',
                    ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};

<?php

use App\Enums\Media\MediaProcessingStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_media', function (Blueprint $table) {
            $table->string('status')->default(MediaProcessingStatus::Completed->value)->index()->after('edit_metadata');
            $table->text('error_message')->nullable()->after('status');
            $table->timestamp('processing_started_at')->nullable()->after('error_message');
            $table->timestamp('processed_at')->nullable()->after('processing_started_at');
            $table->unsignedSmallInteger('attempts')->default(0)->after('processed_at');
        });
    }

    public function down(): void
    {
        Schema::table('post_media', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn([
                'status',
                'error_message',
                'processing_started_at',
                'processed_at',
                'attempts',
            ]);
        });
    }
};

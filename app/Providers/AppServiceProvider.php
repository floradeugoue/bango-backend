<?php

namespace App\Providers;

use App\Events\Listing\ListingPublished;
use App\Events\Story\StoryReplyCreated;
use App\Listeners\Listing\NotifyMatchingHousingSearches;
use App\Listeners\Story\CreateConversationFromStoryReply;
use App\Models\Boost\Campaign;
use App\Models\Message\Conversation;
use App\Models\Page\Page;
use App\Models\Page\PageCertification;
use App\Models\Page\PageMember;
use App\Models\Payment\SavedPaymentMethod;
use App\Models\Square\Post;
use App\Models\Square\PostComment;
use App\Models\Support\SupportTicket;
use App\Models\Support\SupportTicketAttachment;
use App\Models\User;
use App\Policies\CampaignPolicy;
use App\Policies\ConversationPolicy;
use App\Policies\PageCertificationPolicy;
use App\Policies\PageMemberPolicy;
use App\Policies\PagePolicy;
use App\Policies\PostCommentPolicy;
use App\Policies\PostPolicy;
use App\Policies\SavedPaymentMethodPolicy;
use App\Policies\SupportTicketPolicy;
use App\Services\Boost\CampaignEligibilityService;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\PaymentGatewayManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PaymentGatewayManager::class, function ($app) {
            return new PaymentGatewayManager($app);
        });

        $this->app->bind(PaymentGatewayInterface::class, function ($app) {
            return $app->make(PaymentGatewayManager::class)->driver();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(
            Post::class,
            PostPolicy::class
        );

        Gate::policy(
            PostComment::class,
            PostCommentPolicy::class
        );

        Gate::policy(
            Conversation::class,
            ConversationPolicy::class
        );

        Gate::policy(
            Page::class,
            PagePolicy::class
        );

        Gate::policy(
            PageMember::class,
            PageMemberPolicy::class
        );

        Gate::policy(
            PageCertification::class,
            PageCertificationPolicy::class
        );

        Gate::policy(
            Campaign::class,
            CampaignPolicy::class
        );

        Gate::policy(
            SupportTicket::class,
            SupportTicketPolicy::class
        );

        Gate::policy(
            SupportTicketAttachment::class,
            SupportTicketPolicy::class
        );

        Gate::policy(
            SavedPaymentMethod::class,
            SavedPaymentMethodPolicy::class
        );

        Gate::define('boost.start', function (
            User $user,
            ?Model $content = null,
            ?Page $page = null
        ) {
            return app(CampaignEligibilityService::class)->checkEligibility($user, $content, $page);
        });

        Event::listen(
            StoryReplyCreated::class,
            CreateConversationFromStoryReply::class
        );

        Event::listen(
            ListingPublished::class,
            NotifyMatchingHousingSearches::class
        );
    }
}

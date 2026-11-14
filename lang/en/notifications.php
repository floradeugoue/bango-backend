<?php

return [
    // Social
    'like' => [
        'title' => 'New like',
        'body' => ':user liked your post.',
    ],
    'story_like' => [
        'title' => 'New like',
        'body' => ':user liked your story.',
    ],
    'story_question_answer' => [
        'title' => 'Answer to your question',
        'body' => ':user answered your question: ":snippet"',
    ],
    'countdown_remind' => [
        'title' => 'Event reminder',
        'body' => 'Reminder set for the countdown.',
    ],
    'comment' => [
        'title' => 'New comment',
        'body' => ':user commented on your post: ":snippet"',
    ],
    'reply' => [
        'title' => 'New reply',
        'body' => ':user replied to your comment: ":snippet"',
    ],
    'follow' => [
        'title' => 'New follower',
        'body' => ':user started following you.',
    ],
    'follow_request' => [
        'title' => 'Follow request',
        'body' => ':user requested to follow you.',
    ],
    'mention' => [
        'title' => 'Mention',
        'body' => ':user mentioned you in a post or comment.',
    ],

    // Visit
    'visit_requested' => [
        'title' => 'Visit request',
        'body' => 'New visit request for :title',
    ],
    'visit_proposed' => [
        'title' => 'Proposed time slot',
        'body' => 'A new time slot (:time) is proposed for :title',
    ],
    'visit_confirmed' => [
        'title' => 'Visit confirmed',
        'body' => 'Visit confirmed for :title',
    ],
    'visit_declined' => [
        'title' => 'Visit declined',
        'body' => 'Your visit request for :title has been declined. Fees have been refunded.',
    ],
    'visit_cancelled' => [
        'title' => 'Visit cancelled',
        'body' => 'The visit for :title has been cancelled.',
    ],
    'listing_visit_requested' => [
        'title' => 'Visit request',
        'body' => 'New visit request: :user proposed time slots for :title',
    ],
    'listing_visit_slot_chosen' => [
        'title' => 'Slot selected',
        'body' => 'Your time slot on :time has been selected for visiting :title',
    ],

    // Message
    'new_message' => [
        'title' => 'New message from :user',
        'voice' => 'Voice note',
        'photo' => 'Photo',
        'story_reply' => 'Story reply',
        'default' => 'New message',
    ],
    'new_story_reply' => [
        'title' => ':user replied to your story',
    ],
    'added_to_group' => [
        'title' => 'New group',
        'body' => ':user added you to group ":group"',
    ],

    // Listing
    'new_matching_listing' => [
        'title' => 'New property available: :title',
    ],
    'listing_decision' => [
        'title' => 'Listing status update',
        'reject' => 'Your listing ":title" has been rejected. Reason: :reason',
        'request_changes' => 'Changes are required for your listing ":title". Reason: :reason',
        'unpublish' => 'Your listing ":title" has been unpublished. Reason: :reason',
        'merge' => 'Your listing ":title" has been merged with a similar listing. Reason: :reason',
        'default' => 'Update on your listing ":title".',
    ],

    // KYC
    'kyc_submitted' => [
        'title' => 'KYC verification submitted',
        'body' => 'Your KYC verification has been submitted successfully and is being reviewed.',
    ],
    'kyc_decision' => [
        'title' => 'Identity verification',
        'approved_name' => 'Your ID has been validated. Your profile is now named ":name".',
        'approved' => 'Your identity verification has been approved successfully.',
        'rejected' => 'Your KYC verification has been rejected. Reason: :reason',
        'changes_requested' => 'Additional information is required for your KYC verification. Reason: :reason',
    ],

    // Boost
    'campaign_activated' => [
        'title' => 'Boost campaign active',
        'body' => 'Your boost campaign is now active and being displayed.',
    ],
    'campaign_completed' => [
        'title' => 'Boost campaign completed',
        'body' => 'Your boost campaign has completed. Check your analytics.',
    ],
    'campaign_suspended' => [
        'title' => 'Boost campaign suspended',
        'body' => 'Your boost campaign has been suspended: :reason.',
    ],

    // Subscription
    'renewal_reminder' => [
        'title' => 'Upcoming renewal (:days days) — :product',
        'body' => 'Your :product subscription expires on :date (:amount :currency). Renew manually to keep your badge.',
    ],
    'payment_failed' => [
        'title' => 'Payment failed',
        'body' => 'Payment for :product failed. Please resolve the payment to keep your active badge.',
    ],

    // Page
    'page_invitation' => [
        'title' => 'Page invitation',
        'body' => 'You received an invitation to join page :page.',
    ],
    'page_member_accepted' => [
        'title' => 'Member accepted',
        'body' => 'A user accepted the invitation to join the Page.',
    ],
    'page_member_declined' => [
        'title' => 'Invitation declined',
        'body' => 'The invitation to join the Page was declined.',
    ],
    'page_member_revoked' => [
        'title' => 'Access revoked',
        'body' => 'Your permissions on the Professional Page have been revoked immediately.',
    ],
    'page_suspended' => [
        'title' => 'Page suspended',
        'body' => 'The Professional Page ":page" has been suspended.',
    ],
    'page_certification_submitted' => [
        'title' => 'Certification submitted',
        'body' => 'Your Page certification application has been forwarded for review.',
    ],
    'page_certification_approved' => [
        'title' => 'Page certified',
        'body' => 'Congratulations, your Professional Page has been officially certified!',
    ],
    'page_certification_rejected' => [
        'title' => 'Certification rejected',
        'body' => 'Your Page certification application has been rejected.',
    ],
    'page_certification_supplement' => [
        'title' => 'Additional information requested',
        'body' => 'Additional information is required for your certification application.',
    ],

    // Delegation
    'agent_invitation' => [
        'title' => 'Property mandate',
        'body' => 'New property management mandate received',
    ],
    'agent_accepted' => [
        'title' => 'Mandate accepted',
        'body' => 'The agent accepted your management mandate',
    ],
    'agent_declined' => [
        'title' => 'Mandate declined',
        'body' => 'The agent declined your management mandate',
    ],
    'delegation_revoked' => [
        'title' => 'Mandate revoked',
        'body' => 'The management mandate has been revoked',
    ],
    'visit_share_offer' => [
        'title' => 'New visit fee proposal: :percent%',
        'body' => 'A visit fee proposal of :percent% was submitted by :offer_by.',
    ],
    'visit_share_agreed' => [
        'title' => 'Visit fee agreement: :percent%',
        'body' => 'An agreement was reached setting the agent share of visit fees to :percent%.',
    ],

    // Support & Moderation
    'support_ticket_reply' => [
        'title' => 'Reply to ticket :reference',
    ],
    'moderation_decision' => [
        'title' => 'Moderation decision',
        'warn' => 'You received an official moderation warning.',
        'suspend' => 'Your account has been suspended until :until due to a policy violation.',
        'action' => 'A moderation action has been applied to one of your contents.',
    ],
    'moderation_appeal' => [
        'revoked' => 'Your appeal :reference has been accepted and the sanction was lifted.',
        'rejected' => 'Your appeal :reference was rejected. Reason: :notes',
    ],

    // Auth & Security
    'unknown_device_reported' => [
        'title' => 'Security alert',
        'body' => 'Your account has been secured and locked following an unknown device report.',
    ],
    'device_verification_code' => [
        'title' => 'Confirmation code',
        'body' => 'Your device verification code is :code.',
    ],

    // Push Notification Categories
    'categories' => [
        'visit' => 'Real estate visit',
        'new_message' => 'New message',
        'new_story_reply' => 'Reply to your story',
        'added_to_group' => 'New group',
        'kyc' => 'Identity verification',
        'listing' => 'Property listing',
        'campaign' => 'Boost campaign',
        'subscription' => 'Payment & Subscription',
        'page' => 'Professional page',
        'delegation' => 'Real estate delegation',
        'default' => 'BANGO',
    ],
];

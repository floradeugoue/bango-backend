<?php

namespace App\Enums\Admin;

enum AdminPermission: string
{
    case DashboardView = 'dashboard:view';
    case ModerationView = 'moderation:view';
    case ModerationAction = 'moderation:action';
    case SupportView = 'support:view';
    case SupportAction = 'support:action';
    case FinanceView = 'finance:view';
    case FinanceAction = 'finance:action';
    case VerificationView = 'verification:view';
    case VerificationAction = 'verification:action';
    case SearchTransverse = 'search:transverse';
    case NotificationsView = 'notifications:view';
    case ApprovalsView = 'approvals:view';
    case ApprovalsAction = 'approvals:action';
    case OperatorsManage = 'operators:manage';
    case RolesManage = 'roles:manage';
    case ModerationAssign = 'moderation:assign';
    case ModerationAppeals = 'moderation:appeals';
    case ModerationRules = 'moderation:rules';
    case ModerationExport = 'moderation:export';
    case CopyrightManage = 'copyright:manage';
    case TrendsManage = 'trends:manage';

    /**
     * @return array<string>
     */
    public static function all(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    /**
     * Retourne la matrice des permissions par défaut pour un rôle donné.
     *
     * @return array<string>
     */
    public static function defaultForRole(string $roleSlug): array
    {
        return match ($roleSlug) {
            'admin', 'direction' => self::all(),
            'moderation' => [
                self::DashboardView->value,
                self::ModerationView->value,
                self::ModerationAction->value,
                self::ModerationAssign->value,
                self::ModerationAppeals->value,
                self::ModerationExport->value,
                self::CopyrightManage->value,
                self::TrendsManage->value,
                self::SearchTransverse->value,
                self::NotificationsView->value,
                self::ApprovalsView->value,
            ],
            'support' => [
                self::DashboardView->value,
                self::SupportView->value,
                self::SupportAction->value,
                self::SearchTransverse->value,
                self::NotificationsView->value,
            ],
            'finance' => [
                self::DashboardView->value,
                self::FinanceView->value,
                self::FinanceAction->value,
                self::SearchTransverse->value,
                self::NotificationsView->value,
                self::ApprovalsView->value,
                self::ApprovalsAction->value,
            ],
            'verification' => [
                self::DashboardView->value,
                self::VerificationView->value,
                self::VerificationAction->value,
                self::SearchTransverse->value,
                self::NotificationsView->value,
                self::ApprovalsView->value,
                self::ApprovalsAction->value,
            ],
            default => [],
        };
    }
}

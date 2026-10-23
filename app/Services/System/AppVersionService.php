<?php

namespace App\Services\System;

use App\Models\Admin\SystemConfiguration;
use Illuminate\Support\Facades\Config;

class AppVersionService
{
    /**
     * Check version status for a given platform and client version.
     *
     * @return array{
     *     platform: string,
     *     min_version: string,
     *     latest_version: string,
     *     force_update: bool,
     *     store_url: string,
     *     update_recommended?: bool,
     *     maintenance_mode: bool,
     *     is_maintenance: bool,
     *     maintenance_message: string|null
     * }
     */
    public function check(string $platform, ?string $clientVersion = null): array
    {
        $platform = strtolower(trim($platform));

        if (! in_array($platform, ['android', 'ios', 'web'], true)) {
            $platform = 'android';
        }

        $config = Config::get("system.versions.{$platform}", []);

        $dynamicMin = null;
        $maintenanceMode = false;
        $maintenanceMessage = null;

        if (app()->isDownForMaintenance()) {
            $maintenanceMode = true;
            $maintenanceMessage = 'Plateforme en maintenance programmée.';
        }

        if (class_exists(SystemConfiguration::class)) {
            try {
                $dbMaintenance = (bool) SystemConfiguration::getValue('service.maintenance_mode', false);
                if ($dbMaintenance) {
                    $maintenanceMode = true;
                    $maintenanceMessage = SystemConfiguration::getValue('service.maintenance_message', 'Plateforme en maintenance programmée.');
                }

                $dbMinVersion = SystemConfiguration::getValue("service.min_version_{$platform}");
                if (! empty($dbMinVersion)) {
                    $dynamicMin = (string) $dbMinVersion;
                }
            } catch (\Throwable) {
                // Ignore DB error if table not yet migrated
            }
        }

        $minVersion = (string) ($dynamicMin ?: ($config['min_version'] ?? '1.0.0'));
        $latestVersion = (string) ($config['latest_version'] ?? $minVersion);
        $storeUrl = (string) ($config['store_url'] ?? '');

        $forceUpdate = false;
        $updateRecommended = false;

        if ($clientVersion !== null && trim($clientVersion) !== '') {
            $cleanedVersion = $this->sanitizeVersion($clientVersion);

            if ($cleanedVersion === null) {
                // Malformed version: cannot guarantee compatibility, but safe fallback avoids false hard-lock
                $forceUpdate = false;
            } else {
                $forceUpdate = version_compare($cleanedVersion, $minVersion, '<');
                $updateRecommended = version_compare($cleanedVersion, $latestVersion, '<');
            }
        }

        return [
            'platform' => $platform,
            'min_version' => $minVersion,
            'latest_version' => $latestVersion,
            'force_update' => $forceUpdate,
            'store_url' => $storeUrl,
            'update_recommended' => $updateRecommended,
            'maintenance_mode' => $maintenanceMode,
            'is_maintenance' => $maintenanceMode,
            'maintenance_message' => $maintenanceMessage,
        ];
    }

    /**
     * Sanitize and extract semantic version string (e.g. "1.2.3").
     */
    public function sanitizeVersion(string $version): ?string
    {
        $version = trim($version);

        if (preg_match('/^v?(\d+(\.\d+)*)/i', $version, $matches)) {
            return $matches[1];
        }

        return null;
    }
}

<?php

namespace App\Services;

use App\Models\SiteSetting;
use App\Models\User;
use App\Models\UserCapability;

class CapabilityService
{
    public const BOOLEAN_KEYS = [
        'can_type','can_ai','can_voice','can_export_docx','can_export_pdf','can_feedback','can_support',
    ];

    public const DEFAULTS = [
        'active' => true,
        'can_type' => true,
        'can_ai' => true,
        'can_voice' => true,
        'can_export_docx' => true,
        'can_export_pdf' => true,
        'can_feedback' => true,
        'can_support' => true,
        'weekly_free_pages' => 1,
        'max_file_mb' => 50,
        'daily_ai_requests' => 20,
    ];

    public function forUser(?User $user): array
    {
        if ($user?->isAdmin()) {
            return array_merge(self::DEFAULTS, [
                'active' => true,
                'can_type' => true,
                'can_ai' => true,
                'can_voice' => true,
                'can_export_docx' => true,
                'can_export_pdf' => true,
                'can_feedback' => true,
                'can_support' => true,
                'weekly_free_pages' => PHP_INT_MAX,
                'max_file_mb' => PHP_INT_MAX,
                'daily_ai_requests' => PHP_INT_MAX,
                'unlimited' => true,
            ]);
        }

        $global = [];
        foreach (self::DEFAULTS as $key => $default) {
            $global[$key] = SiteSetting::read($key, $default);
            if (in_array($key, self::BOOLEAN_KEYS, true)) $global[$key] = filter_var($global[$key], FILTER_VALIDATE_BOOLEAN);
            else $global[$key] = (int) $global[$key];
        }

        $override = $user ? UserCapability::where('user_id', $user->id)->first() : null;
        if (!$override) return $global + ['unlimited' => false];

        foreach (array_keys(self::DEFAULTS) as $key) {
            if ($override->{$key} !== null) $global[$key] = $override->{$key};
        }
        return $global + ['unlimited' => false];
    }

    public function allowed(?User $user, string $key): bool
    {
        return (bool) ($this->forUser($user)[$key] ?? false);
    }
}

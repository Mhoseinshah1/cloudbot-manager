<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditService
{
    public function record(
        string $action,
        ?Model $subject = null,
        ?Model $user = null,
        ?array $before = null,
        ?array $after = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subject !== null ? $subject->getMorphClass() : null,
            'subject_id' => $subject?->getKey(),
            'before' => $before,
            'after' => $after,
            'ip' => $ip ?? $this->currentIp(),
            'user_agent' => $userAgent ?? $this->currentUserAgent(),
        ]);
    }

    private function currentIp(): ?string
    {
        try {
            return request()->ip();
        } catch (\Throwable) {
            return null;
        }
    }

    private function currentUserAgent(): ?string
    {
        try {
            return (string) request()->userAgent();
        } catch (\Throwable) {
            return null;
        }
    }
}

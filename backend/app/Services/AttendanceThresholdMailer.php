<?php

namespace App\Services;

use App\Models\AttendanceAlertNotificationLog;
use App\Models\Student;
use App\Notifications\AttendanceThresholdNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Envoie les e-mails d’alerte absentéisme aux comptes parents liés à l’élève (CDC §4.8).
 * Au plus un envoi réussi par élève et par jour civil (anti-spam).
 */
class AttendanceThresholdMailer
{
    /**
     * @param  array{reasons: array<int, string>, consecutive: int, last_30d: int}  $payload
     */
    public function notifyParentsIfNeeded(Student $student, array $payload): void
    {
        if (! config('attendance.alert_mail_enabled', true)) {
            return;
        }

        if (AttendanceAlertNotificationLog::query()
            ->where('student_id', $student->id)
            ->whereDate('created_at', now()->toDateString())
            ->exists()) {
            return;
        }

        $student->loadMissing('parents.user');

        $alert = [
            'reasons' => array_values($payload['reasons'] ?? []),
            'consecutive' => (int) ($payload['consecutive'] ?? 0),
            'count_recent_30d' => (int) ($payload['last_30d'] ?? $payload['count_recent_30d'] ?? 0),
        ];

        $notified = false;
        foreach ($student->parents as $parent) {
            $user = $parent->user;
            if ($user === null || $user->email === '' || $user->email === null) {
                continue;
            }
            Notification::send($user, new AttendanceThresholdNotification($student, $alert));
            $notified = true;
        }

        if ($notified) {
            AttendanceAlertNotificationLog::query()->create([
                'student_id' => $student->id,
                'context' => $alert,
            ]);
        }
    }
}

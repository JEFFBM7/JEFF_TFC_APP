<?php

namespace App\Notifications;

use App\Models\Student;
use App\Services\AttendanceAlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification e-mail aux parents / tuteurs lorsque les seuils d'absentéisme sont atteints.
 */
class AttendanceThresholdNotification extends Notification
{
    use Queueable;

    /**
     * @param  array{reasons: array<int, string>, consecutive: int, count_recent_30d: int}  $alert
     */
    public function __construct(
        public Student $student,
        public array $alert,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Alerte absentéisme — '.$this->student->full_name)
            ->greeting('Bonjour'.($notifiable->name ? ' '.$notifiable->name : '').',');

        $mail->line(
            'Le suivi d’assiduité de '.$this->student->full_name.' dans EduConnect a atteint un seuil d’alerte défini par l’établissement.',
        );

        foreach ($this->alert['reasons'] as $code) {
            $mail->line('• '.$this->reasonLabel($code));
        }

        $mail->line(
            sprintf(
                'Détail : absences injustifiées consécutives (jours) : %d ; sur 30 jours glissants : %d.',
                (int) ($this->alert['consecutive'] ?? 0),
                (int) ($this->alert['count_recent_30d'] ?? 0),
            ),
        );

        $mail->line('Nous vous invitons à contacter l’établissement si nécessaire.')
            ->salutation('Cordialement, — '.config('app.name'));

        return $mail;
    }

    private function reasonLabel(string $code): string
    {
        return match ($code) {
            AttendanceAlertService::REASON_CONSECUTIVE => 'Plusieurs absences injustifiées sur des jours consécutifs (seuil paramétré atteint).',
            AttendanceAlertService::REASON_ROLLING => 'Plusieurs absences injustifiées sur une fenêtre glissante (seuil paramétré atteint).',
            AttendanceAlertService::REASON_LATE => 'Plusieurs retards sur une fenêtre glissante (seuil paramétré atteint).',
            default => $code,
        };
    }
}

<?php

namespace App\Notifications;

use App\Models\Term;
use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Envoyée aux parents lorsqu'un élève termine un trimestre sous le seuil
 * de moyenne (grades.low_average_threshold).
 */
class LowAverageNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Student $student,
        public Term $term,
        public float $average,
        public float $threshold,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Alerte pédagogique — '.$this->student->full_name)
            ->greeting('Bonjour'.($notifiable->name ? ' '.$notifiable->name : '').',')
            ->line(sprintf(
                'À la clôture du trimestre « %s », la moyenne générale de %s est de %.2f / 20.',
                $this->term->name,
                $this->student->full_name,
                $this->average,
            ))
            ->line(sprintf(
                'Cette moyenne est inférieure au seuil de vigilance défini par l\'établissement (%.2f / 20).',
                $this->threshold,
            ))
            ->line('Nous vous invitons à consulter le bulletin et à prendre contact avec l\'enseignant principal pour envisager un suivi adapté.')
            ->salutation('Cordialement, — '.config('app.name'));
    }
}

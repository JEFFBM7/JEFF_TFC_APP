<?php

namespace App\Notifications;

use App\Models\Term;
use App\Models\Student;
use App\Services\ReportCardService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Envoyée aux parents lorsqu'un trimestre est clôturé (CDC §4.6 / UC-04).
 * Le bulletin PDF de l'enfant est joint à l'e-mail.
 */
class ReportCardPublishedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Student $student,
        public Term $term,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $service = app(ReportCardService::class);
        $report = $service->compute($this->student, $this->term);
        $this->term->loadMissing('schoolYear');

        $pdfBytes = Pdf::loadView('report_cards.pdf', [
            'student' => $this->student->loadMissing('classroom.level'),
            'term' => $this->term,
            'subjects' => $report['subjects'],
            'overall_average' => $report['overall_average'],
            'appreciation' => $report['appreciation'] ?? null,
        ])->setPaper('a4')->output();

        $filename = sprintf(
            'bulletin-%s-%s.pdf',
            Str::slug($this->student->full_name),
            Str::slug($this->term->name),
        );

        return (new MailMessage)
            ->subject('Bulletin scolaire — '.$this->student->full_name.' — '.$this->term->name)
            ->greeting('Bonjour'.($notifiable->name ? ' '.$notifiable->name : '').',')
            ->line('Le bulletin du trimestre '.$this->term->name.' est désormais disponible pour '.$this->student->full_name.'.')
            ->line($report['overall_average'] !== null
                ? 'Moyenne générale : '.$report['overall_average'].' / 20.'
                : 'La moyenne générale n\'est pas encore calculée.')
            ->line('Vous pouvez aussi consulter le bulletin dans votre espace EduConnect.')
            ->salutation('Cordialement, — '.config('app.name'))
            ->attachData($pdfBytes, $filename, ['mime' => 'application/pdf']);
    }
}

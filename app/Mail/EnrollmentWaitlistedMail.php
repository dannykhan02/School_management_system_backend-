<?php

namespace App\Mail;

use App\Models\Enrollment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EnrollmentWaitlistedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Enrollment $enrollment
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Application Waitlisted — ' . ($this->enrollment->school->name ?? config('app.name')),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.enrollment.waitlisted',
        );
    }
}
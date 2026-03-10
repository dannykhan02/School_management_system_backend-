<?php

namespace App\Mail;

use App\Models\Enrollment;
use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EnrollmentApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Enrollment $enrollment,
        public readonly Student    $student,
        public readonly string     $loginEmail,
        public readonly string     $tempPassword,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Enrollment Approved — ' . ($this->enrollment->school->name ?? config('app.name')),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.enrollment.approved',
        );
    }
}
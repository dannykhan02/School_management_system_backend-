{{--
  emails.enrollment.submitted
  Sent immediately after a parent submits their enrollment form.
  Variables available: $enrollment (Enrollment model)
--}}
@extends('emails.enrollment.layout')

@section('email_title', 'Application Received — ' . ($enrollment->school->name ?? config('app.name')))

{{-- ── Header badge ─────────────────────────────────────────────────────── --}}
@section('badge_style', 'background-color:#0891b2; color:#ffffff;')
@section('badge_label', 'New Application')

{{-- ── Hero block ──────────────────────────────────────────────────────── --}}
@section('hero')
<div class="hero" style="background-color:#1e293b;">
  <div class="status-icon-wrap" style="background-color:#0e7490;">📬</div>
  <div class="hero-title" style="color:#f1f5f9;">Application Received</div>
  <p class="hero-sub" style="color:#94a3b8;">
    Thank you, <strong style="color:#e2e8f0;">{{ $enrollment->parent_first_name }}</strong>.
    We've received the enrollment application for
    <strong style="color:#e2e8f0;">{{ $enrollment->first_name }} {{ $enrollment->last_name }}</strong>
    and it is now awaiting review by our admissions team.
  </p>
  <div class="status-pill"
    style="background-color:#0c4a6e; color:#38bdf8; border-color:#0369a1;">
    <span class="status-dot" style="background-color:#38bdf8;"></span>
    Submitted — Awaiting Review
  </div>
</div>
@endsection

{{-- ── Body cards ──────────────────────────────────────────────────────── --}}
@section('content')

{{-- Application details --}}
<div class="card">
  <div class="section-label">Application Details</div>
  <div class="detail-grid">
    <div class="detail-row">
      <span class="detail-key">Reference No.</span>
      <span class="detail-val mono">#{{ str_pad($enrollment->id, 6, '0', STR_PAD_LEFT) }}</span>
    </div>
    <div class="detail-row">
      <span class="detail-key">Student Name</span>
      <span class="detail-val">{{ $enrollment->first_name }} {{ $enrollment->last_name }}</span>
    </div>
    <div class="detail-row">
      <span class="detail-key">Enrollment Type</span>
      <span class="detail-val">{{ ucfirst($enrollment->enrollment_type) }}</span>
    </div>
    <div class="detail-row">
      <span class="detail-key">Applying For</span>
      <span class="detail-val">{{ $enrollment->applyingForClassroom?->class_name ?? 'Not specified' }}</span>
    </div>
    <div class="detail-row">
      <span class="detail-key">Academic Year</span>
      <span class="detail-val">{{ $enrollment->academicYear?->year ?? '—' }}</span>
    </div>
    <div class="detail-row">
      <span class="detail-key">Date Submitted</span>
      <span class="detail-val">{{ $enrollment->applied_at?->format('d M Y') ?? now()->format('d M Y') }}</span>
    </div>
  </div>
</div>

{{-- Tracking token --}}
<div class="card">
  <div class="section-label">Your Tracking Token</div>
  <div class="token-block">
    <div class="token-label">Token — keep this safe</div>
    <div class="token-value">{{ $enrollment->tracking_token }}</div>
    <div class="token-hint">
      Use this token together with your reference number to check your application status at any time.
      This is the only time this token will be shown.
    </div>
  </div>
  <div class="cta-wrap">
    <a href="{{ config('app.url') }}/enrollment/track?ref={{ $enrollment->id }}&email={{ urlencode($enrollment->parent_email) }}"
       class="cta-btn" style="background-color:#0891b2; color:#ffffff;">
      Track My Application →
    </a>
  </div>
</div>

{{-- What happens next --}}
<div class="card card-last">
  <div class="section-label">What Happens Next</div>
  <div class="notice amber">
    <span class="notice-icon">⚡</span>
    <span class="notice-text">
      <strong>Our team will review your application</strong> and you will receive an email
      notification once a decision has been made. This typically takes 3–5 working days.
    </span>
  </div>

  @if($enrollment->school?->phone || $enrollment->school?->email)
  <div class="contact-block">
    <div class="section-label" style="margin-bottom:8px;">School Contact</div>
    @if($enrollment->school?->phone)
    <div class="contact-line">
      📞 <a href="tel:{{ $enrollment->school->phone }}">{{ $enrollment->school->phone }}</a>
    </div>
    @endif
    @if($enrollment->school?->email)
    <div class="contact-line">
      ✉️ <a href="mailto:{{ $enrollment->school->email }}">{{ $enrollment->school->email }}</a>
    </div>
    @endif
  </div>
  @endif
</div>

@endsection
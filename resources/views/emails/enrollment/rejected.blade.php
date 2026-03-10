{{--
  emails.enrollment.rejected
  Sent when an admin rejects an application.
  Variables available: $enrollment (Enrollment model)
--}}
@extends('emails.enrollment.layout')

@section('email_title', 'Application Update — ' . ($enrollment->school->name ?? config('app.name')))

{{-- ── Header badge ─────────────────────────────────────────────────────── --}}
@section('badge_style', 'background-color:#475569; color:#ffffff;')
@section('badge_label', 'Application Update')

{{-- ── Hero block ──────────────────────────────────────────────────────── --}}
@section('hero')
<div class="hero" style="background-color:#1e293b;">
  <div class="status-icon-wrap" style="background-color:#374151;">📋</div>
  <div class="hero-title" style="color:#f1f5f9;">Application Outcome</div>
  <p class="hero-sub" style="color:#94a3b8;">
    Dear <strong style="color:#e2e8f0;">{{ $enrollment->parent_first_name }}</strong>,
    we have reviewed the enrollment application for
    <strong style="color:#e2e8f0;">{{ $enrollment->first_name }} {{ $enrollment->last_name }}</strong>.
    Unfortunately, we are unable to offer a place at this time.
  </p>
  <div class="status-pill"
    style="background-color:#1e1e2e; color:#94a3b8; border-color:#334155;">
    <span class="status-dot" style="background-color:#64748b;"></span>
    Unsuccessful
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
      <span class="detail-key">Applied For</span>
      <span class="detail-val">{{ $enrollment->applyingForClassroom?->class_name ?? 'Not specified' }}</span>
    </div>
    <div class="detail-row">
      <span class="detail-key">Academic Year</span>
      <span class="detail-val">{{ $enrollment->academicYear?->year ?? '—' }}</span>
    </div>
    <div class="detail-row">
      <span class="detail-key">Date Reviewed</span>
      <span class="detail-val">{{ $enrollment->rejected_at?->format('d M Y') ?? now()->format('d M Y') }}</span>
    </div>
  </div>
</div>

{{-- Rejection reason — only shown if provided --}}
@if($enrollment->rejection_reason)
<div class="card">
  <div class="section-label">Reason</div>
  <div class="reason-block">
    <div class="reason-label">Details from the admissions team</div>
    <div class="reason-text">{{ $enrollment->rejection_reason }}</div>
  </div>
</div>
@endif

{{-- Contact / next steps --}}
<div class="card card-last">
  <div class="section-label">Need More Information?</div>
  <div class="notice slate">
    <span class="notice-icon">💬</span>
    <span class="notice-text">
      <strong>Please contact the school directly</strong> if you would like more information
      about this decision or wish to discuss alternative options.
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
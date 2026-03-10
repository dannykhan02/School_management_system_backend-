{{--
  emails.enrollment.waitlisted
  Sent when a school is at capacity and the application is placed on the waitlist.
  Variables available: $enrollment (Enrollment model)
--}}
@extends('emails.enrollment.layout')

@section('email_title', 'Application Waitlisted — ' . ($enrollment->school->name ?? config('app.name')))

{{-- ── Header badge ─────────────────────────────────────────────────────── --}}
@section('badge_style', 'background-color:#b45309; color:#ffffff;')
@section('badge_label', 'Waitlisted')

{{-- ── Hero block ──────────────────────────────────────────────────────── --}}
@section('hero')
<div class="hero" style="
  background: linear-gradient(135deg, #1c1205 0%, #292010 50%, #1e293b 100%);
  border-left-color: #92400e;
  border-right-color: #92400e;
">
  <div class="status-icon-wrap" style="background-color:#92400e;">⏳</div>
  <div class="hero-title" style="color:#fef3c7;">You're on the Waitlist</div>
  <p class="hero-sub" style="color:#d97706;">
    Dear <strong style="color:#fef3c7;">{{ $enrollment->parent_first_name }}</strong>,
    we have reviewed the application for
    <strong style="color:#fef3c7;">{{ $enrollment->first_name }} {{ $enrollment->last_name }}</strong>.
    The school is currently at capacity, but your application has been added to the waiting list.
  </p>
  <div class="status-pill"
    style="background-color:#292010; color:#fbbf24; border-color:#92400e;">
    <span class="status-dot" style="background-color:#fbbf24;"></span>
    Waitlisted — On the Waiting List
  </div>
</div>
@endsection

{{-- ── Body cards ──────────────────────────────────────────────────────── --}}
@section('content')

{{-- Waitlist status + application details --}}
<div class="card">
  {{-- Status highlight --}}
  <div style="
    background: linear-gradient(135deg, #1c1205, #221809);
    border: 1px solid #92400e;
    border-radius: 12px;
    padding: 18px 20px;
    text-align: center;
    margin-bottom: 18px;
  ">
    <div style="font-size:10px; font-weight:700; color:#fbbf24; letter-spacing:.1em; text-transform:uppercase; margin-bottom:6px;">
      Current Status
    </div>
    <div style="font-size:13px; font-weight:600; color:#fef3c7; line-height:1.55;">
      Your application is active on the waiting list.<br/>
      You will be contacted if a place becomes available.
    </div>
  </div>

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
      <span class="detail-key">Applying For</span>
      <span class="detail-val">{{ $enrollment->applyingForClassroom?->class_name ?? 'Not specified' }}</span>
    </div>
    <div class="detail-row">
      <span class="detail-key">Academic Year</span>
      <span class="detail-val">{{ $enrollment->academicYear?->year ?? '—' }}</span>
    </div>
    <div class="detail-row">
      <span class="detail-key">Status</span>
      <span class="detail-val amber">Waitlisted</span>
    </div>
  </div>
</div>

{{-- What this means --}}
<div class="card">
  <div class="section-label">What This Means</div>
  <div class="notice amber">
    <span class="notice-icon">💡</span>
    <span class="notice-text">
      <strong>Your application is still active.</strong> Being waitlisted means the school
      is currently full for this intake. If a place becomes available due to a cancellation
      or additional capacity, you will be contacted immediately.
    </span>
  </div>
  <div class="notice slate">
    <span class="notice-icon">📬</span>
    <span class="notice-text">
      <strong>Keep an eye on your inbox.</strong> All updates will be sent to this email
      address. You can also track your application status using your reference number at any time.
    </span>
  </div>
  <div class="cta-wrap">
    <a href="{{ config('app.url') }}/enrollment/track?ref={{ $enrollment->id }}&email={{ urlencode($enrollment->parent_email) }}"
       class="cta-btn" style="background-color:#1e293b; border:1px solid #334155; color:#e2e8f0;">
      Track My Application →
    </a>
  </div>
</div>

{{-- Contact --}}
<div class="card card-last">
  <div class="section-label">Questions?</div>

  @if($enrollment->school?->phone || $enrollment->school?->email)
  <div class="contact-block" style="margin-top:0;">
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
  @else
  <div class="notice slate" style="margin-top:0;">
    <span class="notice-icon">🏫</span>
    <span class="notice-text">
      Please visit the school office directly to enquire about your waitlist position.
    </span>
  </div>
  @endif
</div>

@endsection
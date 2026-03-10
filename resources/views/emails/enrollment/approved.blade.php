{{--
  emails.enrollment.approved
  Sent when an admin approves an application.
  Variables available: $enrollment (Enrollment), $student (Student),
                       $loginEmail (string), $tempPassword (string)
--}}
@extends('emails.enrollment.layout')

@section('email_title', 'Enrollment Approved — ' . ($enrollment->school->name ?? config('app.name')))

{{-- ── Header badge ─────────────────────────────────────────────────────── --}}
@section('badge_style', 'background-color:#16a34a; color:#ffffff;')
@section('badge_label', '✓ Approved')

{{-- ── Hero block ──────────────────────────────────────────────────────── --}}
@section('hero')
<div class="hero" style="
  background: linear-gradient(135deg, #052e16 0%, #14532d 50%, #1e293b 100%);
  border-left-color: #16a34a;
  border-right-color: #16a34a;
  position: relative;
  overflow: hidden;
">
  {{-- Decorative watermark --}}
  <div aria-hidden="true" style="
    position:absolute; right:-8px; top:-16px;
    font-size:120px; line-height:1; font-weight:900;
    color:rgba(22,163,74,0.06); pointer-events:none; user-select:none;
  ">✓</div>

  <div class="status-icon-wrap" style="background-color:#16a34a;">🎓</div>
  <div class="hero-title" style="color:#f0fdf4;">
    Congratulations,<br/>{{ $enrollment->parent_first_name }}!
  </div>
  <p class="hero-sub" style="color:#86efac;">
    <strong style="color:#f0fdf4;">{{ $enrollment->first_name }} {{ $enrollment->last_name }}</strong>'s
    enrollment application has been approved. Welcome to
    <strong style="color:#f0fdf4;">{{ $enrollment->school->name ?? 'our school' }}</strong>!
  </p>
  <div class="status-pill"
    style="background-color:#14532d; color:#4ade80; border-color:#16a34a;">
    <span class="status-dot" style="background-color:#4ade80;"></span>
    Approved
  </div>
</div>
@endsection

{{-- ── Body cards ──────────────────────────────────────────────────────── --}}
@section('content')

{{-- Admission number + student details --}}
<div class="card">
  <div class="admission-highlight"
    style="background:linear-gradient(135deg,#052e16,#0a3a1f); border:1px solid #16a34a;">
    <div class="admission-label" style="color:#4ade80;">Admission Number</div>
    <div class="admission-number" style="color:#f0fdf4;">{{ $student->admission_number }}</div>
  </div>

  <div class="section-label">Student Details</div>
  <div class="detail-grid">
    <div class="detail-row">
      <span class="detail-key">Full Name</span>
      <span class="detail-val">{{ $student->full_name ?? $enrollment->first_name . ' ' . $enrollment->last_name }}</span>
    </div>
    <div class="detail-row">
      <span class="detail-key">Class Assigned</span>
      <span class="detail-val green">{{ $enrollment->assignedClassroom?->class_name ?? '—' }}</span>
    </div>
    <div class="detail-row">
      <span class="detail-key">Stream</span>
      <span class="detail-val">{{ $enrollment->assignedStream?->name ?? '—' }}</span>
    </div>
    <div class="detail-row">
      <span class="detail-key">Academic Year</span>
      <span class="detail-val">{{ $enrollment->academicYear?->year ?? '—' }}</span>
    </div>
    <div class="detail-row">
      <span class="detail-key">Approved On</span>
      <span class="detail-val">{{ $enrollment->approved_at?->format('d M Y') ?? now()->format('d M Y') }}</span>
    </div>
  </div>
</div>

{{-- Login credentials --}}
<div class="card">
  <div class="section-label">Student Portal Login</div>
  <div class="cred-block">
    <div class="cred-header">Login Credentials — Keep Confidential</div>
    <div class="cred-row">
      <div class="cred-key">Email / Username</div>
      <div class="cred-val">{{ $loginEmail }}</div>
    </div>
    <div class="cred-row">
      <div class="cred-key">Temporary Password</div>
      <div class="cred-val">{{ $tempPassword }}</div>
    </div>
  </div>

  <div class="notice amber" style="margin-top:14px;">
    <span class="notice-icon">⚠️</span>
    <span class="notice-text">
      <strong>Change your password immediately</strong> after your first login.
      This temporary password will expire after first use for security purposes.
    </span>
  </div>

  <div class="cta-wrap">
    <a href="{{ config('app.url') }}/login"
       class="cta-btn" style="background-color:#16a34a; color:#ffffff;">
      Login to Student Portal →
    </a>
  </div>
</div>

{{-- Next steps --}}
<div class="card card-last">
  <div class="section-label">Next Steps</div>
  <div class="notice green">
    <span class="notice-icon">📋</span>
    <span class="notice-text">
      Please report to the school's administration office to complete registration
      and collect your student ID card. Bring this email and any required documents.
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
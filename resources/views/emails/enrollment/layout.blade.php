<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta http-equiv="X-UA-Compatible" content="IE=edge"/>
  <title>@yield('email_title', config('app.name'))</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap');

    /* ── Reset ───────────────────────────────────────────────────────────── */
    *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

    /* ── Base ────────────────────────────────────────────────────────────── */
    body {
      background-color: #0f172a;
      font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      -webkit-font-smoothing: antialiased;
      color: #e2e8f0;
    }

    /* ── Outer wrapper & container ───────────────────────────────────────── */
    .email-wrapper {
      background-color: #0f172a;
      padding: 40px 16px 60px;
    }

    .email-container {
      max-width: 560px;
      margin: 0 auto;
    }

    /* ═══════════════════════════════════════════════════════════════════════
       SHARED HEADER BAR
       School name + badge — badge colour overridden per template via
       the .header-badge inline style yielded from each child.
    ═══════════════════════════════════════════════════════════════════════ */
    .header {
      background-color: #1e293b;
      border: 1px solid #334155;
      border-bottom: none;
      border-radius: 16px 16px 0 0;
      padding: 22px 30px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
    }

    .school-name {
      font-size: 14px;
      font-weight: 700;
      color: #f1f5f9;
      letter-spacing: -0.01em;
    }

    .school-meta {
      font-size: 11px;
      color: #64748b;
      margin-top: 2px;
    }

    .header-badge {
      font-size: 10px;
      font-weight: 700;
      padding: 4px 12px;
      border-radius: 99px;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      white-space: nowrap;
      flex-shrink: 0;
      /* background & color set inline per child template */
    }

    /* ═══════════════════════════════════════════════════════════════════════
       HERO BLOCK  — full visual section yielded per child
       Each child provides its own gradient / border / icon / copy.
       Shared structural rules live here so children only override colours.
    ═══════════════════════════════════════════════════════════════════════ */
    .hero {
      border-left: 1px solid #334155;
      border-right: 1px solid #334155;
      padding: 36px 30px 30px;
    }

    .status-icon-wrap {
      width: 52px;
      height: 52px;
      border-radius: 13px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 18px;
      font-size: 24px;
      line-height: 1;
      /* background set inline per child */
    }

    .hero-title {
      font-size: 26px;
      font-weight: 800;
      letter-spacing: -0.03em;
      line-height: 1.2;
      margin-bottom: 10px;
      /* color set inline per child */
    }

    .hero-sub {
      font-size: 13px;
      line-height: 1.65;
      /* color set inline per child */
    }

    .hero-sub strong { font-weight: 600; }

    .status-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-size: 11px;
      font-weight: 700;
      padding: 5px 13px;
      border-radius: 99px;
      border: 1px solid;
      margin-top: 14px;
      letter-spacing: 0.02em;
      /* colours set inline per child */
    }

    .status-dot {
      width: 6px;
      height: 6px;
      border-radius: 50%;
      display: inline-block;
      flex-shrink: 0;
      /* background set inline per child */
    }

    /* ═══════════════════════════════════════════════════════════════════════
       CARD SECTIONS  — stacked content blocks
    ═══════════════════════════════════════════════════════════════════════ */
    .card {
      background-color: #1e293b;
      border: 1px solid #334155;
      border-top: none;
      padding: 26px 30px;
    }

    .card-last {
      border-radius: 0 0 16px 16px;
    }

    /* Section micro-label e.g. "APPLICATION DETAILS" */
    .section-label {
      font-size: 10px;
      font-weight: 700;
      color: #475569;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      margin-bottom: 12px;
    }

    /* ── Zebra detail table ───────────────────────────────────────────── */
    .detail-grid {
      border: 1px solid #334155;
      border-radius: 10px;
      overflow: hidden;
    }

    .detail-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 11px 15px;
      border-bottom: 1px solid #1e293b;
      gap: 8px;
    }

    .detail-row:last-child { border-bottom: none; }
    .detail-row:nth-child(odd)  { background-color: #0f172a; }
    .detail-row:nth-child(even) { background-color: #162032; }

    .detail-key {
      font-size: 12px;
      color: #64748b;
      font-weight: 500;
      white-space: nowrap;
    }

    .detail-val {
      font-size: 12px;
      color: #e2e8f0;
      font-weight: 700;
      text-align: right;
      word-break: break-word;
    }

    /* Modifier classes for detail values */
    .detail-val.mono  { font-family: 'DM Mono', monospace; font-size: 11px; color: #22d3ee; }
    .detail-val.green { color: #4ade80; }
    .detail-val.amber { color: #fbbf24; }

    /* ── Token block ───────────────────────────────────────────────────── */
    .token-block {
      background-color: #0f172a;
      border: 1px solid #0e7490;
      border-radius: 10px;
      padding: 14px 18px;
      margin-top: 14px;
    }

    .token-label {
      font-size: 10px;
      font-weight: 700;
      color: #0891b2;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      margin-bottom: 6px;
    }

    .token-value {
      font-family: 'DM Mono', monospace;
      font-size: 14px;
      color: #22d3ee;
      font-weight: 500;
      letter-spacing: 0.06em;
      word-break: break-all;
    }

    .token-hint {
      font-size: 10px;
      color: #475569;
      margin-top: 6px;
      line-height: 1.5;
    }

    /* ── Admission number highlight ────────────────────────────────────── */
    .admission-highlight {
      border-radius: 12px;
      padding: 18px 22px;
      text-align: center;
      margin-bottom: 18px;
      /* bg + border set inline */
    }

    .admission-label {
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      margin-bottom: 6px;
      /* color set inline */
    }

    .admission-number {
      font-family: 'DM Mono', monospace;
      font-size: 26px;
      font-weight: 500;
      letter-spacing: 0.1em;
      /* color set inline */
    }

    /* ── Credentials block ─────────────────────────────────────────────── */
    .cred-block {
      background-color: #0f172a;
      border: 1px solid #334155;
      border-radius: 10px;
      overflow: hidden;
    }

    .cred-header {
      background-color: #162032;
      padding: 9px 15px;
      font-size: 10px;
      font-weight: 700;
      color: #64748b;
      letter-spacing: 0.1em;
      text-transform: uppercase;
    }

    .cred-row {
      padding: 13px 15px;
      border-bottom: 1px solid #1e293b;
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    .cred-row:last-child { border-bottom: none; }

    .cred-key {
      font-size: 10px;
      color: #64748b;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.06em;
    }

    .cred-val {
      font-family: 'DM Mono', monospace;
      font-size: 14px;
      color: #22d3ee;
      font-weight: 500;
      word-break: break-all;
    }

    /* ── Reason block ──────────────────────────────────────────────────── */
    .reason-block {
      background-color: #0f172a;
      border: 1px solid #334155;
      border-radius: 10px;
      padding: 14px 18px;
    }

    .reason-label {
      font-size: 10px;
      font-weight: 700;
      color: #64748b;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      margin-bottom: 8px;
    }

    .reason-text {
      font-size: 12px;
      color: #cbd5e1;
      line-height: 1.65;
    }

    /* ── Notice / callout boxes ────────────────────────────────────────── */
    .notice {
      border-radius: 10px;
      padding: 13px 15px;
      display: flex;
      gap: 10px;
      align-items: flex-start;
    }

    .notice + .notice { margin-top: 10px; }

    .notice-icon { font-size: 14px; flex-shrink: 0; margin-top: 1px; }
    .notice-text { font-size: 12px; line-height: 1.6; }
    .notice-text strong { font-weight: 600; }

    /* Colour variants */
    .notice.amber { background-color: #1c1a0f; border: 1px solid #854d0e; }
    .notice.amber .notice-text         { color: #fbbf24; }
    .notice.amber .notice-text strong  { color: #fde68a; }

    .notice.green { background-color: #052e16; border: 1px solid #166534; }
    .notice.green .notice-text         { color: #86efac; }
    .notice.green .notice-text strong  { color: #f0fdf4; }

    .notice.slate { background-color: #162032; border: 1px solid #334155; }
    .notice.slate .notice-text         { color: #94a3b8; }
    .notice.slate .notice-text strong  { color: #cbd5e1; }

    /* ── CTA button ────────────────────────────────────────────────────── */
    .cta-wrap { text-align: center; margin-top: 18px; }

    .cta-btn {
      display: inline-block;
      text-decoration: none;
      font-family: 'DM Sans', sans-serif;
      font-size: 13px;
      font-weight: 700;
      padding: 12px 30px;
      border-radius: 10px;
      letter-spacing: -0.01em;
      /* bg + color set inline per child */
    }

    /* ── School contact block ──────────────────────────────────────────── */
    .contact-block {
      margin-top: 14px;
      padding: 13px 15px;
      border: 1px solid #334155;
      border-radius: 10px;
      background-color: #0f172a;
    }

    .contact-line {
      font-size: 12px;
      color: #94a3b8;
      margin-bottom: 6px;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .contact-line:last-child { margin-bottom: 0; }
    .contact-line a { color: #22d3ee; text-decoration: none; }

    /* ═══════════════════════════════════════════════════════════════════════
       SHARED FOOTER
    ═══════════════════════════════════════════════════════════════════════ */
    .email-footer {
      margin-top: 20px;
      text-align: center;
      padding: 0 16px;
    }

    .footer-school {
      font-size: 11px;
      font-weight: 700;
      color: #475569;
      margin-bottom: 3px;
    }

    .footer-divider {
      width: 32px;
      height: 1px;
      background-color: #1e293b;
      margin: 8px auto;
    }

    .footer-copy {
      font-size: 10px;
      color: #334155;
      line-height: 1.65;
    }

    .footer-copy a { color: #0891b2; text-decoration: none; }

    /* ═══════════════════════════════════════════════════════════════════════
       RESPONSIVE
    ═══════════════════════════════════════════════════════════════════════ */
    @media only screen and (max-width: 600px) {
      .email-wrapper   { padding: 16px 10px 40px; }
      .header          { padding: 16px 18px; }
      .hero            { padding: 26px 18px 22px; }
      .card            { padding: 22px 18px; }
      .hero-title      { font-size: 22px; }
      .admission-number { font-size: 20px; }
      .cred-val        { font-size: 13px; }
      .cta-btn         { display: block; text-align: center; }
    }

    @media only screen and (max-width: 400px) {
      .header          { flex-direction: column; align-items: flex-start; }
      .hero            { padding: 22px 14px 18px; }
      .card            { padding: 18px 14px; }
      .hero-title      { font-size: 19px; }
      .admission-number { font-size: 17px; }
      .detail-row      { flex-direction: column; align-items: flex-start; gap: 2px; }
      .detail-val      { text-align: left; }
      .cred-val        { font-size: 12px; }
      .token-value     { font-size: 12px; }
    }
  </style>
</head>
<body>
<div class="email-wrapper">
  <div class="email-container">

    {{-- ── Shared header bar ──────────────────────────────────────────── --}}
    <div class="header">
      <div>
        <div class="school-name">{{ $enrollment->school->name ?? config('app.name') }}</div>
        <div class="school-meta">Student Enrollment System</div>
      </div>
      {{-- badge bg + text colour provided by each child --}}
      <div class="header-badge" style="@yield('badge_style')">
        @yield('badge_label')
      </div>
    </div>

    {{-- ── Hero block — fully overridden per child ───────────────────── --}}
    @yield('hero')

    {{-- ── Body cards — unique per child ────────────────────────────── --}}
    @yield('content')

    {{-- ── Shared footer ─────────────────────────────────────────────── --}}
    <div class="email-footer">
      <div class="footer-school">{{ $enrollment->school->name ?? config('app.name') }}</div>

      @if($enrollment->school?->phone || $enrollment->school?->email)
      <div class="footer-divider"></div>
      <div class="footer-copy">
        @if($enrollment->school?->phone)
          📞 <a href="tel:{{ $enrollment->school->phone }}">{{ $enrollment->school->phone }}</a>
          @if($enrollment->school?->email) &nbsp;·&nbsp; @endif
        @endif
        @if($enrollment->school?->email)
          ✉️ <a href="mailto:{{ $enrollment->school->email }}">{{ $enrollment->school->email }}</a>
        @endif
      </div>
      @endif

      <div class="footer-divider"></div>
      <div class="footer-copy">
        This email was sent to <strong style="color:#475569;">{{ $enrollment->parent_email }}</strong>.<br/>
        If this wasn't you, please
        @if($enrollment->school?->email)
          <a href="mailto:{{ $enrollment->school->email }}">contact us</a>
        @else
          contact us
        @endif
        immediately.
      </div>
    </div>

  </div>
</div>
</body>
</html>
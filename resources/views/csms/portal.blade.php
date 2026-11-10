<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>CSMS | PT Sentral Sustainability Consulting</title><link rel="stylesheet" href="/css/csms-portal.css?v=20261026-light"></head><body>
<div class="login-overlay" id="authScreen"><div class="login-box">
<div class="brand" style="border:0;padding:0;color:#102b55"><img class="brand-logo" src="/images/company-logo.png" alt="Logo PT Sustainability"><div><strong>CSMS PT Sustainability</strong><small style="color:#64748b">Portal Kontraktor & Keselamatan</small></div></div>
<h1 id="authTitle">Masuk ke aplikasi</h1><p id="authDesc">Gunakan akun sesuai peran Anda.</p>
@if(auth()->check() && auth()->user()->hasModule('csms'))<form method="post" action="{{ route('csms.internal') }}">@csrf<button type="submit">Masuk dengan akun SILESTARI: {{ auth()->user()->name }}</button></form>@endif<div class="login-tabs"><button id="tabLogin" class="selected" type="button" onclick="showAuth('login')">Login</button><button id="tabRegister" class="secondary" type="button" onclick="showAuth('register')">Pendaftaran Akun</button></div>
<form id="authForm"><div class="field"><label>Akun / Email</label><input id="authUser" autocomplete="username" required placeholder="nama@perusahaan.co.id"></div>
<div class="field" style="margin-top:12px"><label>Password</label><input id="authPass" type="password" autocomplete="current-password" required minlength="12" placeholder="Minimal 12 karakter"></div>
<div id="registerFields" class="hide"><div class="field" style="margin-top:12px"><label>Nama lengkap</label><input id="authName" placeholder="Nama pengguna"></div>
<div class="field" style="margin-top:12px"><label>Peran akun (centang semua yang sesuai)</label><div id="authRoleOptions" class="role-options"><label class="role-option"><input type="checkbox" name="authRole" value="vendor" checked> <span>Vendor</span></label></div><div class="role-help">Pendaftaran mandiri hanya untuk akun Vendor. Akun Verifikator, Validator, Bagian Pengadaan, dan Admin harus disiapkan oleh administrator sistem.</div></div>
<div class="note small">Akun internal (Verifikator, Validator, Pengadaan, dan Admin) tidak dapat dibuat melalui pendaftaran mandiri. Gunakan akun internal yang disiapkan administrator.</div></div>
<div id="authError" class="login-error"></div><button id="authSubmit" type="submit" style="width:100%;margin-top:16px">Masuk</button></form>
<div class="footer">Akun internal disiapkan oleh administrator sistem.</div></div></div>
<div class="app">
<aside class="side"><div class="brand"><img class="logo" src="/images/company-logo.png" alt="Logo PT Sustainability"><div><strong>CSMS PT Sustainability</strong><small>Contractor HSE Portal</small></div></div>
<div class="nav" id="nav">
<button class="active" data-page="dashboard">▦ &nbsp; Dashboard</button>
<button data-page="vendors">▤ &nbsp; Data Penyedia</button>
<button data-page="register">＋ &nbsp; Pendaftaran Vendor</button>
<button data-page="questionnaire">☑ &nbsp; Pra-Kualifikasi</button>
<button data-page="verification">⌕ &nbsp; Verifikasi Dokumen</button>
<button data-page="validation">✓ &nbsp; Validasi Lapangan</button>
<button data-page="risk">◈ &nbsp; Hasil & Lingkup Risiko</button>
<button data-page="accounts">⚙ &nbsp; Kelola Akun Internal</button>
</div><div class="sidefoot">PT Sentral Sustainability Consulting<br>Sustainability · Keselamatan · Lingkungan</div></aside>
<main class="main">@if(auth()->check() && auth()->user()->hasModule('csms'))<div class="csms-returnbar"><a href="{{ route('dashboard') }}">← Kembali ke SILESTARI</a><a href="{{ route('dashboard',['view'=>'csms','legacy'=>1]) }}">Catatan CSMS lama</a></div>@endif<header class="top"><div><strong id="topTitle">Dashboard</strong><br><small>Contractor Health Safety Environment Management System</small></div><div class="row"><span class="tag">CSMS</span><small id="whoami">Belum login</small><button class="secondary" onclick="changePassword()">Ganti Password</button><button class="secondary" onclick="logout()">Keluar</button><button class="secondary" onclick="exportData()">Ekspor Data</button></div></header>
<div class="content" id="view"></div></main></div>
<script src="/js/browser-compat.js"></script><script src="/js/csms-portal.js?v=20261026" defer></script></body></html>

@extends('layouts.app')
@section('content')
<section class="card"><h1>Ganti password</h1><form method="post" action="{{ route('password.update') }}">@csrf @method('put')
<label>Password saat ini<input type="password" name="current_password" required autocomplete="current-password"></label>
<label>Password baru<input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
<label>Ulangi password baru<input type="password" name="password_confirmation" required minlength="12" autocomplete="new-password"></label>
<p>Minimal 12 karakter, mengandung huruf dan angka.</p><button type="submit">Simpan password</button><a href="{{ route('dashboard') }}">Kembali</a></form></section>
@endsection

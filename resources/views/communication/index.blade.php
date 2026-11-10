@extends('layouts.workspace')
@section('title','Komunikasi, Konsultasi dan Partisipasi · SILESTARI')
@section('content')
<link rel="stylesheet" href="{{ asset('css/communication.css') }}?v=20261026-print2">
<div class="card"><div class="comm-toolbar"><h2>Matriks komunikasi tahunan</h2><a href="{{ route('dashboard',['view'=>'communication','legacy'=>1]) }}">Catatan komunikasi lama</a></div>
<p>Kelola sumber informasi, materi komunikasi, konsultasi dan partisipasi beserta bukti pelaksanaannya.</p>
<div class="tablewrap"><table><thead><tr><th>Tahun</th><th>Departemen</th><th>Terakhir diperbarui</th><th>Aksi</th></tr></thead><tbody>
@forelse($matrices as $matrix)
<tr><td>{{ $matrix->year }}</td><td>{{ $matrix->department }}</td><td>{{ $matrix->updated_at->timezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB</td><td><a href="{{ route('communication.show',$matrix) }}">Buka matriks</a></td></tr>
@empty
<tr><td colspan="4">Belum ada matriks tahunan. Buat matriks dari contoh atau mulai dengan matriks kosong.</td></tr>
@endforelse
</tbody></table></div>{{ $matrices->links() }}</div>
<div class="card"><h2>Buat matriks tahunan</h2><form method="post" action="{{ route('communication.store') }}">@csrf
<div class="formgrid"><label class="field">Tahun<input type="number" name="year" min="2000" max="2100" value="{{ old('year',now()->timezone('Asia/Jakarta')->year) }}" required></label>
<label class="field">Departemen<input name="department" maxlength="120" value="{{ old('department',auth()->user()->department ?: 'HSE') }}" @readonly(!auth()->user()->isAdmin()) required></label>
<label class="field">Isi awal<select name="template"><option value="1">Gunakan contoh: 18 sumber dan 33 baris materi</option><option value="0">Matriks kosong</option></select></label></div>
<button class="primary" type="submit">Buat matriks</button></form></div>
@endsection

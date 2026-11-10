@extends('layouts.workspace')
@section('title','Kebijakan Perusahaan · SILESTARI')
@section('content')
@if($selected)
<div class="card"><div class="pagehead"><div><h2>{{ $selected->title }}</h2><p>Versi {{ $selected->version }} · {{ $selected->version===$latest?->version?'Berlaku saat ini':'Riwayat versi sebelumnya' }}<small>{{ $selected->original_name }} · {{ number_format($selected->size/1024,0,',','.') }} KB</small></p></div><div class="toolbar"><a class="button" href="{{ route('policy.file',$selected) }}" target="_blank" rel="noopener">Buka dokumen</a><a class="button" href="{{ route('policy.file',[$selected,'download'=>1]) }}">Unduh</a></div></div>
@if($selected->mime==='application/pdf')
<object class="policy-preview" data="{{ route('policy.file',$selected) }}" type="application/pdf"><p>Pratinjau PDF tidak tersedia di browser ini. <a href="{{ route('policy.file',$selected) }}" target="_blank" rel="noopener">Buka dokumen PDF</a>.</p></object>
@else
<img class="policy-image" src="{{ route('policy.file',$selected) }}" alt="{{ $selected->title }} — versi {{ $selected->version }}">
@endif
<p><strong>Perubahan:</strong> {{ $selected->change_summary }}</p><small>Diunggah oleh {{ $selected->uploaded_by_name }} · {{ $selected->created_at->timezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB</small></div>
@else
<div class="card"><h2>Belum ada lampiran kebijakan</h2><p>Unggah dokumen untuk menampilkan kebijakan perusahaan.</p></div>
@endif
<div class="card"><h2>{{ $latest?'Unggah versi kebijakan baru':'Unggah dokumen kebijakan' }}</h2><p>Format PDF, JPG atau PNG, maksimal 10 MB. Setiap unggahan menjadi versi baru; dokumen sebelumnya tetap tersimpan.</p>
<form id="policy-upload" data-version="{{ $revision }}"><div class="formgrid"><label class="field">Judul dokumen<input name="title" required maxlength="200" value="{{ $latest?->title ?? 'Kebijakan Perusahaan' }}"></label><label class="field">Lampiran dokumen<input name="document" type="file" accept="application/pdf,image/jpeg,image/png,.pdf,.jpg,.jpeg,.png" required></label><label class="field wide">Keterangan perubahan<textarea name="change_summary" required maxlength="3000" rows="3" placeholder="Contoh: Revisi komitmen perusahaan dan pengesahan terbaru"></textarea></label></div><p id="policy-progress" role="status" aria-live="polite"></p><button class="primary policy-upload-submit" type="submit">{{ $latest?'Simpan versi baru':'Simpan kebijakan' }}</button></form></div>
<div class="card"><h2>Riwayat perubahan</h2><div class="tablewrap"><table><thead><tr><th>Versi</th><th>Dokumen</th><th>Perubahan</th><th>Tanggal unggah</th><th>Diunggah oleh</th><th>Aksi</th></tr></thead><tbody>@forelse($history as $doc)<tr><td>{{ $doc->version }}<small>{{ $doc->id===$latest?->id?'Saat ini':'Riwayat' }}</small></td><td>{{ $doc->title }}<small>{{ $doc->original_name }}</small></td><td>{{ $doc->change_summary }}</td><td>{{ $doc->created_at->timezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB</td><td>{{ $doc->uploaded_by_name }}</td><td><div class="toolbar" style="margin:0"><a class="button" href="{{ route('policy.index',['version'=>$doc->version]) }}">Tampilkan</a><form method="post" action="{{ route('policy.destroy',$doc) }}" onsubmit="return confirm('Hapus dokumen kebijakan versi {{ $doc->version }}? Dokumen juga akan dihapus dari daftar riwayat.');">@csrf @method('delete')<button type="submit" style="color:#b42318;border-color:#efb8b2">Hapus</button></form></div></td></tr>@empty<tr><td colspan="6">Belum ada riwayat perubahan.</td></tr>@endforelse</tbody></table></div>{{ $history->links() }}</div>
<script src="{{ asset('js/policy-documents.js') }}?v=20261030-audit2" defer></script>
@endsection

@extends('layouts.workspace')
@section('title','Matriks Komunikasi '.$matrix->year.' · SILESTARI')
@section('content')
<link rel="stylesheet" href="{{ asset('css/communication.css') }}?v=20261026-print2"><script src="{{ asset('js/communication.js') }}?v=20261026-print2" defer></script>
<div id="comm-root" data-action-url="{{ route('communication.action',$matrix) }}" data-upload-url="{{ route('communication.chunks',$matrix) }}" data-revision="{{ $matrix->revision }}">
<div id="comm-message" role="status" hidden></div>
<div class="card comm-screen"><div class="comm-toolbar"><a href="{{ route('communication.index') }}">← Daftar tahun</a><div><button type="button" data-comm-print>Cetak / Simpan PDF</button><button type="button" data-comm-copy>Salin ke {{ $matrix->year+1 }}</button></div></div>
<p>Perubahan isi atau bukti akan mengosongkan TTD agar ditandatangani ulang pada versi terbaru.</p>
<details><summary>Identitas dokumen & akun TTD</summary><form data-comm-action="metadata"><div class="formgrid">
<label class="field">Nomor dokumen<input name="number" maxlength="120" value="{{ $payload['number'] }}"></label><label class="field">Revisi dokumen<input name="document_revision" maxlength="60" value="{{ $payload['document_revision'] }}"></label><label class="field">Edisi<input name="edition" maxlength="120" value="{{ $payload['edition'] }}"></label>
@foreach($slots as $slot=>$label)
<label class="field">{{ $label }}<select name="{{ $slot }}" @required($slot==='prepared')><option value="">Pilih akun</option>@foreach($people as $person)<option value="{{ $person->id }}" @selected((int)($payload['signers'][$slot]??0)===$person->id)>{{ $person->name }} · {{ $person->username ?? $person->email }}</option>@endforeach</select></label>
@endforeach
</div><button class="primary">Simpan identitas & akun TTD</button></form></details></div>
<div class="card comm-paper"><div class="si-form-head comm-paper-header">
<img src="{{ $workspaceLogo }}" alt="Logo {{ $workspaceCompany['name'] ?? 'perusahaan' }}">
<div><h1>MATRIKS KOMUNIKASI, KONSULTASI DAN PARTISIPASI</h1>
<p>No. Dokumen: <b>{{ $payload['number'] ?: '....................' }}</b> &nbsp; Rev.: <b>{{ $payload['document_revision'] ?: '..........' }}</b> &nbsp; Edisi: <b>{{ $payload['edition'] ?: '....................' }}</b></p>
<small>{{ $workspaceCompany['name'] ?? '' }} · Tahun {{ $matrix->year }} · {{ $matrix->department }}</small>
<small>Diperbarui {{ $matrix->updated_at->timezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB</small></div></div>
<div class="comm-screen comm-toolbar"><button type="button" class="primary" data-comm-source>+ Tambah sumber informasi</button><label class="field">Cari sumber / materi<input id="comm-search" type="search" placeholder="Ketik untuk mencari…"></label></div>
<div class="comm-table-scroll"><table class="comm-table"><thead><tr><th>No.</th><th>Sumber informasi</th>@foreach($fields as $key=>$label)<th>{{ $label }}</th>@endforeach<th class="comm-screen">Aksi</th></tr></thead><tbody>
@forelse($payload['sources'] as $source)
@php($rows=array_values(array_filter($payload['materials'],fn($r)=>$r['source_id']===$source['id'])))
@php($sourceIndex=$loop->iteration)
@php($groupSearch=mb_strtolower($source['name'].' '.implode(' ',array_column($rows,'title'))))
@foreach($rows ?: [null] as $row)
<tr data-comm-row data-search="{{ $groupSearch }}">
<!-- source group -->
@if($loop->first)
<td rowspan="{{ max(1,count($rows)) }}" class="comm-number">{{ $sourceIndex }}</td><td rowspan="{{ max(1,count($rows)) }}" class="comm-source-cell"><strong>{{ $source['name'] }}</strong><div class="comm-screen comm-source-actions"><button type="button" data-comm-source="{{ $source['id'] }}" data-name="{{ $source['name'] }}">Ubah sumber</button><button type="button" class="danger" data-comm-delete="delete_source" data-id="{{ $source['id'] }}">Hapus sumber</button><button type="button" data-comm-add-material="{{ $source['id'] }}">+ Materi</button></div></td>
@endif
@foreach($fields as $key=>$label)
<td class="comm-cell-{{ $key }}"><div class="comm-text">{{ $row[$key]??'' }}</div>
@if($key==='control_document' && $row)
@foreach($evidence->get($row['id'],collect()) as $file)
<div class="comm-evidence"><a target="_blank" rel="noopener" href="{{ route('communication.file',[$matrix,$file]) }}">{{ $file->original_name }}</a><small>{{ $file->uploaded_by_name }} · {{ $file->created_at->timezone('Asia/Jakarta')->format('d/m/Y') }}</small><button type="button" class="danger comm-screen" data-comm-delete="delete_evidence" data-id="{{ $file->id }}">Hapus bukti</button></div>
@endforeach
<label class="comm-screen comm-upload">+ Unggah bukti<input type="file" accept="application/pdf,image/png,image/jpeg" data-comm-upload="{{ $row['id'] }}" multiple></label>
<small class="comm-screen">PDF/JPG/PNG · maksimal 10 MB/file</small>
@endif
</td>
@endforeach
<td class="comm-screen">@if($row)<button type="button" data-comm-add-material="{{ $source['id'] }}">+ Tambah materi</button><button type="button" data-comm-edit-material="{{ $row['id'] }}">Ubah materi</button><button type="button" class="danger" data-comm-delete="delete_material" data-id="{{ $row['id'] }}">Hapus materi</button>@else<button type="button" data-comm-add-material="{{ $source['id'] }}">+ Tambah materi</button>@endif</td>
</tr>
@endforeach
@empty
<tr><td colspan="12">Belum ada sumber informasi. Gunakan tombol Tambah sumber informasi.</td></tr>
@endforelse
</tbody></table></div>
<div class="si-form-approval comm-approval"><table aria-label="Pengesahan matriks komunikasi"><thead><tr>
@foreach($slots as $slot=>$label)<th>{{ $label }}</th>@endforeach
</tr></thead><tbody><tr>
@foreach($slots as $slot=>$label)
@php($sig=$matrix->signatures[$slot]??null)
<td><div class="si-form-sign">@if($sig)<img src="{{ $sig['image'] }}" alt="TTD {{ $label }}">@endif</div>
<b>{{ $sig['name'] ?? $people->firstWhere('id',$payload['signers'][$slot]??null)?->name ?? '________________' }}</b><br>
Tanggal: {{ $sig ? \Illuminate\Support\Carbon::parse($sig['at'])->timezone('Asia/Jakarta')->format('d/m/Y H:i').' WIB' : '____________' }}
@if((int)($payload['signers'][$slot]??0)===auth()->id())<div class="comm-screen"><button type="button" data-comm-sign="{{ $slot }}">{{ $sig?'TTD ulang':'Tanda tangani' }}</button></div>@endif
</td>
@endforeach
</tr></tbody></table></div></div>
<div class="card comm-screen"><h2>Riwayat perubahan</h2><div class="tablewrap"><table><thead><tr><th>Waktu WIB</th><th>Akun</th><th>Perubahan</th><th>Versi data</th></tr></thead><tbody>@foreach($events as $event)<tr><td>{{ $event->created_at->timezone('Asia/Jakarta')->format('d/m/Y H:i') }}</td><td>{{ $event->snapshot['actor_name'] }}<br><small>{{ $event->snapshot['actor_username'] }}</small></td><td>{{ $event->action }}</td><td>{{ $event->revision }}</td></tr>@endforeach</tbody></table></div>{{ $events->links() }}</div>
<dialog id="comm-source-dialog"><form data-comm-action="add_source"><div class="comm-toolbar"><h2>Sumber informasi</h2><button type="button" data-comm-close>✕</button></div><input type="hidden" name="source_id"><label class="field">Nama sumber informasi<textarea name="name" maxlength="2000" required></textarea></label><button class="primary">Simpan sumber</button></form></dialog>
<dialog id="comm-material-dialog"><form data-comm-action="add_material"><div class="comm-toolbar"><h2>Materi komunikasi</h2><button type="button" data-comm-close>✕</button></div><input type="hidden" name="material_id"><label class="field">Sumber informasi<select name="source_id" required>@foreach($payload['sources'] as $source)<option value="{{ $source['id'] }}">{{ $source['name'] }}</option>@endforeach</select></label>
<div class="formgrid">@foreach($fields as $key=>$label)<label class="field">{{ $label }}@if($key==='criteria')<select name="criteria" required><option>Internal</option><option>Eksternal</option><option>Internal / Eksternal</option></select>@else<textarea name="{{ $key }}" maxlength="4000" rows="{{ $key==='title'?3:2 }}" @required($key==='title')></textarea>@endif</label>@endforeach</div><p>Unggah file bukti pada kolom Dokumen pengendalian setelah materi disimpan.</p><button class="primary">Simpan materi</button></form></dialog>
<dialog id="comm-sign-dialog"><form data-comm-action="sign"><div class="comm-toolbar"><h2>TTD akun {{ auth()->user()->name }}</h2><button type="button" data-comm-close>✕</button></div><input type="hidden" name="slot"><p>Gambar TTD atau unggah gambar PNG/JPG, lalu konfirmasikan password akun Anda.</p><canvas id="comm-sign-canvas" width="800" height="240"></canvas><button type="button" id="comm-clear-sign">Bersihkan TTD</button><label class="field">Atau unggah TTD<input id="comm-sign-upload" type="file" accept="image/png,image/jpeg"></label><img id="comm-sign-preview" alt="Pratinjau TTD" hidden><input type="hidden" name="signature"><label class="field">Password akun<input type="password" name="password" autocomplete="current-password" required></label><button class="primary">Simpan TTD</button></form></dialog>
<script id="comm-material-data" type="application/json">{!! json_encode($payload['materials'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) !!}</script>
</div>
@endsection

<?php

use App\Models\DocumentAttachment;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function dcFileUpload($test, array $data = [])
{
    $pdf = "%PDF-1.4\nLampiran dokumen\n%%EOF";

    return $test->postJson('/workspace/document-files/chunks', ['upload' => (string) Str::uuid(), 'index' => 0, 'total' => 1, 'data' => json_encode(['revision' => 0, 'payload' => ['kind' => 'pedoman', 'number' => 'PED-001', 'title' => 'Pedoman HSE', 'department' => 'HSE', 'file_name' => 'Pedoman.pdf', 'file_data' => 'data:application/pdf;base64,'.base64_encode($pdf), 'change_summary' => 'Dokumen baru', ...$data]])]);
}
test('Document Control lists four categories and attachment versions without changing procedures', function (): void {
    Storage::fake('local');
    $u = User::factory()->admin()->create();
    $this->actingAs($u)->withSession(['auth_version' => 1]);
    foreach (['procedure', 'pedoman', 'ik', 'form'] as $kind) {
        $this->get('/document-control?kind='.$kind)->assertOk()->assertSee('Pedoman')->assertSee('Prosedur')->assertSee('IK')->assertSee('Form / Standar');
    }
    dcFileUpload($this)->assertOk();
    $file = DocumentAttachment::firstOrFail();
    Storage::disk('local')->assertExists($file->path);
    $this->get(route('document-control.attachment.file', $file))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    dcFileUpload($this, ['document_id' => $file->id, 'change_summary' => 'Perubahan pedoman'])->assertOk();
    expect(DocumentAttachment::count())->toBe(2);
    expect(DocumentAttachment::latest('id')->first()->version)->toBe(2);
    $this->get('/document-control?kind=pedoman')->assertOk()->assertSee('Perubahan pedoman');
    $this->delete(route('document-control.attachment.destroy', $file))->assertRedirect();
    $this->get(route('document-control.attachment.file', $file))->assertNotFound();
});
test('attachment categories enforce module departments parent identity and MIME validation', function (): void {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->withSession(['auth_version' => 1]);
    dcFileUpload($this)->assertOk();
    $file = DocumentAttachment::firstOrFail();
    dcFileUpload($this, ['document_id' => $file->id, 'kind' => 'form'])->assertUnprocessable();
    dcFileUpload($this, ['number' => 'BAD', 'file_data' => 'data:application/pdf;base64,'.base64_encode('<html>bad</html>')])->assertUnprocessable();
    dcFileUpload($this, ['number' => 'BAD2', 'kind' => 'procedure'])->assertUnprocessable();
    dcFileUpload($this)->assertUnprocessable();
    expect(DocumentAttachment::count())->toBe(1);
    $outsider = User::factory()->departmentUser()->create(['department' => 'HRGA', 'allowed_modules' => ['documents']]);
    $this->actingAs($outsider)->withSession(['auth_version' => 1]);
    $this->get('/document-control?kind=pedoman')->assertOk()->assertDontSee('Pedoman HSE');
    $this->get(route('document-control.attachment.file', $file))->assertForbidden();
    $this->delete(route('document-control.attachment.destroy', $file))->assertForbidden();
    dcFileUpload($this)->assertForbidden();
    $denied = User::factory()->departmentUser()->create(['department' => 'HSE', 'allowed_modules' => ['company']]);
    $this->actingAs($denied)->withSession(['auth_version' => 1]);
    dcFileUpload($this)->assertForbidden();
});

test('Form Standar supports Excel attachments and rejects unrelated zip content', function (): void {
    Storage::fake('local');
    $user = User::factory()->admin()->create();
    $this->actingAs($user)->withSession(['auth_version' => 1]);
    $temp = tempnam(sys_get_temp_dir(), 'dc-test-');
    $zip = new ZipArchive;
    $zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook/>');
    $zip->close();
    $bytes = file_get_contents($temp);
    unlink($temp);
    dcFileUpload($this, ['kind' => 'form', 'number' => 'FR-001', 'file_name' => 'Form.xlsx', 'file_data' => 'data:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet;base64,'.base64_encode($bytes)])->assertOk();
    $file = DocumentAttachment::firstOrFail();
    $this->get(route('document-control.attachment.file', $file))->assertOk()->assertDownload('Form.xlsx');
    dcFileUpload($this, ['kind' => 'form', 'number' => 'FR-002', 'file_name' => 'Form.docx', 'file_data' => 'data:application/zip;base64,'.base64_encode($bytes)])->assertUnprocessable();
    expect(DocumentAttachment::count())->toBe(1);
});

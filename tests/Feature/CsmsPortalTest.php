<?php

namespace Tests\Feature;

use App\Models\CsmsAccount;
use App\Models\CsmsVendor;
use App\Models\User;
use App\Services\CsmsPortal;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CsmsPortalTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function user(string $role): CsmsAccount
    {
        return CsmsAccount::create(['name' => $role, 'email' => uniqid().'@example.test', 'password' => 'Testing12345!', 'roles' => [$role]]);
    }

    private function vendor(CsmsAccount $u, string $status = 'Menunggu Pengisian'): CsmsVendor
    {
        return CsmsVendor::create(['id' => 'RCH-'.uniqid(), 'owner_id' => $u->id, 'name' => 'PT Test', 'scope' => 'Pemeliharaan', 'status' => $status]);
    }

    private function scores(float $n): array
    {
        return DB::table('csms_questions')->get()->map(fn ($q) => ['question_id' => $q->id, 'score' => $n, 'comment' => 'Bukti diperiksa'])->all();
    }

    public function test_master_matches_mockup(): void
    {
        $this->assertEquals(49, DB::table('csms_questions')->count());
        $this->assertEquals(565, DB::table('csms_questions')->sum('max'));
        $this->assertEquals(49, DB::table('csms_questions')->count());
    }

    public function test_registration_does_not_allow_role_escalation(): void
    {
        $this->postJson('/csms/api/register', ['name' => 'Vendor', 'username' => 'new@example.test', 'password' => 'Testing12345!', 'role' => ['admin']])->assertCreated()->assertJsonPath('role.0', 'vendor');
        $this->postJson('/csms/api/accounts', [])->assertForbidden();
    }

    public function test_vendor_isolation_and_role_permissions(): void
    {
        $one = $this->user('vendor');
        $two = $this->user('vendor');
        $v = $this->vendor($one);
        $this->portalAs($two)->getJson('/csms/api/state')->assertJsonCount(0, 'vendors');
        $this->portalAs($two)->postJson('/csms/api/vendors/'.$v->id.'/answers', ['answers' => []])->assertForbidden();
        $this->portalAs($two)->postJson('/csms/api/vendors/'.$v->id.'/assessments/review', [])->assertForbidden();
    }

    public function test_complete_workflow_multi_reviewer_average_and_lock(): void
    {
        $u = $this->user('vendor');
        $v = $this->vendor($u);
        $answers = DB::table('csms_questions')->get()->map(fn ($q) => ['question_id' => $q->id, 'answer' => 'Ya', 'note' => 'Bukti'])->all();
        $this->portalAs($u)->postJson('/csms/api/vendors/'.$v->id.'/answers', ['answers' => $answers])->assertOk()->assertJsonPath('status', 'Menunggu Verifikasi Dokumen');
        $r1 = $this->user('verifikator');
        $r2 = $this->user('verifikator');
        $this->portalAs($r1)->postJson('/csms/api/vendors/'.$v->id.'/assessments/review', ['action' => 'save', 'scores' => $this->scores(1)])->assertOk();
        $this->portalAs($r2)->postJson('/csms/api/vendors/'.$v->id.'/assessments/review', ['action' => 'advance', 'scores' => $this->scores(.5)])->assertOk()->assertJsonPath('totals.review', 423.75);
        $this->portalAs($r1)->postJson('/csms/api/vendors/'.$v->id.'/assessments/review', ['action' => 'save', 'scores' => $this->scores(0)])->assertConflict();
        $a = $this->user('validator');
        $b = $this->user('validator');
        $this->portalAs($a)->postJson('/csms/api/vendors/'.$v->id.'/assessments/validation', ['action' => 'save', 'scores' => $this->scores(.5)])->assertOk();
        $this->portalAs($b)->postJson('/csms/api/vendors/'.$v->id.'/assessments/validation', ['action' => 'finalize', 'scores' => $this->scores(1), 'fieldDate' => '2026-10-14', 'fieldLocation' => 'Jakarta', 'fieldResult' => 'Sesuai'])->assertOk()->assertJsonPath('status', 'Tervalidasi')->assertJsonPath('finalRisk', 'Risiko Tinggi')->assertJsonPath('totals.validation', 423.75);
        $this->portalAs($a)->postJson('/csms/api/vendors/'.$v->id.'/assessments/validation', ['action' => 'save', 'scores' => $this->scores(0)])->assertConflict();
        $this->assertNotNull($v->fresh()->final_snapshot);
        $this->assertDatabaseHas('csms_audit_logs', ['action' => 'validation_finalize', 'vendor_id' => $v->id]);
    }

    public function test_invalid_score_and_wrong_stage_rejected(): void
    {
        $v = $this->vendor($this->user('vendor'));
        $a = $this->user('validator');
        $this->portalAs($a)->postJson('/csms/api/vendors/'.$v->id.'/assessments/validation', ['action' => 'save', 'scores' => $this->scores(.5)])->assertConflict();
        $this->portalAs($a)->postJson('/csms/api/vendors/'.$v->id.'/assessments/validation', ['action' => 'save', 'scores' => $this->scores(.7)])->assertUnprocessable();
    }

    public function test_correction_starts_fresh_assessment_round(): void
    {
        $u = $this->user('vendor');
        $v = $this->vendor($u, 'Menunggu Verifikasi Dokumen');
        $this->portalAs($this->user('verifikator'))->postJson('/csms/api/vendors/'.$v->id.'/assessments/review', ['action' => 'return', 'scores' => $this->scores(.25)])->assertOk();
        $answers = DB::table('csms_questions')->get()->map(fn ($q) => ['question_id' => $q->id, 'answer' => 'Tidak'])->all();
        $this->portalAs($u)->postJson('/csms/api/vendors/'.$v->id.'/answers', ['answers' => $answers])->assertOk();
        $this->assertDatabaseCount('csms_assessments', 0);
        $this->assertDatabaseHas('csms_audit_logs', ['action' => 'previous_round_archived']);
    }

    public function test_evidence_is_private_and_downloadable(): void
    {
        Storage::fake('local');
        $u = $this->user('vendor');
        $v = $this->vendor($u);
        $file = UploadedFile::fake()->createWithContent('bukti.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6NvEAAAAASUVORK5CYII='));
        $r = $this->portalAs($u)->post('/csms/api/vendors/'.$v->id.'/evidence', ['question_id' => '1.a', 'file' => $file], ['Accept' => 'application/json'])->assertOk();
        $id = $r->json('id');
        $this->portalAs($this->user('vendor'))->get('/csms/api/evidence/'.$id)->assertForbidden();
        $this->portalAs($this->user('verifikator'))->get('/csms/api/evidence/'.$id)->assertOk();
    }

    public function test_guest_and_inactive_user_are_blocked(): void
    {
        $this->getJson('/csms/api/state')->assertUnauthorized();
        $u = $this->user('vendor');
        $u->update(['active' => false]);
        $this->portalAs($u)->getJson('/csms/api/state')->assertUnauthorized();
    }

    public function test_internal_account_multi_roles_and_safe_deactivation(): void
    {
        $admin = $this->user('admin');
        $this->portalAs($admin)->postJson('/csms/api/accounts', ['username' => 'both@example.test', 'name' => 'Penilai', 'password' => 'Testing12345!', 'role' => ['verifikator', 'validator']])->assertOk()->assertJsonCount(2, 'role');
        $u = CsmsAccount::where('email', 'both@example.test')->first();
        $this->deleteJson('/csms/api/accounts/'.$u->id)->assertOk();
        $this->assertFalse($u->fresh()->active);
        $this->deleteJson('/csms/api/accounts/'.$admin->id)->assertUnprocessable();
    }

    public function test_risk_thresholds(): void
    {
        foreach ([[0, 'Tidak Lulus'], [34.99, 'Tidak Lulus'], [35, 'Risiko Rendah'], [50, 'Risiko Menengah'], [70, 'Risiko Tinggi'], [100, 'Risiko Tinggi']] as [$n,$category]) {
            $this->assertSame($category, CsmsPortal::category($n));
        }
    }

    public function test_missing_evidence_file_returns_not_found_without_recording_successful_download(): void
    {
        Storage::fake('local');
        $owner = $this->user('vendor');
        $vendor = $this->vendor($owner);
        $id = (string) Str::uuid();
        DB::table('csms_evidence')->insert(['id' => $id, 'vendor_id' => $vendor->id, 'question_id' => '1.a', 'uploaded_by' => $owner->id, 'name' => 'missing.pdf', 'path' => 'csms-evidence/missing.pdf', 'mime' => 'application/pdf', 'size' => 10, 'created_at' => now(), 'updated_at' => now()]);

        $this->portalAs($owner)->getJson('/csms/api/evidence/'.$id)->assertNotFound();
        $this->assertSame(0, DB::table('csms_audit_logs')->where('action', 'evidence_downloaded')->count());
        $this->portalAs($this->user('vendor'))->getJson('/csms/api/evidence/'.$id)->assertForbidden();
    }

    private function portalAs(CsmsAccount $actor): self
    {
        $this->withSession(['csms_account_id' => $actor->id, 'csms_auth_version' => $actor->auth_version]);

        return $this;
    }

    public function test_external_session_does_not_authenticate_to_silestari(): void
    {
        $this->postJson('/csms/api/register', ['name' => 'Vendor Eksternal', 'username' => 'external@example.test', 'password' => 'Testing12345!', 'role' => ['admin'], 'internal_user_id' => 1])->assertCreated()->assertHeader('X-CSRF-TOKEN');
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
        $this->getJson('/workspace')->assertUnauthorized();
        $this->getJson('/users')->assertUnauthorized();
        $this->getJson('/csms/api/state')->assertOk()->assertJsonPath('session.role.0', 'vendor');
    }

    public function test_internal_sso_uses_existing_user_without_changing_global_roles(): void
    {
        $u = User::factory()->admin()->create();
        $this->actingAs($u)->withSession(['auth_version' => $u->auth_version])->post('/csms/internal-session')->assertRedirect(route('csms.portal'));
        $this->getJson('/csms/api/state')->assertOk()->assertJsonPath('session.role.0', 'admin');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('csms_accounts', ['internal_user_id' => $u->id]);
        $this->postJson('/csms/api/logout')->assertOk();
        $this->assertAuthenticatedAs($u);
        $this->getJson('/csms/api/state')->assertUnauthorized();
    }

    public function test_only_csms_authorized_internal_users_can_use_sso(): void
    {
        $u = User::factory()->departmentUser()->create(['allowed_modules' => ['company']]);
        $this->actingAs($u)->withSession(['auth_version' => $u->auth_version])->post('/csms/internal-session')->assertForbidden();
        $this->assertDatabaseCount('csms_accounts', 0);
    }

    public function test_branding_is_neutral_in_portal_and_certificate_assets(): void
    {
        $this->get('/csms')->assertOk()->assertSee('PT Sentral Sustainability Consulting')->assertDontSee('Kereta Api')->assertDontSee('Logo KAI')->assertSee('/images/company-logo.png', false);
        foreach (['public/js/csms-portal.js', 'public/css/csms-portal.css', 'resources/templates/csms-questions.json'] as $file) {
            $text = file_get_contents(base_path($file));
            $this->assertDoesNotMatchRegularExpression('/\bKAI\b|Kereta Api|Railway|RCHSEMS/i', $text);
        }
    }

    public function test_chunk_evidence_is_scoped_to_vendor_and_question(): void
    {
        Storage::fake('local');
        $u = $this->user('vendor');
        $v = $this->vendor($u);
        $this->portalAs($u);
        $bytes = "%PDF-1.4\n".str_repeat('Bukti CSMS ', 50000)."\n%%EOF";
        $json = json_encode(['name' => 'bukti.pdf', 'base64' => base64_encode($bytes)]);
        $parts = str_split($json, 130000);
        $upload = (string) Str::uuid();
        foreach ($parts as $i => $part) {
            $response = $this->postJson('/csms/api/vendors/'.$v->id.'/evidence/chunks', ['upload' => $upload, 'index' => $i, 'total' => count($parts), 'question_id' => '1.a', 'data' => $part])->assertOk();
        }
        $this->assertDatabaseCount('csms_evidence', 1);
        $file = DB::table('csms_evidence')->first();
        Storage::disk('local')->assertExists($file->path);
        $this->get('/csms/api/evidence/'.$file->id)->assertOk();
        $other = $this->vendor($this->user('vendor'));
        $this->postJson('/csms/api/vendors/'.$other->id.'/evidence/chunks', ['upload' => (string) Str::uuid(), 'index' => 0, 'total' => 1, 'question_id' => '1.a', 'data' => '{}'])->assertForbidden();
    }

    public function test_csms_roles_change_never_changes_a_linked_silestari_user(): void
    {
        $admin = $this->user('admin');
        $u = User::factory()->departmentUser()->create(['allowed_modules' => ['csms'], 'department' => 'HSE']);
        $linked = CsmsAccount::create(['name' => $u->name, 'internal_user_id' => $u->id, 'username' => $u->username, 'password' => 'RandomTestingPassword123', 'roles' => ['pengadaan']]);
        $this->portalAs($admin)->putJson('/csms/api/accounts/'.$linked->id.'/roles', ['role' => ['verifikator', 'validator']])->assertOk()->assertJsonCount(2, 'role');
        $this->assertSame('user', $u->fresh()->role);
        $this->assertSame(['csms'], $u->fresh()->allowed_modules);
        $this->putJson('/csms/api/accounts/'.$linked->id.'/password', ['password' => 'NewTestingPassword123'])->assertForbidden();
    }
}

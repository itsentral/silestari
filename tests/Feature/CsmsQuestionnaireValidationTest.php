<?php

use App\Models\CsmsAccount;
use App\Models\CsmsVendor;
use Illuminate\Support\Facades\DB;

test('invalid vendor answers are rejected with 422 without submitting or changing answers', function (string $scenario, string $field): void {
    $actor = CsmsAccount::create(['name' => 'Vendor', 'email' => 'validation@example.test', 'password' => 'Testing12345!', 'roles' => ['vendor']]);
    $vendor = CsmsVendor::create(['id' => 'VALIDATION-TEST', 'owner_id' => $actor->id, 'name' => 'PT Test', 'scope' => 'Pemeliharaan', 'status' => 'Menunggu Pengisian']);
    $answers = DB::table('csms_questions')->orderBy('id')->get()->map(fn ($q) => ['question_id' => $q->id, 'answer' => 'Ya', 'note' => 'Bukti diperiksa'])->all();
    switch ($scenario) {
        case 'duplicate': $answers[1]['question_id'] = $answers[0]['question_id'];
            break;
        case 'unknown': $answers[0]['question_id'] = 'not-a-question';
            break;
        case 'invalid': $answers[0]['answer'] = 'Mungkin';
            break;
        case 'missing_answer': unset($answers[0]['answer']);
            break;
        case 'missing_id': unset($answers[0]['question_id']);
            break;
        case 'scalar': $answers[0] = 'not-an-answer';
            break;
        case 'long_note': $answers[0]['note'] = str_repeat('a', 5001);
            break;
        case 'array_note': $answers[0]['note'] = ['invalid'];
            break;
    }
    $this->withSession(['csms_account_id' => $actor->id, 'csms_auth_version' => $actor->auth_version]);

    $this->postJson('/csms/api/vendors/'.$vendor->id.'/answers', ['answers' => $answers])->assertUnprocessable()->assertJsonValidationErrors($field);

    expect($vendor->fresh()->status)->toBe('Menunggu Pengisian');
    $this->assertDatabaseCount('csms_answers', 0);
    $this->assertDatabaseMissing('csms_audit_logs', ['vendor_id' => $vendor->id, 'action' => 'questionnaire_submitted']);
})->with([
    ['duplicate', 'answers.1.question_id'],
    ['unknown', 'answers.0.question_id'],
    ['invalid', 'answers.0.answer'],
    ['missing_answer', 'answers.0.answer'],
    ['missing_id', 'answers.0.question_id'],
    ['scalar', 'answers.0'],
    ['long_note', 'answers.0.note'],
    ['array_note', 'answers.0.note'],
]);

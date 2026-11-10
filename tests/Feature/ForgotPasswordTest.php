<?php

use App\Http\Controllers\ForgotPasswordController;
use App\Models\User;
use App\Notifications\ResetSilestariPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

function recoveryReady(): void
{
    config(['password-recovery.enabled' => true, 'password-recovery.url' => 'https://silestari.example', 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.test.local', 'mail.from.address' => 'noreply@company.test']);
}
test('login includes forgot password and SMTP is explicitly disabled until configured', function (): void {
    Notification::fake();
    User::factory()->admin()->create();
    $this->get('/login')->assertOk()->assertSee('Lupa password?');
    $this->get('/forgot-password')->assertOk()->assertSee('Email terdaftar');
    config(['password-recovery.enabled' => false]);
    $this->from('/forgot-password')->post('/forgot-password', ['email' => 'staff@company.test'])->assertRedirect('/forgot-password')->assertSessionHasErrors('email');
    Notification::assertNothingSent();
    $this->assertDatabaseCount('password_reset_tokens', 0);
});
test('known active account gets one hashed reset token and branded fixed-origin email', function (): void {
    recoveryReady();
    Notification::fake();
    $u = User::factory()->admin()->create(['email' => 'staff@company.test']);
    $this->from('/forgot-password')->post('/forgot-password', ['email' => 'STAFF@company.test'])->assertRedirect('/forgot-password')->assertSessionHas('status', ForgotPasswordController::GENERIC_STATUS);
    Notification::assertSentTo($u, ResetSilestariPassword::class, function ($n) use ($u) {
        $mail = $n->toMail($u);
        expect($mail->subject)->toBe('Reset password SILESTARI');
        expect($mail->actionUrl)->toStartWith('https://silestari.example/reset-password/');
        expect(DB::table('password_reset_tokens')->value('token'))->not->toBe($n->token);

        return true;
    });
    $this->post('/forgot-password', ['email' => 'staff@company.test'])->assertSessionHas('status', ForgotPasswordController::GENERIC_STATUS);
    Notification::assertSentToTimes($u, ResetSilestariPassword::class, 1);
});
test('unknown and inactive accounts have the same response without email or token', function (): void {
    recoveryReady();
    Notification::fake();
    $u = User::factory()->create(['email' => 'disabled@company.test']);
    $u->forceFill(['is_active' => false])->save();
    foreach (['unknown@company.test', 'disabled@company.test'] as $email) {
        $this->post('/forgot-password', ['email' => $email])->assertSessionHas('status', ForgotPasswordController::GENERIC_STATUS);
    }
    Notification::assertNothingSent();
    $this->assertDatabaseCount('password_reset_tokens', 0);
});
test('valid reset changes password clears legacy credentials revokes sessions and consumes token once', function (): void {
    $u = User::factory()->admin()->create(['email' => 'staff@company.test', 'password' => 'OldPassword123', 'legacy_password_hash' => 'old-verifier', 'legacy_password_salt' => 'old-salt']);
    $token = Password::broker()->createToken($u);
    DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $u->id, 'payload' => '', 'last_activity' => time()]);
    $this->get('/reset-password/'.$token.'?email=staff%40company.test')->assertOk()->assertSee('Buat password baru');
    $body = ['email' => $u->email, 'token' => $token, 'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123'];
    $this->post('/reset-password', $body)->assertRedirect('/login')->assertSessionHas('status');
    $u->refresh();
    expect(Hash::check('NewPassword123', $u->password))->toBeTrue();
    expect($u->legacy_password_hash)->toBeNull();
    expect($u->legacy_password_salt)->toBeNull();
    expect($u->auth_version)->toBe(2);
    expect($u->role)->toBe('admin');
    $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
    $this->assertDatabaseCount('password_reset_tokens', 0);
    $this->post('/reset-password', $body)->assertSessionHasErrors('email');
    $this->assertGuest();
    $this->post('/login', ['email' => $u->email, 'password' => 'NewPassword123'])->assertRedirect('/dashboard');
});
test('wrong expired and inactive reset tokens never change passwords', function (): void {
    $this->freezeTime();
    $u = User::factory()->admin()->create(['email' => 'staff@company.test', 'password' => 'OldPassword123']);
    $token = Password::broker()->createToken($u);
    $body = ['email' => $u->email, 'token' => str_repeat('x', 64), 'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123'];
    $this->post('/reset-password', $body)->assertSessionHasErrors('email');
    expect(Hash::check('OldPassword123', $u->fresh()->password))->toBeTrue();
    $this->travel(61)->minutes();
    $this->post('/reset-password', [...$body, 'token' => $token])->assertSessionHasErrors('email');
    expect(Hash::check('OldPassword123', $u->fresh()->password))->toBeTrue();
    $new = Password::broker()->createToken($u);
    $u->forceFill(['is_active' => false])->save();
    $this->post('/reset-password', [...$body, 'token' => $new])->assertSessionHasErrors('email');
    expect(Hash::check('OldPassword123', $u->fresh()->password))->toBeTrue();
});
test('reset requires confirmed strong passwords and enforces bcrypt byte limit', function (): void {
    $u = User::factory()->admin()->create(['email' => 'staff@company.test']);
    $token = Password::broker()->createToken($u);
    foreach ([['Short1', 'Short1'], ['LongPassword123', 'DifferentPassword123'], [str_repeat('é', 37).'1', str_repeat('é', 37).'1']] as [$password,$confirmation]) {
        $this->post('/reset-password', ['email' => $u->email, 'token' => $token, 'password' => $password, 'password_confirmation' => $confirmation])->assertSessionHasErrors('password');
    }
    expect(Password::broker()->tokenExists($u,$token))->toBeTrue();
});

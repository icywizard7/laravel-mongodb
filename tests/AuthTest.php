<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Tests;

use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Laravel\Tests\Models\User;

use function bcrypt;

class AuthTest extends TestCase
{
    public function tearDown(): void
    {
        parent::setUp();

        User::truncate();
        DB::table('password_reset_tokens')->truncate();
    }

    public function testAuthAttempt()
    {
        User::create([
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'password' => Hash::make('foobar'),
        ]);

        $this->assertTrue(Auth::attempt(['email' => 'john.doe@example.com', 'password' => 'foobar'], true));
        $this->assertTrue(Auth::check());
    }

    public function testRemindOld()
    {
        $broker = $this->app->make('auth.password.broker');

        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'password' => Hash::make('foobar'),
        ]);

        $token = null;

        $this->assertSame(
            PasswordBroker::RESET_LINK_SENT,
            $broker->sendResetLink(
                ['email' => 'john.doe@example.com'],
                function ($actualUser, $actualToken) use ($user, &$token) {
                    $this->assertEquals($user->_id, $actualUser->_id);
                    // Store token for later use
                    $token = $actualToken;
                },
            ),
        );

        $this->assertEquals(1, DB::table('password_reset_tokens')->count());
        $reminder = DB::table('password_reset_tokens')->first();
        $this->assertEquals('john.doe@example.com', $reminder['email']);
        $this->assertNotNull($reminder['token']);
        $this->assertInstanceOf(UTCDateTime::class, $reminder['created_at']);

        $credentials = [
            'email' => 'john.doe@example.com',
            'password' => 'foobar',
            'password_confirmation' => 'foobar',
            'token' => $token,
        ];

        $response = $broker->reset($credentials, function ($user, $password) {
            $user->password = bcrypt($password);
            $user->save();
        });

        $this->assertEquals('passwords.reset', $response);
        $this->assertEquals(0, DB::table('password_resets')->count());
    }
}

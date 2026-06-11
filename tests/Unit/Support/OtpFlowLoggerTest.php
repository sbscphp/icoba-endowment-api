<?php

namespace Tests\Unit\Support;

use App\Support\OtpFlowLogger;
use Illuminate\Http\Request;
use Tests\TestCase;

class OtpFlowLoggerTest extends TestCase
{
    protected function tearDown(): void
    {
        config(['security.otp_flow_debug' => false]);

        parent::tearDown();
    }

    public function test_request_meta_excludes_secrets(): void
    {
        $request = Request::create('/api/v1/auth/login', 'POST', [
            'email' => 'user@example.com',
            'password' => 'secret-password',
            'client' => 'mobile',
            'otp' => '123456',
            'challenge_token' => str_repeat('a', 40),
        ]);

        $meta = OtpFlowLogger::requestMeta($request);

        $this->assertSame('user@example.com', $meta['email']);
        $this->assertSame('mobile', $meta['client']);
        $this->assertArrayHasKey('token_fp', $meta);
        $this->assertArrayHasKey('otp_len', $meta);
        $this->assertArrayNotHasKey('password', $meta);
        $this->assertArrayNotHasKey('otp', $meta);
        $this->assertArrayNotHasKey('challenge_token', $meta);
    }

    public function test_auth_payload_meta_includes_otp_channel(): void
    {
        $meta = OtpFlowLogger::authPayloadMeta([
            'otp_channel' => 'sms',
            'otp_purpose' => 'LOGIN',
            'expires_in' => 300,
            'cooldown_active' => false,
        ]);

        $this->assertSame('sms', $meta['otp_channel']);
        $this->assertSame('LOGIN', $meta['otp_purpose']);
        $this->assertSame(300, $meta['expires_in']);
        $this->assertSame('no', $meta['cooldown_active']);
    }
}

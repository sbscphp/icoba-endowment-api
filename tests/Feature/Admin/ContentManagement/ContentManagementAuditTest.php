<?php

namespace Tests\Feature\Admin\ContentManagement;

use App\Enums\AuditActionEnum;
use App\Enums\ModuleEnums;
use App\Models\Ad;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Faq;
use App\Models\HeroSlide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Tests\TestCase;

class ContentManagementAuditTest extends TestCase
{
    use RefreshDatabase;

    private const CM = '/api/v1/admin/content-management';

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([ThrottleRequests::class, PermissionMiddleware::class]);

        $this->admin = Admin::query()->create([
            'name' => 'Content Admin',
            'email' => 'cm-audit@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
            'can_login' => true,
            '2fa' => false,
        ]);

        Sanctum::actingAs($this->admin);
    }

    public function test_faq_lifecycle_is_audited(): void
    {
        $this->postJson(self::CM.'/faqs', ['title' => 'Q1', 'content' => 'A1'])->assertOk();
        $id = Faq::query()->value('uuid');

        $this->patchJson(self::CM.'/faqs/'.$id, ['title' => 'Q1 edited'])->assertOk();
        $this->patchJson(self::CM.'/faqs/'.$id, ['title' => 'Q1 edited'])->assertOk(); // no change: not audited
        $this->patchJson(self::CM.'/faqs/'.$id.'/toggle-status')->assertOk();
        $this->deleteJson(self::CM.'/faqs/'.$id)->assertOk();

        $this->assertActions([
            AuditActionEnum::FAQ_CREATED,
            AuditActionEnum::FAQ_UPDATED,
            AuditActionEnum::FAQ_STATUS_TOGGLED,
            AuditActionEnum::FAQ_DELETED,
        ]);

        $update = $this->log(AuditActionEnum::FAQ_UPDATED);
        $this->assertSame(['title'], $update->metadata['data']['changed_fields']);
        $this->assertSame('Q1', $update->metadata['data']['previous']['title']);
        $this->assertSame('inactive', $this->log(AuditActionEnum::FAQ_STATUS_TOGGLED)->metadata['data']['new_status']);
        $this->assertSame('A1', $this->log(AuditActionEnum::FAQ_DELETED)->metadata['data']['content']);
    }

    public function test_hero_slide_lifecycle_is_audited(): void
    {
        $this->postJson(self::CM.'/hero-slides', [
            'title' => 'Slide',
            'banner_url' => 'https://cdn.example.com/banner.jpg',
            'primary_cta_url' => 'https://example.com/a',
            'primary_cta_text' => 'Give',
            'secondary_cta_url' => 'https://example.com/b',
            'secondary_cta_text' => 'Learn',
        ])->assertOk();
        $id = HeroSlide::query()->value('uuid');

        $this->patchJson(self::CM.'/hero-slides/'.$id, ['title' => 'Slide 2'])->assertOk();
        $this->patchJson(self::CM.'/hero-slides/'.$id.'/toggle-status')->assertOk();
        $this->deleteJson(self::CM.'/hero-slides/'.$id)->assertOk();

        $this->assertActions([
            AuditActionEnum::HERO_SLIDE_CREATED,
            AuditActionEnum::HERO_SLIDE_UPDATED,
            AuditActionEnum::HERO_SLIDE_STATUS_TOGGLED,
            AuditActionEnum::HERO_SLIDE_DELETED,
        ]);
    }

    public function test_ad_lifecycle_is_audited(): void
    {
        $this->postJson(self::CM.'/ads', [
            'title' => 'Ad',
            'starts_at' => '2026-10-01 00:00:00',
            'ends_at' => '2026-10-31 00:00:00',
            'images' => ['https://cdn.example.com/1.jpg'],
        ])->assertOk();
        $id = Ad::query()->value('uuid');

        $this->patchJson(self::CM.'/ads/'.$id, ['images' => ['https://cdn.example.com/1.jpg', 'https://cdn.example.com/2.jpg']])->assertOk();
        $this->patchJson(self::CM.'/ads/'.$id.'/archive')->assertOk();
        $this->patchJson(self::CM.'/ads/'.$id.'/reactivate')->assertOk();
        $this->patchJson(self::CM.'/ads/settings', ['ads_transition_seconds' => 9])->assertOk();
        $this->deleteJson(self::CM.'/ads/'.$id)->assertOk();

        $this->assertActions([
            AuditActionEnum::AD_CREATED,
            AuditActionEnum::AD_UPDATED,
            AuditActionEnum::AD_ARCHIVED,
            AuditActionEnum::AD_REACTIVATED,
            AuditActionEnum::AD_SETTINGS_UPDATED,
            AuditActionEnum::AD_DELETED,
        ]);

        $update = $this->log(AuditActionEnum::AD_UPDATED);
        $this->assertSame(['https://cdn.example.com/1.jpg'], $update->metadata['data']['previous_images']);
        $this->assertCount(2, $update->metadata['data']['new_images']);
    }

    public function test_event_lifecycle_is_audited(): void
    {
        $this->postJson('/api/v1/admin/events', [
            'title' => 'Gala',
            'short_description' => 'Short',
            'long_description' => 'Long',
            'event_date' => '2026-11-01',
            'banner' => 'https://cdn.example.com/gala.jpg',
        ])->assertOk();
        $id = Event::query()->value('uuid');

        $this->patchJson('/api/v1/admin/events/'.$id.'/status', ['status' => 'published'])->assertOk();
        $this->deleteJson('/api/v1/admin/events/'.$id)->assertOk();

        $this->assertActions([
            AuditActionEnum::EVENT_CREATED,
            AuditActionEnum::EVENT_STATUS_CHANGED,
            AuditActionEnum::EVENT_DELETED,
        ]);

        $status = $this->log(AuditActionEnum::EVENT_STATUS_CHANGED);
        $this->assertSame('draft', $status->metadata['data']['previous_status']);
        $this->assertSame('published', $status->metadata['data']['new_status']);
    }

    /**
     * @param  list<AuditActionEnum>  $expected
     */
    private function assertActions(array $expected): void
    {
        $logs = AuditLog::query()->orderBy('id')->get();

        $this->assertSame(
            array_map(fn (AuditActionEnum $a) => $a->value, $expected),
            $logs->map(fn (AuditLog $log) => $log->action instanceof AuditActionEnum ? $log->action->value : $log->action)->all(),
        );

        foreach ($logs as $log) {
            $this->assertSame($this->admin->uuid, $log->user_id);
            $module = $log->action_module instanceof ModuleEnums ? $log->action_module->value : $log->action_module;
            $this->assertSame(ModuleEnums::content_management->value, $module);
        }
    }

    private function log(AuditActionEnum $action): AuditLog
    {
        return AuditLog::query()->where('action', $action->value)->firstOrFail();
    }
}

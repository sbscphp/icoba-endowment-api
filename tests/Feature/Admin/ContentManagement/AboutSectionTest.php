<?php

namespace Tests\Feature\Admin\ContentManagement;

use App\Enums\AuditActionEnum;
use App\Models\AboutSection;
use App\Models\Admin;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Tests\TestCase;

class AboutSectionTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/content-management/about-sections';

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([ThrottleRequests::class, PermissionMiddleware::class]);
        Storage::fake('cloudinary');

        $this->admin = Admin::query()->create([
            'name' => 'Adekunle Johnson',
            'email' => 'content-admin@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
            'can_login' => true,
            '2fa' => false,
        ]);

        Sanctum::actingAs($this->admin);
    }

    public function test_list_returns_all_four_sections_in_order_before_any_edit(): void
    {
        $response = $this->getJson(self::BASE);

        $response->assertOk();
        $this->assertSame(
            ['overview', 'vision_mission', 'implementation_plan', 'pdf_viewer'],
            array_column($response->json('data'), 'section_key'),
        );
        $this->assertTrue($response->json('data.0.is_active'));
        $this->assertNull($response->json('data.0.last_updated'));
        $this->assertArrayNotHasKey('content', $response->json('data.0'));
    }

    public function test_show_returns_null_content_for_unsaved_section_and_404_for_unknown_key(): void
    {
        $this->getJson(self::BASE.'/vision_mission')
            ->assertOk()
            ->assertJsonPath('data.content', null)
            ->assertJsonPath('data.is_active', true);

        $this->getJson(self::BASE.'/unknown')
            ->assertNotFound()
            ->assertJsonPath('message', 'Record not found.');
    }

    public function test_update_replaces_plans_keeps_status_and_writes_audit_log(): void
    {
        AboutSection::query()->where('section_key', 'implementation_plan')->update([
            'is_active' => false,
            'content' => json_encode([
                'section_tag' => 'OLD',
                'section_title' => 'Old',
                'sub_text' => '',
                'plans' => [['title' => 'Phase 1', 'body' => 'a'], ['title' => 'Phase 2', 'body' => 'b'], ['title' => 'Phase 3', 'body' => 'c']],
            ]),
        ]);

        $response = $this->patchJson(self::BASE.'/implementation_plan', [
            'section_tag' => 'ROADMAP TO SUCCESS',
            'section_title' => 'Implementation Plan',
            'sub_text' => '',
            'plans' => [
                ['title' => 'Phase 1: Foundation', 'body' => 'Set up governance...'],
                ['title' => 'Phase 3: Scale', 'body' => 'Expand to all sets...'],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Section updated successfully.')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.updated_by', 'Adekunle Johnson')
            ->assertJsonPath('data.content.sub_text', '')
            ->assertJsonPath('data.content.plans.1.title', 'Phase 3: Scale');
        $this->assertCount(2, $response->json('data.content.plans'));
        $this->assertNotNull($response->json('data.last_updated'));

        $log = AuditLog::query()->where('action', AuditActionEnum::ABOUT_SECTION_UPDATED->value)->first();
        $this->assertNotNull($log);
        $this->assertSame($this->admin->uuid, $log->user_id);
        $this->assertSame('Phase 2', $log->metadata['data']['previous_content']['plans'][1]['title']);
    }

    public function test_update_validation_messages_are_readable(): void
    {
        $this->patchJson(self::BASE.'/implementation_plan', [
            'section_tag' => 'TAG',
            'section_title' => 'Title',
            'plans' => [],
        ])->assertStatus(422)->assertJsonPath('message', 'Implementation plans is required.');

        $this->patchJson(self::BASE.'/overview', [
            'section_tag' => 'ABOUT US',
            'body' => 'Body',
            'start_date' => '2026-10-01',
            'end_date' => '2026-09-01',
            'image' => 'https://cdn.example.com/a.jpg',
        ])->assertStatus(422)->assertJsonPath('message', 'Section title is required.');

        $this->patchJson(self::BASE.'/overview', [
            'section_tag' => 'ABOUT US',
            'section_title' => 'Title',
            'body' => 'Body',
            'start_date' => '2026-10-01',
            'end_date' => '2026-09-01',
            'image' => 'https://cdn.example.com/a.jpg',
        ])->assertStatus(422)->assertJsonPath('message', 'Expiration date must be on or after the start date.');
    }

    public function test_pdf_viewer_accepts_link_or_base64_pdf_and_rejects_other_files(): void
    {
        $this->patchJson(self::BASE.'/pdf_viewer', ['document_link' => 'https://icobaendowment.com/doc.pdf'])
            ->assertOk()
            ->assertJsonPath('data.content.document_link', 'https://icobaendowment.com/doc.pdf');

        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";
        $response = $this->patchJson(self::BASE.'/pdf_viewer', [
            'document_link' => 'data:application/pdf;base64,'.base64_encode($pdf),
        ]);
        $response->assertOk();
        $this->assertStringEndsWith('.pdf', $response->json('data.content.document_link'));

        $png = base64_encode("\x89PNG\r\n\x1a\n".str_repeat("\0", 16));
        $this->patchJson(self::BASE.'/pdf_viewer', ['document_link' => 'data:image/png;base64,'.$png])
            ->assertStatus(422);

        $log = AuditLog::query()->where('action', AuditActionEnum::ABOUT_SECTION_UPDATED->value)->latest('id')->first();
        $this->assertSame('[base64 file omitted]', $log->metadata['request']['payload']['document_link']);
    }

    public function test_toggle_status_works_on_unsaved_section_and_logs(): void
    {
        $this->patchJson(self::BASE.'/pdf_viewer/toggle-status')
            ->assertOk()
            ->assertJsonPath('message', 'Content block deactivated successfully.')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.updated_by', 'Adekunle Johnson');

        $this->patchJson(self::BASE.'/pdf_viewer/toggle-status')
            ->assertOk()
            ->assertJsonPath('message', 'Content block reactivated successfully.')
            ->assertJsonPath('data.is_active', true);

        $this->assertSame(2, AuditLog::query()->where('action', AuditActionEnum::ABOUT_SECTION_STATUS_TOGGLED->value)->count());
    }

    public function test_pages_list_includes_about_us_row(): void
    {
        $this->patchJson(self::BASE.'/vision_mission/toggle-status')->assertOk();

        $row = collect($this->getJson('/api/v1/admin/content-management/pages')->assertOk()->json('data'))
            ->firstWhere('page_key', 'about_us');

        $this->assertSame('About us / Home Page', $row['page_title']);
        $this->assertSame('active', $row['status']);
        $this->assertSame('Adekunle Johnson', $row['updated_by']);
        $this->assertNotNull($row['last_updated']);
    }

    public function test_public_endpoint_hides_inactive_unsaved_and_out_of_window_sections(): void
    {
        Carbon::setTestNow('2026-10-01 10:00:00');

        AboutSection::query()->where('section_key', 'overview')->update([
            'content' => json_encode(['section_title' => 'Overview', 'start_date' => '2026-10-01', 'end_date' => '2026-10-01', 'image' => 'https://cdn.example.com/a.jpg']),
        ]);
        AboutSection::query()->where('section_key', 'pdf_viewer')->update([
            'content' => json_encode(['document_link' => 'https://icobaendowment.com/doc.pdf']),
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/v1/public/about-sections');

        $response->assertOk()
            ->assertJsonPath('data.overview.section_title', 'Overview')
            ->assertJsonPath('data.vision_mission', null)
            ->assertJsonPath('data.pdf_viewer', null);

        Carbon::setTestNow('2026-10-02 10:00:00');
        $this->getJson('/api/v1/public/about-sections')->assertJsonPath('data.overview', null);

        Carbon::setTestNow();
    }
}

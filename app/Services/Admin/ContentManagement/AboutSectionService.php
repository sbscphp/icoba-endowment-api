<?php

namespace App\Services\Admin\ContentManagement;

use App\Enums\AboutSectionKey;
use App\Enums\AuditActionEnum;
use App\Enums\ModuleEnums;
use App\Enums\UserTypeEnum;
use App\Exceptions\ApiException;
use App\Helpers\FileUploadHelper;
use App\Helpers\GeneralHelper;
use App\Models\AboutSection;
use App\Models\Admin;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AboutSectionService
{
    private const UPLOAD_FOLDER = 'about-sections';

    /**
     * All four sections in display order.
     *
     * @return Collection<int, AboutSection>
     */
    public function list(): Collection
    {
        $sections = AboutSection::query()
            ->with('updatedByAdmin')
            ->get()
            ->keyBy('section_key');

        return new Collection(array_values(array_filter(array_map(
            fn (AboutSectionKey $key) => $sections->get($key->value),
            AboutSectionKey::cases(),
        ))));
    }

    public function find(string $sectionKey): AboutSection
    {
        return $this->resolveSection($sectionKey)->loadMissing('updatedByAdmin');
    }

    /**
     * Replaces the section's stored content in full. Status is left unchanged.
     *
     * @param  array<string, mixed>  $payload
     */
    public function update(string $sectionKey, array $payload, Admin $actor, Request $request): AboutSection
    {
        $section = $this->resolveSection($sectionKey);
        $key = AboutSectionKey::from((string) $section->section_key);

        $previousContent = $section->content;
        $content = $this->buildContent($key, $payload);

        DB::transaction(function () use ($section, $key, $content, $previousContent, $actor, $request): void {
            $section->forceFill([
                'content' => $content,
                'updated_by_admin_uuid' => $actor->uuid,
                'updated_at' => now(),
            ])->save();

            GeneralHelper::storeAuditLog(
                UserTypeEnum::ADMIN,
                AuditActionEnum::ABOUT_SECTION_UPDATED,
                $request,
                $actor->uuid,
                [
                    'section_key' => $key->value,
                    'changed_fields' => $this->changedFields($previousContent, $content),
                    'previous_content' => $previousContent,
                ],
                $key->label().' section updated.',
                AboutSection::class,
                $section->uuid,
                ModuleEnums::content_management,
                200,
            );
        });

        return $section->fresh(['updatedByAdmin']) ?? $section;
    }

    public function toggleActiveStatus(string $sectionKey, Admin $actor, Request $request): AboutSection
    {
        $section = $this->resolveSection($sectionKey);
        $key = AboutSectionKey::from((string) $section->section_key);
        $previousStatus = (bool) $section->is_active;
        $isActive = ! $previousStatus;

        DB::transaction(function () use ($section, $key, $previousStatus, $isActive, $actor, $request): void {
            $section->forceFill([
                'is_active' => $isActive,
                'updated_by_admin_uuid' => $actor->uuid,
            ])->save();

            GeneralHelper::storeAuditLog(
                UserTypeEnum::ADMIN,
                AuditActionEnum::ABOUT_SECTION_STATUS_TOGGLED,
                $request,
                $actor->uuid,
                [
                    'section_key' => $key->value,
                    'previous_status' => $previousStatus ? 'active' : 'inactive',
                    'new_status' => $isActive ? 'active' : 'inactive',
                ],
                $key->label().' section '.($isActive ? 'reactivated.' : 'deactivated.'),
                AboutSection::class,
                $section->uuid,
                ModuleEnums::content_management,
                200,
            );
        });

        return $section->fresh(['updatedByAdmin']) ?? $section;
    }

    private function resolveSection(string $sectionKey): AboutSection
    {
        $section = AboutSectionKey::tryFrom($sectionKey) !== null
            ? AboutSection::query()->where('section_key', $sectionKey)->first()
            : null;

        if ($section === null) {
            throw (new ModelNotFoundException)->setModel(AboutSection::class, [$sectionKey]);
        }

        return $section;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function buildContent(AboutSectionKey $key, array $payload): array
    {
        return match ($key) {
            AboutSectionKey::OVERVIEW => [
                'section_tag' => (string) $payload['section_tag'],
                'section_title' => (string) $payload['section_title'],
                'body' => (string) $payload['body'],
                'start_date' => (string) $payload['start_date'],
                'end_date' => (string) $payload['end_date'],
                'image' => $this->uploadImage($payload['image']),
            ],
            AboutSectionKey::VISION_MISSION => [
                'section_tag' => (string) $payload['section_tag'],
                'section_title' => (string) $payload['section_title'],
                'sub_text' => (string) ($payload['sub_text'] ?? ''),
                'vision' => [
                    'title' => (string) $payload['vision']['title'],
                    'body' => (string) $payload['vision']['body'],
                ],
                'mission' => [
                    'title' => (string) $payload['mission']['title'],
                    'body' => (string) $payload['mission']['body'],
                ],
            ],
            AboutSectionKey::IMPLEMENTATION_PLAN => [
                'section_tag' => (string) $payload['section_tag'],
                'section_title' => (string) $payload['section_title'],
                'sub_text' => (string) ($payload['sub_text'] ?? ''),
                'plans' => array_values(array_map(
                    fn (array $plan) => [
                        'title' => (string) $plan['title'],
                        'body' => (string) $plan['body'],
                    ],
                    $payload['plans'],
                )),
            ],
            AboutSectionKey::PDF_VIEWER => [
                'document_link' => $this->uploadDocument((string) $payload['document_link']),
            ],
        };
    }

    private function uploadImage(mixed $value): string
    {
        return $this->upload($value, 'Section image');
    }

    /**
     * Accepts an http(s) link (kept as is) or a base64 PDF (uploaded).
     */
    private function uploadDocument(string $value): string
    {
        $trimmed = trim($value);

        if (! str_starts_with($trimmed, 'http://') && ! str_starts_with($trimmed, 'https://')) {
            $decoded = base64_decode((string) preg_replace('#^data:[^;]+;base64,#i', '', $trimmed), true);

            if ($decoded === false || ! str_starts_with($decoded, '%PDF')) {
                throw new ApiException('Document must be a PDF file or a link starting with http:// or https://.', 422);
            }
        }

        return $this->upload($trimmed, 'Document');
    }

    private function upload(mixed $value, string $label): string
    {
        try {
            $uploaded = FileUploadHelper::smartSingleFileUpload($value, self::UPLOAD_FOLDER);
        } catch (InvalidArgumentException $e) {
            throw new ApiException($label.' upload failed: '.$e->getMessage(), 422);
        }

        if ($uploaded === null || $uploaded === '') {
            throw new ApiException($label.' is required.', 422);
        }

        return $uploaded;
    }

    /**
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>  $current
     * @return list<string>
     */
    private function changedFields(?array $previous, array $current): array
    {
        $previous ??= [];

        return array_values(array_filter(
            array_keys($current),
            fn (string $field) => ($previous[$field] ?? null) !== $current[$field],
        ));
    }
}

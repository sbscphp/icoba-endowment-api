<?php

namespace App\Http\Resources;

use App\Models\ContactSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ContactSubmission
 */
class ContactSubmissionListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('handledByAdmin:uuid,name');

        return [
            'submission_id' => $this->uuid,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'user_type' => $this->user_type->value,
            'user_type_label' => $this->user_type->label(),
            'description' => $this->description,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'handled_by' => $this->handledByAdmin !== null ? [
                'admin_id' => $this->handledByAdmin->uuid,
                'name' => $this->handledByAdmin->name,
            ] : null,
            'resolved_at' => $this->resolved_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

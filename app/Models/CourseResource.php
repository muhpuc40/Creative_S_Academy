<?php

namespace App\Models;

use App\Support\Media;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseResource extends Model
{
    protected $guarded = ['id'];

    /** An external link as it is; an uploaded file through the admin-only route (it is private). */
    protected function fileUrl(): Attribute
    {
        return Attribute::get(fn () => match (true) {
            ! $this->url => null,
            Media::isExternal($this->url) => $this->url,
            default => route('admin.library.resources.file', $this),
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(CourseModuleItem::class, 'course_resource_id');
    }
}

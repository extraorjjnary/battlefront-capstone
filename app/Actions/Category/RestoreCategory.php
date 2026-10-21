<?php

namespace App\Actions\Category;

use App\Models\Category;
use Illuminate\Support\Facades\DB;

class RestoreCategory
{
    public function execute(int $categoryId): void
    {
        DB::transaction(function () use ($categoryId): void {
            $category = Category::onlyTrashed()->lockForUpdate()->findOrFail($categoryId);
            $category->deleted_by_id = null;
            $category->is_active = false;
            $category->restore();
            DB::table('audit_logs')->insert([
                'actor_id' => auth()->id(), 'action' => 'category.restored', 'subject_type' => 'category',
                'subject_id' => $category->id, 'changes' => json_encode(['name' => $category->name], JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
        }, attempts: 3);
    }
}

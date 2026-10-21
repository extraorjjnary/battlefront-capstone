<?php

namespace App\Actions\Category;

use App\Models\Category;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteCategory
{
    public const PROTECTED_MESSAGE = 'This category cannot be deleted because it contains products. Move or remove its products first, or deactivate the category.';

    public function execute(Category $category): void
    {
        $actorId = auth()->id();
        $actorId = $actorId === null ? null : max(0, (int) $actorId);

        try {
            DB::transaction(function () use ($category, $actorId): void {
                $lockedCategory = Category::query()->lockForUpdate()->findOrFail($category->id);
                if ($lockedCategory->products()->exists()) {
                    throw ValidationException::withMessages(['deletion' => self::PROTECTED_MESSAGE]);
                }

                $lockedCategory->is_active = false;
                $lockedCategory->deleted_by_id = $actorId;
                $lockedCategory->save();

                DB::table('audit_logs')->insert([
                    'actor_id' => $actorId,
                    'action' => 'category.deleted',
                    'subject_type' => 'category',
                    'subject_id' => $lockedCategory->id,
                    'changes' => json_encode(['name' => $lockedCategory->name], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
                $lockedCategory->delete();
            }, attempts: 3);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1451
                || str_contains($exception->getMessage(), 'FOREIGN KEY constraint failed')) {
                throw ValidationException::withMessages(['deletion' => self::PROTECTED_MESSAGE]);
            }

            throw $exception;
        }
    }
}

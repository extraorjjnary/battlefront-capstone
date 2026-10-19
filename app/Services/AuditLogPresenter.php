<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;
use stdClass;

class AuditLogPresenter
{
    /** @return array<string, mixed> */
    public function present(stdClass $entry, User $viewer): array
    {
        $detailsVisible = $this->canViewDetails($viewer, $entry->subject_type, $entry->action);
        $changes = $detailsVisible && is_string($entry->changes)
            ? json_decode($entry->changes, true) : null;

        return [
            'id' => (int) $entry->id,
            'created_at' => $entry->created_at,
            'actor_id' => $entry->actor_id === null ? null : (int) $entry->actor_id,
            'actor_name' => $entry->actor_name ?? 'System or removed account',
            'action' => $entry->action,
            'action_label' => Str::headline(str_replace('.', ' ', $entry->action)),
            'subject_type' => $entry->subject_type,
            'subject_label' => Str::headline(str_replace('_', ' ', $entry->subject_type)),
            'subject_id' => $entry->subject_id === null ? null : (int) $entry->subject_id,
            'subject_url' => $this->subjectUrl($viewer, $entry->subject_type, $entry->subject_id),
            'reason' => $detailsVisible ? $entry->reason : null,
            'details_visible' => $detailsVisible,
            'details' => is_array($changes) ? $this->details($changes) : [],
            'has_changes' => $entry->changes !== null,
        ];
    }

    private function canViewDetails(User $viewer, string $subjectType, string $action): bool
    {
        $permission = match ($subjectType) {
            'order', 'customer_return', 'customer_refund', 'refund_payment' => 'orders.view',
            'inventory', 'inventory_adjustment_request', 'damage_report', 'supplier_return' => 'inventory.view',
            'product', 'chatbot_knowledge' => 'products.manage',
            'supplier' => 'suppliers.manage',
            'administrator_account' => $action === 'role.permissions.updated' ? 'permissions.manage' : 'users.manage',
            'user' => 'staff_reports.view',
            default => 'audit.export',
        };

        return $viewer->can($permission);
    }

    private function subjectUrl(User $viewer, string $subjectType, ?int $subjectId): ?string
    {
        if ($subjectId === null || ! $this->canViewDetails($viewer, $subjectType, '')) {
            return null;
        }

        return match ($subjectType) {
            'order' => route('administration.orders.show', $subjectId),
            'product' => route('administration.products.show', $subjectId),
            'inventory_adjustment_request' => route('administration.inventory-adjustments.index', ['case' => $subjectId]),
            'damage_report' => route('administration.damage-reports.index', ['case' => $subjectId]),
            'supplier_return' => route('administration.supplier-returns.index', ['case' => $subjectId]),
            'customer_return' => route('administration.customer-returns.index', ['case' => $subjectId]),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return list<array{label: string, before: string|null, after: string}>
     */
    private function details(array $changes): array
    {
        $before = is_array($changes['before'] ?? null) ? $changes['before'] : [];
        $after = is_array($changes['after'] ?? null) ? $changes['after'] : [];
        $rows = [];

        foreach ($after as $key => $value) {
            $rows[] = [
                'label' => Str::headline((string) $key),
                'before' => array_key_exists($key, $before) ? $this->value((string) $key, $before[$key]) : null,
                'after' => $this->value((string) $key, $value),
            ];
        }
        foreach ($before as $key => $value) {
            if (! array_key_exists($key, $after)) {
                $rows[] = [
                    'label' => Str::headline((string) $key),
                    'before' => $this->value((string) $key, $value),
                    'after' => 'Removed',
                ];
            }
        }
        foreach ($changes as $key => $value) {
            if (in_array($key, ['before', 'after'], true)) {
                continue;
            }
            $rows[] = ['label' => Str::headline((string) $key), 'before' => null,
                'after' => $this->value((string) $key, $value)];
        }

        return $rows;
    }

    private function value(string $key, mixed $value): string
    {
        if (preg_match('/password|secret|token|proof|evidence|(^|_)path$/i', $key) === 1) {
            return '[redacted]';
        }

        if ($value === null) {
            return 'None';
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            $value = $this->redactNested($value);
        }

        return is_scalar($value) ? (string) $value : (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[unavailable]');
    }

    /** @param array<string|int, mixed> $values
     * @return array<string|int, mixed>
     */
    private function redactNested(array $values): array
    {
        foreach ($values as $key => $value) {
            $values[$key] = preg_match('/password|secret|token|proof|evidence|(^|_)path$/i', (string) $key) === 1
                ? '[redacted]'
                : (is_array($value) ? $this->redactNested($value) : $value);
        }

        return $values;
    }
}

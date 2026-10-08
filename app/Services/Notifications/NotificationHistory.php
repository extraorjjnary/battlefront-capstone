<?php

namespace App\Services\Notifications;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * @phpstan-type NotificationView array{
 *     id: string, event: mixed, title: mixed, body: mixed, occurred_at: mixed,
 *     created_at: string|null, read_at: string|null, is_read: bool,
 *     order: array{id: int, reference: string, web_url: string, api_url: string|null,
 *         deep_link: array{screen: string, order_id: int}|null}|null
 * }
 */
class NotificationHistory
{
    /** @return Builder<DatabaseNotification> */
    public function query(User $user): Builder
    {
        return $user->notifications()->getQuery()
            ->where('data->audience', $user->isAdministrator() ? 'administrator' : 'customer');
    }

    public function unreadCount(User $user): int
    {
        return $this->query($user)->whereNull('read_at')->count();
    }

    /** @return LengthAwarePaginator<int, covariant NotificationView> */
    public function paginate(User $user): LengthAwarePaginator
    {
        $paginator = $this->query($user)->orderByDesc('created_at')->orderByDesc('id')->paginate(10)->withQueryString();

        return new LengthAwarePaginator(
            $this->presentMany($user, $paginator->getCollection()),
            $paginator->total(), $paginator->perPage(), $paginator->currentPage(),
            $paginator->getOptions(),
        );
    }

    /** @return array{unread_count: int, recent: list<NotificationView>} */
    public function summary(User $user): array
    {
        return [
            'unread_count' => $this->unreadCount($user),
            'recent' => array_values($this->presentMany($user, $this->query($user)->orderByDesc('created_at')->orderByDesc('id')->limit(5)->get())->all()),
        ];
    }

    /** @return NotificationView */
    public function read(User $user, string $id): array
    {
        $notification = $this->query($user)->whereKey($id)->firstOrFail();
        $notification->markAsRead();

        return $this->presentMany($user, collect([$notification]))->sole();
    }

    public function readAll(User $user): void
    {
        $this->query($user)->whereNull('read_at')->update(['read_at' => now()]);
    }

    /**
     * @param  Collection<int, DatabaseNotification>  $notifications
     * @return Collection<int, covariant NotificationView>
     */
    public function presentMany(User $user, Collection $notifications): Collection
    {
        $orderIds = $notifications->map(fn (DatabaseNotification $notification): mixed => $notification->data['order_id'] ?? null)->filter()->all();
        $orders = Order::query()->select(['id', 'user_id'])->whereKey($orderIds)
            ->when(! $user->isAdministrator(), fn (Builder $query): Builder => $query->whereBelongsTo($user))
            ->get()->keyBy('id');

        return $notifications->map(fn (DatabaseNotification $notification): array => $this->present(
            $notification, $orders->get($notification->data['order_id'] ?? null), $user,
        ));
    }

    /** @return NotificationView */
    private function present(DatabaseNotification $notification, ?Order $order, User $user): array
    {
        $data = $notification->data;

        return [
            'id' => $notification->id,
            'event' => $data['event'],
            'title' => $data['title'],
            'body' => $data['body'],
            'occurred_at' => $data['occurred_at'],
            'created_at' => $notification->created_at?->toIso8601String(),
            'read_at' => $notification->read_at?->toIso8601String(),
            'is_read' => $notification->read_at !== null,
            'order' => $order === null ? null : [
                'id' => $order->id,
                'reference' => $order->reference,
                'web_url' => route($user->isAdministrator() ? 'administration.orders.show' : 'orders.show', $order),
                'api_url' => $user->isAdministrator() ? null : route('api.v1.orders.show', $order),
                'deep_link' => $user->isAdministrator() ? null : ['screen' => 'order_detail', 'order_id' => $order->id],
            ],
        ];
    }
}

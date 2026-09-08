<?php

namespace App\Http\Controllers\Administration;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    /**
     * Display the customer account directory.
     */
    public function index(): Response
    {
        $this->authorize('viewAny', User::class);

        $customers = User::query()
            ->select(['id', 'name', 'email', 'email_verified_at', 'created_at'])
            ->where('role', UserRole::Customer)
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (User $customer): array => $this->customerData($customer));

        return Inertia::render('Administration/Customers/Index', [
            'customers' => $customers,
        ]);
    }

    /**
     * Display a customer account record.
     */
    public function show(User $customer): Response
    {
        $this->authorize('view', $customer);

        return Inertia::render('Administration/Customers/Show', [
            'customer' => $this->customerData($customer),
        ]);
    }

    /**
     * Return only the customer account fields approved for administration.
     *
     * @return array{
     *     id: int,
     *     name: string,
     *     email: string,
     *     is_email_verified: bool,
     *     created_at: string|null
     * }
     */
    private function customerData(User $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'is_email_verified' => $customer->email_verified_at !== null,
            'created_at' => $customer->created_at?->toIso8601String(),
        ];
    }
}

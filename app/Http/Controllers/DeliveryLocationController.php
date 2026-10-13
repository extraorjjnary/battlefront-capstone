<?php

namespace App\Http\Controllers;

use App\Services\Order\PsgcDeliveryLocations;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DeliveryLocationController extends Controller
{
    public function index(Request $request, PsgcDeliveryLocations $locations): JsonResource
    {
        $input = $request->validate([
            'level' => ['required', Rule::in(['regions', 'provinces', 'cities', 'city', 'barangays'])],
            'parent_code' => ['nullable', 'string', 'regex:/\A[0-9]{10}\z/'],
        ]);
        try {
            return JsonResource::make(['version' => PsgcDeliveryLocations::VERSION,
                'locations' => $locations->lookup($input['level'], $input['parent_code'] ?? null)]);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['parent_code' => $exception->getMessage()]);
        }
    }
}

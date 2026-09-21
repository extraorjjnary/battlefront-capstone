<?php

namespace App\Actions\Chatbot\Context;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class ResolveStoreContext
{
    /**
     * Resolve confirmed branch reference data, preferring a branch named in the inquiry.
     *
     * @return array{branches: list<array{
     *     name: string,
     *     city: string,
     *     address: string|null,
     *     contact_number: string|null,
     *     email: string|null,
     *     operating_hours: string,
     *     is_operational: bool
     * }>}
     */
    public function execute(string $message): array
    {
        $branches = Branch::query()
            ->select(['id', 'name', 'address', 'city', 'contact_number'])
            ->orderBy('city')
            ->orderBy('id')
            ->get();

        $matchingBranches = $this->matchingBranches($branches, $message);

        return [
            'branches' => array_values(($matchingBranches->isNotEmpty() ? $matchingBranches : $branches)
                ->sort(function (Branch $left, Branch $right): int {
                    return ((int) ! $left->is_operational <=> (int) ! $right->is_operational)
                        ?: strcasecmp($left->city, $right->city)
                        ?: ($left->id <=> $right->id);
                })
                ->values()
                ->map(fn (Branch $branch): array => [
                    'name' => $branch->name,
                    'city' => $branch->city,
                    'address' => $branch->address,
                    'contact_number' => $branch->contact_number,
                    'email' => $branch->email,
                    'operating_hours' => $branch->operating_hours,
                    'is_operational' => $branch->is_operational,
                ])
                ->all()),
        ];
    }

    /**
     * @param  Collection<int, Branch>  $branches
     * @return Collection<int, Branch>
     */
    private function matchingBranches(Collection $branches, string $message): Collection
    {
        $normalizedMessage = $this->normalize($message);

        if ($normalizedMessage === '') {
            return new Collection;
        }

        return $branches->filter(function (Branch $branch) use ($normalizedMessage): bool {
            $city = $this->normalize($branch->city);
            $cityWithoutSuffix = Str::endsWith($city, ' city')
                ? Str::beforeLast($city, ' city')
                : $city;

            return $this->containsPhrase($normalizedMessage, $city)
                || (Str::length($cityWithoutSuffix) >= 4
                    && $this->containsPhrase($normalizedMessage, $cityWithoutSuffix));
        });
    }

    private function normalize(string $value): string
    {
        return Str::of($value)
            ->trim()
            ->lower()
            ->replaceMatches('/[^\p{L}\p{N}\s]+/u', ' ')
            ->squish()
            ->toString();
    }

    private function containsPhrase(string $message, string $phrase): bool
    {
        return str_contains(" {$message} ", " {$phrase} ");
    }
}

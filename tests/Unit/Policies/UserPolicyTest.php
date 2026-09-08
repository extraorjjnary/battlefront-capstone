<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Policies\UserPolicy;

function userWithRole(UserRole $role): User
{
    $user = new User;
    $user->role = $role;

    return $user;
}

test('only administrators can view the customer directory', function (
    UserRole $role,
    bool $expected,
) {
    $policy = new UserPolicy;

    expect($policy->viewAny(userWithRole($role)))->toBe($expected);
})->with([
    'customer' => [UserRole::Customer, false],
    'administrator' => [UserRole::Administrator, true],
]);

test('only administrators can view customer records', function (
    UserRole $actorRole,
    UserRole $recordRole,
    bool $expected,
) {
    $policy = new UserPolicy;

    expect($policy->view(
        userWithRole($actorRole),
        userWithRole($recordRole),
    ))->toBe($expected);
})->with([
    'customer viewing customer' => [
        UserRole::Customer,
        UserRole::Customer,
        false,
    ],
    'customer viewing administrator' => [
        UserRole::Customer,
        UserRole::Administrator,
        false,
    ],
    'administrator viewing customer' => [
        UserRole::Administrator,
        UserRole::Customer,
        true,
    ],
    'administrator viewing administrator' => [
        UserRole::Administrator,
        UserRole::Administrator,
        false,
    ],
]);

test('customer account mutations are not authorized', function (string $ability) {
    $policy = new UserPolicy;
    $administrator = userWithRole(UserRole::Administrator);
    $customer = userWithRole(UserRole::Customer);

    $isAuthorized = $ability === 'create'
        ? $policy->create($administrator)
        : $policy->{$ability}($administrator, $customer);

    expect($isAuthorized)->toBeFalse();
})->with([
    'create',
    'update',
    'delete',
    'restore',
    'forceDelete',
]);

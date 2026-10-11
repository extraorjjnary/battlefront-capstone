# Local demonstration customers

`DevelopmentCustomerSeeder` creates 30 fictional Filipino customer accounts in
`local` or `testing` only. Their reserved emails are
`demo.customer01@example.test` through `demo.customer30@example.test`; all use
the shared password `customer123`. These are demonstration identities, not client
records. Use the normal sign-in form; email verification remains disabled.

To add the accounts without resetting existing data:

```shell
php artisan db:seed --class=DevelopmentCustomerSeeder --no-interaction
```

Reruns preserve matching accounts and create missing roster members. Unexpected
changes to a reserved email, name, role, password, or verification status stop the
entire seeder without changing accounts. Profile preferences and addresses remain
editable and are preserved. The seeder creates no purchases or browsing history.

Both development reset variants include these accounts automatically:

```shell
php artisan battlefront:reset-dev
php artisan battlefront:reset-dev --with-sales-history
```

**A reset deletes the development database tables.** Run it manually only when
you intend to recreate local data and have the existing verified catalog inputs.
It restores the original demo identities and credentials. Administrator/test
accounts and the separate optional historical-sales customer retain their existing
seeding conventions. Ordinary `DatabaseSeeder` does not create this roster.

Keep this shared credential confined to local/testing. Never seed these accounts
in production, publish the credential on application pages, or log it.

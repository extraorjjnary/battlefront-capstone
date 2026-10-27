<x-mail::message>
# Verify your email address

@if ($forTeamAccount)
A Battlefront administrator is preparing your team account. Personally enter this code in the Add team member form at the store to verify your email before the account is created:
@else
Enter this six-digit code in Battlefront to verify ownership of your email address:
@endif

<x-mail::panel>
{{ $code }}
</x-mail::panel>

This code expires in **5 minutes** and can be used only once. Requesting a new code invalidates the previous code.

@if ($forTeamAccount)
Enter the code yourself in Battlefront. Do not read it aloud, forward it, or share it with anyone.
@else
Never share this code with anyone. Battlefront staff will never ask you for your verification code.
@endif

If you did not request this code, you can ignore this email.

Thank you,<br>
Battlefront Computer Trading
</x-mail::message>

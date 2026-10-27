<?php

namespace App\Actions\Fortify;

use App\Mail\VerifyEmailOtp;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use SensitiveParameter;
use Throwable;

class IssueEmailOtp
{
    public function execute(string $email, #[SensitiveParameter] ?string $previousHash = null, bool $forTeamAccount = false): ?string
    {
        do {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while ($previousHash !== null && Hash::check($code, $previousHash));

        $hash = Hash::make($code);

        try {
            $transport = config('mail.mailers.'.config('mail.default').'.transport');
            if ($transport !== 'smtp' && ! (app()->runningUnitTests() && $transport === 'array')) {
                return null;
            }
            Mail::to($email)->send(new VerifyEmailOtp($code, $forTeamAccount));

            return $hash;
        } catch (Throwable) {
            return null;
        } finally {
            unset($code);
        }
    }
}

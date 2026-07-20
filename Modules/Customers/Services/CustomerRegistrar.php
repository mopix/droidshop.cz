<?php

namespace Modules\Customers\Services;

use App\Core\Mail\Contracts\MailService;
use App\Core\Mail\MailKind;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Customers\Mail\VerifyEmail;
use Modules\Customers\Models\Customer;

/**
 * Creates a customer account.
 *
 * A service rather than a fat controller because registration will grow a
 * second call site in the next etapa: checkout offers to create an account
 * from the details the customer just typed.
 */
class CustomerRegistrar
{
    public function __construct(
        private readonly CustomerTokens $tokens,
        private readonly MailService $mail,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function register(array $data): Customer
    {
        return DB::transaction(function () use ($data) {
            $customer = Customer::create([
                // Normalised here, once, on write: every lookup
                // (EloquentCustomerIdentity::findByEmail(),
                // PasswordResetController) already lowercases the address it
                // searches for, and that only actually matches stored data
                // because it does — this makes that true by construction
                // instead of by relying on the database collation.
                'email' => Str::lower($data['email']),
                // The model casts password to hashed, so the plain value is
                // never what lands in the column.
                'password' => $data['password'],
                'first_name' => $data['first_name'] ?? null,
                'last_name' => $data['last_name'] ?? null,
                'phone' => $data['phone'] ?? null,
            ]);

            $this->sendVerification($customer);

            return $customer;
        });
    }

    /**
     * The queued send is deferred until this transaction actually commits
     * (SendTenantMail::afterCommit()), so dispatching from inside it cannot
     * hand a worker a customer row that is not there yet.
     */
    private function sendVerification(Customer $customer): void
    {
        $token = $this->tokens->issue($customer->email, CustomerTokens::EMAIL_VERIFICATION);

        $verifyUrl = route('storefront.customers.verify', [
            'token' => $token,
            'email' => $customer->email,
        ]);

        $shopName = $this->context->current()?->name ?? '';

        $this->mail->send(new VerifyEmail($verifyUrl, $shopName), $customer->email, MailKind::Transactional);
    }
}

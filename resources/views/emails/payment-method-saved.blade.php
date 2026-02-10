{{--
    Email: Payment Method Saved
    Sent when a new payment method is added to a customer's account.
--}}

<x-mail::message>
# New Payment Method Added

Hello {{ $customerName }},

A new {{ $methodType }} has been added to your account.

**Details:**
- **Payment Method:** {{ $displayName }}
- **Last 4 Digits:** {{ $lastFour }}
- **Date Added:** {{ $dateAdded }}

<x-mail::panel>
**Security Notice:** If you did not add this payment method, please contact us immediately to secure your account.
</x-mail::panel>

Thank you for choosing us for your payment needs.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

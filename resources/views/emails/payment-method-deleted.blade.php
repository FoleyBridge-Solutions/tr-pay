{{--
    Email: Payment Method Deleted
    Sent when a payment method is removed from a customer's account.
--}}

<x-mail::message>
# Payment Method Removed

Hello {{ $customerName }},

A {{ $methodType }} has been removed from your account.

**Details:**
- **Payment Method:** {{ $displayName }}
- **Last 4 Digits:** {{ $lastFour }}
- **Date Removed:** {{ $dateRemoved }}

<x-mail::panel>
**Security Notice:** If you did not remove this payment method, please contact us immediately to secure your account.
</x-mail::panel>

Thank you for choosing us for your payment needs.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

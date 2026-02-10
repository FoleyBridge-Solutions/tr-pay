{{--
    Email: Payment Method Expiring Soon
    Sent when a credit card is expiring within 30 days.
--}}

<x-mail::message>
# Your Payment Method is Expiring Soon

Hello {{ $customerName }},

Your {{ $brand ?? 'credit card' }} ending in **{{ $lastFour }}** will expire on **{{ $expirationDate }}**.

@if($hasLinkedPlans)
<x-mail::panel>
**Important:** This card is linked to active payment plans or recurring payments. Please update your payment method before it expires to avoid any interruption in service.
</x-mail::panel>
@else
To ensure uninterrupted service, please update your payment method before it expires.
@endif

To update your payment method:
1. Log in to your account
2. Navigate to the payment page
3. Select "Add New Card" during checkout
4. Remove the expired card if desired

<x-mail::button :url="config('app.url')">
Update Payment Method
</x-mail::button>

If you have any questions, please don't hesitate to contact us.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

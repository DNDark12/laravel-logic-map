<?php

namespace Fixtures\CommerceApp\Services;

use Fixtures\CommerceApp\Mail\OrderCancelledMail;
use Fixtures\CommerceApp\Models\Order;
use Fixtures\CommerceApp\Models\User;
use Illuminate\Support\Facades\Mail;

final class OrderMailService
{
    public function queue(Order $order, User $user): void
    {
        Mail::to($user)->queue(new OrderCancelledMail($order));
    }
}

<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    /**
     * Determine whether the customer can view their order list.
     */
    public function viewAny(User $user): bool
    {
        return $user->role === 'pelanggan';
    }

    /**
     * Determine whether the customer owns the requested order.
     */
    public function view(User $user, Order $order): bool
    {
        return $user->role === 'pelanggan' && $order->user_id === $user->id;
    }

    /**
     * Only the customer who owns an order may request its cancellation.
     */
    public function cancel(User $user, Order $order): bool
    {
        return $this->view($user, $order);
    }
}

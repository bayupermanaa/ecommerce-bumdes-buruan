<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use App\Support\UserRoles;

class OrderPolicy
{
    /**
     * Determine whether the customer can view their order list.
     */
    public function viewAny(User $user): bool
    {
        return UserRoles::canBuy($user->role);
    }

    /**
     * Determine whether the customer owns the requested order.
     */
    public function view(User $user, Order $order): bool
    {
        return UserRoles::canBuy($user->role)
            && $order->user_id === $user->id;
    }

    /**
     * Only the customer who owns an order may request its cancellation.
     */
    public function cancel(User $user, Order $order): bool
    {
        return $this->view($user, $order);
    }
}

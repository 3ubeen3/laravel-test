<?php

namespace App\Services;

use App\Models\Affiliate;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;

class OrderService
{
    public function __construct(
        protected AffiliateService $affiliateService
    ) {}

    /**
     * Process an order and log any commissions.
     * This should create a new affiliate if the customer_email is not already associated with one.
     * This method should also ignore duplicates based on order_id.
     *
     * @param  array{order_id: string, subtotal_price: float, merchant_domain: string, discount_code: string, customer_email: string, customer_name: string} $data
     * @return void
     */
    public function processOrder(array $data)
    {
        $merchant = Merchant::where('domain', $data['merchant_domain'])->first();
        if (!$merchant) {
            return; // or throw
        }

        if (Order::where('external_order_id', $data['order_id'])->exists()) {
            return;
        }

        // Find the affiliate who owns this discount code (they get the commission)
        $commissionAffiliate = Affiliate::where('discount_code', $data['discount_code'])
            ->where('merchant_id', $merchant->id)
            ->first();

        // Always try to register the customer as an affiliate (if they're not already one)
        $user = User::where('email', $data['customer_email'])->first();
        if (!$user || !$user->affiliate()->where('merchant_id', $merchant->id)->exists()) {
            $this->affiliateService->register($merchant, $data['customer_email'], $data['customer_name'], 0.1);
        }

        // Use the commission affiliate for the order (the one who owns the discount code)
        $affiliate = $commissionAffiliate;

        if (!$affiliate) {
            // If no discount code affiliate found, this shouldn't happen in normal flow
            // but we'll handle it by using the customer as the affiliate
            $user = User::where('email', $data['customer_email'])->first();
            $affiliate = $user->affiliate()->where('merchant_id', $merchant->id)->first();
        }

        $commissionOwed = $data['subtotal_price'] * $affiliate->commission_rate;

        Order::create([
            'merchant_id' => $merchant->id,
            'affiliate_id' => $affiliate->id,
            'subtotal' => $data['subtotal_price'],
            'commission_owed' => $commissionOwed,
            'discount_code' => $data['discount_code'],
            'customer_email' => $data['customer_email'],
            'external_order_id' => $data['order_id']
        ]);
    }
}
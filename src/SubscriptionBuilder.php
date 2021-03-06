<?php

namespace Laravel\CashierAuthorizeNet;

use DateTime;
use Exception;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Laravel\CashierAuthorizeNet\Requestor;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\constants as AnetConstants;
use net\authorize\api\controller as AnetController;
use Illuminate\Support\Str;

class SubscriptionBuilder
{
    /**
     * The user model that is subscribing.
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $user;

    /**
     * The name of the subscription.
     *
     * @var string
     */
    protected $name;

    /**
     * The name of the plan being subscribed to.
     *
     * @var string
     */
    protected $plan;

    /**
     * The quantity of the subscription.
     *
     * @var int
     */
    protected $quantity = 1;

    /**
     * The number of trial days to apply to the subscription.
     *
     * @var int|null
     */
    protected $trialDays;

    /**
     * Indicates that the trial should end immediately.
     *
     * @var bool
     */
    protected $skipTrial = false;

    /**
     * The coupon code being applied to the customer.
     *
     * @var string|null
     */
    protected $coupon;

    /**
     * The metadata to apply to the subscription.
     *
     * @var array|null
     */
    protected $metadata;

    /**
     * Create a new subscription builder instance.
     *
     * @param  mixed  $user
     * @param  string  $name
     * @param  array  $plan
     * @return void
     */
    public function __construct($user, $name, $plan)
    {
        $this->user = $user;
        $this->name = $name;
        $this->plan = $plan;
        $this->requestor = new Requestor;
    }

    /**
     * Specify the quantity of the subscription.
     *
     * @param  int  $quantity
     * @return $this
     */
    public function quantity($quantity)
    {
        $this->quantity = $quantity;

        return $this;
    }

    /**
     * Specify the ending date of the trial.
     *
     * @param  int  $trialDays
     * @return $this
     */
    public function trialDays($trialDays)
    {
        $this->trialDays = $trialDays;

        return $this;
    }

    /**
     * Force the trial to end immediately.
     *
     * @return $this
     */
    public function skipTrial()
    {
        $this->skipTrial = true;

        return $this;
    }

    /**
     * The coupon to apply to a new subscription.
     *
     * @param  string  $coupon
     * @return $this
     */
    public function withCoupon($coupon)
    {
        $this->coupon = $coupon;

        return $this;
    }

    /**
     * The metadata to apply to a new subscription.
     *
     * @param  array  $metadata
     * @return $this
     */
    public function withMetadata($metadata)
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * Add a new Authorize subscription to the user.
     *
     * @param  array  $options
     * @return \Laravel\Cashier\Subscription
     */
    public function add(array $options = [])
    {
        return $this->create(null, $options);
    }

    public function test(){
        return $this->plan;
    }
    /**
     * Create a new Authorize subscription.
     *
     * @param  string|null  $token
     * @param  array  $options
     * @return \Laravel\Cashier\Subscription
     */
    public function create()
    {
        // $config = Config::get('cashier-authorize');
        // Set the transaction's refId
        $refId = 'ref' . time();
        // Subscription Type Info
        $subscription = new AnetAPI\ARBSubscriptionType();
        $subscription->setName($this->plan['name']);

        $interval = new AnetAPI\PaymentScheduleType\IntervalAType();
        $interval->setLength($this->plan['duration']);
        $interval->setUnit('days'); // To Do $this->plan['invoice_interval'

        $trialDays = $this->plan['trial_period'];
        $this->trialDays($trialDays);

        // Must use mountain time according to Authorize.net
        $nowInMountainTz = Carbon::now('America/Denver')->addDays($trialDays);

        $paymentSchedule = new AnetAPI\PaymentScheduleType();
        $paymentSchedule->setInterval($interval);
        $paymentSchedule->setStartDate(new DateTime($nowInMountainTz));
        $paymentSchedule->setTotalOccurrences(9999); //TO DO make it variable
        $paymentSchedule->setTrialOccurrences(0); //TO DO make it variable

        // $amount = round(floatval($this->plan['price']) * floatval('1.'.$this->getTaxPercentageForPayload()), 2);
        $amount = round(floatval($this->plan['price']) + floatval($this->plan['price'] * $this->getTaxPercentageForPayload() / 100), 2);
        $subscription->setPaymentSchedule($paymentSchedule);
        $subscription->setAmount($amount);
        $subscription->setTrialAmount(0); //TO DO make it variable

        $profile = new AnetAPI\CustomerProfileIdType();
        $profile->setCustomerProfileId($this->user->authorize_id);
        $profile->setCustomerPaymentProfileId($this->user->authorize_payment_id);
        $subscription->setProfile($profile);

        $order = new AnetAPI\OrderType();
        $order->setInvoiceNumber(mt_rand(10000, 99999));   //generate random invoice number     
        $order->setDescription($this->plan['description']); 
        $subscription->setOrder($order);

        $requestor = new Requestor();
        $request = $requestor->prepare((new AnetAPI\ARBCreateSubscriptionRequest()));
        $request->setRefId($refId);
        $request->setSubscription($subscription);
        $controller = new AnetController\ARBCreateSubscriptionController($request);

        $response = $controller->executeWithApiResponse($requestor->env);

        if (($response != null) && ($response->getMessages()->getResultCode() == "Ok") ) {
            if ($this->skipTrial) {
                $trialEndsAt = null;
            } else {
                $trialEndsAt = $this->trialDays ? Carbon::now()->addDays($this->trialDays) : null;
            }
            return [
                'name' => $this->name,
                'authorize_id' => $response->getSubscriptionId(),
                'authorize_plan' => $this->plan['name'],
                'authorize_payment_id' => $this->user->authorize_payment_id,
                'metadata' => json_encode([
                    'refId' => $requestor->refId
                ]),
                'quantity' => $this->quantity,
                'trial_ends_at' => $trialEndsAt,
                'ends_at' => null,
            ];
            // return $this->user->subscriptions()->create([
            //     'name' => $this->name,
            //     'authorize_id' => $response->getSubscriptionId(),
            //     'authorize_plan' => $this->plan['name'],
            //     'authorize_payment_id' => $this->user->authorize_payment_id,
            //     'metadata' => json_encode([
            //         'refId' => $requestor->refId
            //     ]),
            //     'quantity' => $this->quantity,
            //     'trial_ends_at' => $trialEndsAt,
            //     'ends_at' => null,
            // ]);
        } else {
            $errorMessages = $response->getMessages()->getMessage();
            throw new Exception("Response : " . $errorMessages[0]->getCode() . "  " .$errorMessages[0]->getText(), 1);
        }
    }

    
    /**
     * Get the trial ending date for the Authorize payload.
     *
     * @return int|null
     */
    protected function getTrialEndForPayload()
    {
        if ($this->skipTrial) {
            return 'now';
        }

        if ($this->trialDays) {
            return Carbon::now()->addDays($this->trialDays)->getTimestamp();
        }
    }

    /**
     * Get the tax percentage for the Authorize payload.
     *
     * @return int|null
     */
    protected function getTaxPercentageForPayload()
    {
        if ($taxPercentage = $this->user->taxPercentage()) {
            return $taxPercentage;
        }
    }
}

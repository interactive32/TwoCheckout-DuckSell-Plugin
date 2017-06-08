<?php namespace App\Plugins\TwoCheckout;

use App\Http\Controllers\Controller;
use App\Models\Plugin;
use App\Services\AmountService;
use App\Services\TemplateService;
use App\Services\Util;
use Event;
use Input;
use Lang;
use Redirect;
use Route;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Illuminate\Http\Request;
use App\Models\Log;
use App\Models\Product;
use App\Models\ProductTransaction;
use App\Models\Transaction;
use App\Models\TransactionMetadata;
use App\Models\TransactionUpdate;
use App\Models\User;
use Validator;


Event::listen('App\Events\ContentProductsEdit', function($event)
{
    $options = Plugin::getData('2checkout');
    $sid = ($options->where('key', 'account-number')->first() && $options->where('key', 'account-number')->first()->value) ? $options->where('key', 'account-number')->first()->value : '';
    $product = Product::findOrFail($event->product_id);

    if(APP_VERSION < 1.5  || !$sid) {
        return;
    }

    $purchase_link = url('purchase_2co/'.$event->product_id);

    $form_button = '<form target="twocheckout" action="'.$purchase_link.'" method="get">
    <input name="submit" type="submit" value="Buy now">
</form>';

    $bootstrap_button = '<a href="'.$purchase_link.'" class="btn btn-primary">Buy Now</a>';


    return '
    <div class="box box-success">
        <div class="box-header">
            <h3 class="box-title">2Checkout Integration</h3>
        </div>
        <div class="box-body">
            <p>
              <button data-target="#twocheckoutModal" data-toggle="modal" class="btn btn-secondary pull-right" type="button">Buy Button Code</button>
            </p>
        </div>
    </div>
    
    <!-- Modal -->
    <div class="modal fade" id="twocheckoutModal" tabindex="-1" role="dialog" aria-labelledby="twocheckoutModalLabel">
      <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            <h4 class="modal-title" id="twocheckoutModalLabel">2Checkout Buy Button Code</h4>
          </div>
          <div class="modal-body">
          
          <div class="nav-tabs-custom no-shadow">
						<ul class="nav nav-tabs">
							<li class="active"><a href="#twocobutton_1" data-toggle="tab">Classic Form Button</a></li>
							<li><a href="#twocobutton_2" data-toggle="tab">Bootstrap Button</a></li>
							<li><a href="#twocobutton_3" data-toggle="tab">Email</a></li>
						</ul>
						<br>
						<div class="tab-content">
							<div class="tab-pane active" id="twocobutton_1">
                                <h4>HTML Code:</h4>
                                <pre>'.htmlentities($form_button).'</pre>
                                <hr>
                                <h4>Preview:</h4>
                                <br>
                                <div>'.$form_button.'</div>
                            </div>
                            <div class="tab-pane" id="twocobutton_2">
								<h4>Bootstrap HTML Code:</h4>
                                <pre>'.htmlentities($bootstrap_button).'</pre>
                                <hr>
                                <h4>Preview:</h4>
                                <br>
                                <div>'.$bootstrap_button.'</div>
							</div>
							<div class="tab-pane" id="twocobutton_3">
								<div class="form-group">
									<h4>Email Link:</h4>
                                <pre>'.$purchase_link.'</pre>
								</div>
							</div>
						</div>
  		  </div>

		  </div>		
          <div class="modal-footer">

          </div>
        </div>
      </div>
    </div>
    ';
});

Event::listen('App\Events\PluginMenu', function($event)
{
    return '<li class="'.(getRouteName() == 'twocheckout@setup' ? 'active' : '').'"><a href="'.url('/2co').'"><i class="fa fa-credit-card"></i><span>'.Trans::translator()->trans('2checkout_menu').'</span></a></li>';
});


Event::listen('App\Events\Routes', function($event)
{
    Route::group(['middleware' => ['csrf', 'admin']], function()
    {
        Route::get('2co', '\App\Plugins\TwoCheckout\TwoCheckoutController@setup');
        Route::post('2co', '\App\Plugins\TwoCheckout\TwoCheckoutController@update');
    });

    Route::get('purchase_2co/{product_id}', '\App\Plugins\TwoCheckout\TwoCheckoutController@placeOrder');
    Route::post('inscallback', '\App\Plugins\TwoCheckout\TwoCheckoutController@callback');
    Route::get('thank_you', '\App\Plugins\TwoCheckout\TwoCheckoutController@thankYou');

});


class TwoCheckoutController extends Controller {

    public static $plugin_name = '2checkout';

    public function setup()
    {
        if(APP_VERSION < 1.5) {
            return view(basename(__DIR__) . '/views/requirements');
        };

        $options = Plugin::getData(self::$plugin_name);

        return view(basename(__DIR__).'/views/setup')->with([
            'options' => $options,
            'trans' => Trans::translator(),
            'default_template' => "Hi,\n\nThank you for purchasing {{ \$item_name }}. We will contact you shortly.\n\nRegards,\nThe Team\n",
        ]);
    }

    public function update()
    {
        $rules = [
            'mode' => 'required',
            'account-number' => 'required|numeric',
            'secret' => 'required_if:validate,1',
            'custom_thank_you' => 'url',
        ];

        $validator = Validator::make(Input::all(), $rules);

        if ($validator->fails()) {
            return Redirect::to('2co')
                ->withErrors($validator)
                ->withInput();
        } else {

            $templates = [
                'mail_template_unlisted_product' => 'mail_template_unlisted_product',
            ];

            foreach (Input::except(['_token'] + $templates) as $key => $value) {

                try {
                    Plugin::updateValue(self::$plugin_name, $key, $value);
                } catch (\Exception $e) {
                    Log::writeException($e);
                    return Redirect::to('2co')
                        ->withErrors($e->getMessage())
                        ->withInput();
                }
            }

            // templates are critical, try them first
            foreach($templates as $key => $value) {

                $TemplateService = new TemplateService();
                $TemplateService->setContent(Input::get($value));
                $TemplateService->setVars(['item_name' => 'test']);

                if($TemplateService->render() === false) {
                    return Redirect::to('2co')
                        ->withErrors(trans('app.template_error'))
                        ->withInput();
                } else {
                    Plugin::updateValue(self::$plugin_name, $key, Input::get($value));
                }
            }

            flash()->success(Trans::translator()->trans('success'));
            return Redirect::to('2co');

        }
    }

    public function placeOrder($product_id)
    {
        $options = Plugin::getData(self::$plugin_name);
        $sid = ($options->where('key', 'account-number')->first() && $options->where('key', 'account-number')->first()->value) ? $options->where('key', 'account-number')->first()->value : '';
        $mode = ($options->where('key', 'mode')->first() && $options->where('key', 'mode')->first()->value == 'live') ? 'www' : 'sandbox';

        if(!$sid) {
            return '2checkout plugin is not configured';
        }

        $product = Product::findOrFail($product_id);

        $link = 'https://'.$mode.'.2checkout.com/checkout/purchase?sid='.$sid.'&quantity=1&fixed=Y&product_id='.$product->external_id;

        return Redirect::to($link);
    }

    public function callback(Request $request)
    {
        $twockeckout = new TwoCheckout();
        $twockeckout->parseRequest($request);
    }

    public function thankYou()
    {
        $Transaction = new Transaction();
        $options = Plugin::getData(self::$plugin_name);
        $email = Input::get('email', null);
        $order_number = Input::get('order_number', null);

        if($options->where('key', 'custom_thank_you')->first() && $options->where('key', 'custom_thank_you')->first()->value) {

            return Redirect::to($options->where('key', 'custom_thank_you')->first()->value);

        } elseif ($email && $order_number) {

            // redirect to download page

            $delay = 0;
            do {
                // wait for callback to complete, max 10 seconds
                sleep(1);
                $customer = User::customers()->where('email', $email)->first();
                $transaction = $Transaction->getTransactionByExternalSaleId($order_number);
                ++$delay;
            } while ((!$customer || !$customer) && $delay < 11);

            if($transaction && $customer) {
                $direct_link = url('/download') .'?q='. $transaction->hash;
                return Redirect::to($direct_link);
            }
        }

        return view(basename(__DIR__).'/views/thankyou');
    }

}


class TwoCheckout {

    public $payment_processor = '2checkout';
    public $processor_currency = 'USD';

    private $ins;
    private $request;
    private $options;

    private $item_keys = [
        'item_name',
        'item_id',
        'item_list_amount',
        'item_usd_amount',
        'item_cust_amount',
        'item_type',
        'item_duration',
        'item_recurrence',
        'item_rec_list_amount',
        'item_rec_status',
        'item_rec_date_next',
        'item_rec_install_billed',
    ];

    const MESSAGE_TYPE_ORDER_CREATED = 'ORDER_CREATED';
    const MESSAGE_TYPE_FRAUD_STATUS_CHANGED = 'FRAUD_STATUS_CHANGED';
    const MESSAGE_TYPE_INVOICE_STATUS_CHANGED = 'INVOICE_STATUS_CHANGED';
    const MESSAGE_TYPE_REFUND_ISSUED = 'REFUND_ISSUED';
    const FRAUD_STATUS_FAILED = 'fail';
    const FRAUD_STATUS_WAIT = 'wait';
    const FRAUD_STATUS_PASSED = 'pass';

    public function __construct()
    {
        $this->options = Plugin::getData('2checkout');
    }

    private function getOption($name)
    {
        return $this->options->where('key', $name)->first() ? $this->options->where('key', $name)->first()->value : false;
    }

    public function parseRequest(Request $request)
    {
        $this->request = $request;

        Log::write('log_incoming_request', $this->getRawInsString());

        $this->buildIns();

        if ($this->ins->item_count < 1) {
            Log::write('log_request_failed_no_items', $this->getRawInsString(), true, Log::TYPE_CRITICAL);
            return false;
        }

        if ($this->ins->recurring != 0) {
            Log::write('log_request_failed_recurring_not_supported', $this->getRawInsString(), true, Log::TYPE_CRITICAL);
            return false;
        }

        if(!filter_var($this->getCustomerEmail(), FILTER_VALIDATE_EMAIL)) {
            Log::write('log_cannot_create_customer', 'bad email: '.$this->getCustomerEmail(), true, Log::TYPE_CRITICAL);
            return false;
        }

        if($this->getOption('validate') && !$this->validateRequest()) {
            return false;
        }

        switch ($this->getMessageType()) {

            case self::MESSAGE_TYPE_ORDER_CREATED:
                $this->createOrder();
                break;

            case self::MESSAGE_TYPE_FRAUD_STATUS_CHANGED:
                // sleep a bit for order to arrive and process
                sleep(5);
                $this->setTransactionStatus($this->getStatusIdBasedOnFraud(), 'trx_update_fraud');
                break;

            case self::MESSAGE_TYPE_INVOICE_STATUS_CHANGED:
                // sleep a bit for order to arrive and process
                sleep(5);
                $this->updateInvoiceStatus();
                break;

            case self::MESSAGE_TYPE_REFUND_ISSUED:
                // sleep a bit for order to arrive and process
                sleep(5);
                $this->setTransactionStatus(Transaction::STATUS_REFUNDED, 'trx_update_refund');
                break;

            default:
                Log::write('log_unsupported_message_type', $this->getMessageType(), true, Log::TYPE_CRITICAL);
                return false;
                break;
        }

        return true;

    }

    private function updateInvoiceStatus()
    {
        $Transaction = new Transaction();
        $TransactionUpdate = new TransactionUpdate();

        $transaction = $Transaction->getTransactionByExternalSaleId($this->getSaleId());

        if(!$transaction) {
            Log::write('log_cannot_update_transaction', $this->getSaleId());
            return false;
        }

        $TransactionUpdate->updateTransaction($transaction->id, 'trx_update_invoice_status', $this->payment_processor, $this->getInvoiceStatus());

        return true;
    }

    private function setTransactionStatus($status_id, $description)
    {
        $Transaction = new Transaction();
        $TransactionUpdate = new TransactionUpdate();

        $transaction = $Transaction->getTransactionByExternalSaleId($this->getSaleId());

        if(!$transaction) {
            Log::write('log_cannot_update_transaction', $this->getSaleId());
            return false;
        }

        $Transaction->setStatus($transaction->id, $status_id);
        $TransactionUpdate->updateTransaction($transaction->id, $description, $this->payment_processor, 'transaction_status_'.$status_id);

        return true;
    }

    private function createOrder()
    {
        $User = new User();
        $Product = new Product();
        $Transaction = new Transaction();
        $ProductTransaction = new ProductTransaction();
        $TransactionUpdate = new TransactionUpdate();
        $TransactionMetadata = new TransactionMetadata();

        // get existing or create new customer
        $customer = $User->getOrCreateCustomer($this->getCustomerEmail(), $this->getCustomerName(), $this->getCustomerDetails(), $this->getCustomerMetaData());
        if(!$customer) {
            Log::write('log_cannot_create_customer', $this->getCustomerEmail(), true, Log::TYPE_CRITICAL);
            return false;
        }

        // in case this is returning customer, update details with fresh set
        $customer->details = $this->getCustomerDetails();
        $customer->save();

        // first check all products in request and prepare them
        $products = [];
        foreach($this->getProducts() as $item) {

            $product = $Product->getProductByExternalId($item->item_id);

            if(!$product) {
                if($this->getOption('send_email_on_unlisted_product')) {

                    $email_template = $this->getOption('mail_template_unlisted_product') ?: '';

                    $TemplateService = new TemplateService();
                    $TemplateService->setContent($email_template);
                    $TemplateService->setVars(['item_name' => $item->item_name]);

                    $bcc = $this->getOption('bcc_to_admin') ? config('global.admin-mail') : false;

                    Util::sendMail($this->getCustomerEmail(), Trans::translator()->trans('email_subject'), $TemplateService->render(), false, $bcc);
                    Log::write('log_cannot_find_product_with_this_id', $this->getProductId());

                } else {
                    Log::write('log_cannot_find_product_with_this_id', $this->getProductId(), true, Log::TYPE_CRITICAL);
                }
            } else {
                $products[] = [
                    'id' => $product->id,
                    'amount' => $this->getProductAmount(new AmountService(), $item),
                ];
            }
        }
        if(empty($products)) {
            Log::write('log_request_failed_no_items', $this->getRawInsString());
            return false;
        }

        if($Transaction->getTransactionByExternalSaleId($this->getSaleId())) {
            Log::write('log_cannot_create_order_exist', $this->getSaleId(), true, Log::TYPE_CRITICAL);
            return false;
        }

        // create transaction
        $transaction = $Transaction->createTransaction($this->payment_processor, $customer->id, $this->getInvoiceAmount(new AmountService()), $this->getStatusIdBasedOnFraud(), $this->getSaleId());
        if(!$transaction) {
            Log::write('log_cannot_create_transaction', $this->getRawInsString(), true, Log::TYPE_CRITICAL);
            return false;
        }

        // add metadata
        $TransactionMetadata->addMetadata($transaction->id, $this->getTransactionMetaData());

        // set initial statuses
        $TransactionUpdate->updateTransaction($transaction->id, 'trx_update_invoice_status', $this->payment_processor, $this->getInvoiceStatus());
        $TransactionUpdate->updateTransaction($transaction->id, 'trx_update_fraud_status', $this->payment_processor, $this->getFraudStatus());

        // add products
        foreach($products as $product) {
            $ProductTransaction->addProductToTransaction($product['id'], $product['amount'], $transaction->id);
        }

        $Transaction->sendPurchaseInformationEmail($transaction->hash);

        return $transaction;
    }

    private function getRawInsString()
    {
        return
            "Referrer:\n".$this->request->server('HTTP_REFERER', '')
            ."\n\nIP:\n ".$this->request->getClientIp()
            ."\n\nPOST:\n".json_encode($_POST);
    }

    private function getProducts()
    {
        return $this->ins->_items;
    }

    private function getInvoiceAmount(AmountService $amount)
    {
        $amount->setProcessorCurrency($this->processor_currency);
        $amount->setProcessorAmount($this->ins->invoice_usd_amount);

        $amount->setListedCurrency($this->ins->list_currency);
        $amount->setListedAmount($this->ins->invoice_list_amount);

        $amount->setCustomerCurrency($this->ins->cust_currency);
        $amount->setCustomerAmount($this->ins->invoice_cust_amount);

        return $amount;
    }

    private function getProductAmount(AmountService $amount, $item)
    {

        $amount->setProcessorCurrency($this->processor_currency);
        $amount->setProcessorAmount($item->item_usd_amount);

        $amount->setListedCurrency($this->ins->list_currency);
        $amount->setListedAmount($item->item_list_amount);

        $amount->setCustomerCurrency($this->ins->cust_currency);
        $amount->setCustomerAmount($item->item_cust_amount);

        return $amount;
    }

    private function getInvoiceStatus()
    {
        // 2co invoice status string: approved, pending, deposited, or declined
        return $this->ins->invoice_status;
    }

    private function getFraudStatus()
    {
        return $this->ins->fraud_status;
    }

    private function getCustomerEmail()
    {
        return $this->ins->customer_email;
    }

    private function getCustomerName()
    {
        return $this->ins->customer_name;
    }

    private function getCustomerDetails()
    {
        return
            $this->ins->customer_name."\n".
            $this->ins->customer_phone."\n".
            $this->ins->bill_street_address."\n".
            ($this->ins->bill_street_address2 ? $this->ins->bill_street_address2."\n" : '').
            $this->ins->bill_city.($this->ins->bill_postal_code ? ', '.$this->ins->bill_postal_code : '')."\n".
            ($this->ins->bill_state ? $this->ins->bill_state."\n" : '').
            $this->ins->bill_country."\n"
            ;
    }

    public function getStatusIdBasedOnFraud()
    {
        $Transactions = new Transaction();

        switch ($this->getFraudStatus()) {
            case self::FRAUD_STATUS_FAILED:
                $status_id = $Transactions::STATUS_FRAUD;
                break;

            case self::FRAUD_STATUS_WAIT:
                $status_id = $Transactions::STATUS_PENDING;
                break;

            case self::FRAUD_STATUS_PASSED:
                $status_id = $Transactions::STATUS_APPROVED;
                break;

            default:
                $status_id = $Transactions::STATUS_APPROVED;
                break;
        }

        return $status_id;
    }

    private function getCustomerMetaData()
    {
        $metadata = [];

        $metadata['first_name'] = $this->ins->customer_first_name; // optional
        $metadata['last_name'] = $this->ins->customer_last_name; // optional
        $metadata['name'] = $this->ins->customer_name;
        $metadata['email'] = $this->ins->customer_email;
        $metadata['phone'] = $this->ins->customer_phone;
        $metadata['street_address'] = $this->ins->bill_street_address;
        $metadata['street_address2'] = $this->ins->bill_street_address2; // optional
        $metadata['city'] = $this->ins->bill_city;
        $metadata['country'] = $this->ins->bill_country; // 3-Letter ISO country code of billing address
        $metadata['state'] = $this->ins->bill_state; // optional
        $metadata['postal_code'] = $this->ins->bill_postal_code; // optional
        $metadata['ip'] = $this->ins->customer_ip; // optional
        $metadata['ip_country'] = $this->ins->customer_ip_country; // optional

        return $metadata;
    }

    private function getTransactionMetaData()
    {
        $metadata = [];

        // mandatory fields (translatable keys)
        $metadata['external_sale_id'] = $this->ins->sale_id;
        $metadata['timestamp'] = $this->ins->timestamp;
        $metadata['sale_date_placed'] = $this->ins->sale_date_placed;
        $metadata['vendor_id'] = $this->ins->vendor_id;
        $metadata['invoice_id'] = $this->ins->invoice_id;
        $metadata['listed_currency'] = $this->ins->list_currency; // Upper Case Text; 3-Letter ISO code for seller currency
        $metadata['customer_currency'] = $this->ins->cust_currency; // Upper Case Text; 3-Letter ISO code for customer currency
        $metadata['processor_amount'] = $this->ins->invoice_usd_amount;
        $metadata['listed_amount'] = $this->ins->invoice_list_amount;
        $metadata['customer_amount'] = $this->ins->invoice_cust_amount;
        $metadata['payment_type'] = $this->ins->payment_type;
        $metadata['message_id'] = $this->ins->message_id;
        $metadata['md5_hash'] = $this->ins->md5_hash;

        // optional
        $metadata['vendor_order_id'] = $this->ins->vendor_order_id;

        return $metadata;
    }

    private function getProductId()
    {
        return $this->ins->item_id_1;
    }

    private function getSaleId()
    {
        return $this->ins->sale_id;
    }

    private function getInvoiceId()
    {
        return $this->ins->invoice_id;
    }

    private function getMd5Hash()
    {
        return $this->ins->md5_hash;
    }

    private function getMessageType()
    {
        return $this->ins->message_type;
    }

    private function validateRequest()
    {
        $hashSecretWord = $this->getOption('secret') ? $this->getOption('secret') : '';
        $hashSid = $this->getOption('account-number') ? $this->getOption('account-number') : '';
        $hashOrder = $this->getSaleId();
        $hashInvoice = $this->getInvoiceId();
        $StringToHash = strtoupper(md5($hashOrder . $hashSid . $hashInvoice . $hashSecretWord));

        if ($StringToHash != $this->getMd5Hash()) {
            Log::write('log_cannot_validate_request', $this->getRawInsString(), true, Log::TYPE_CRITICAL);
            return false;
        }

        return true;
    }

    private function buildIns()
    {
        $request = $this->request->request;

        /*
         * 2Checkout INS 1.1
         *
         * https://www.2checkout.com/static/va/documentation/INS/INS_User_Guide_Changes.pdf
         *
         */

        $this->ins = new \stdClass();

        $this->ins->_raw_request = $request->all();

        $fields = [
            'message_type',
            'message_description',
            'timestamp',
            'md5_hash',
            'message_id',
            'key_count',
            'vendor_id',
            'sale_id',
            'sale_date_placed',
            'vendor_order_id',
            'invoice_id',
            'recurring',
            'payment_type',
            'list_currency',
            'cust_currency',
            'auth_exp',
            'invoice_status',
            'fraud_status',
            'invoice_list_amount',
            'invoice_usd_amount',
            'invoice_cust_amount',
            'customer_first_name',
            'customer_last_name',
            'customer_name',
            'customer_email',
            'customer_phone',
            'customer_ip',
            'customer_ip_country',
            'bill_street_address',
            'bill_street_address2',
            'bill_city',
            'bill_state',
            'bill_postal_code',
            'bill_country',
            'ship_status',
            'ship_tracking_number',
            'ship_name',
            'ship_street_address',
            'ship_street_address2',
            'ship_city',
            'ship_state',
            'ship_postal_code',
            'ship_country',
            'item_count',
            'item_name_1',
            'item_id_1',
            'item_list_amount_1',
            'item_usd_amount_1',
            'item_cust_amount_1',
            'item_type_1',
            'item_duration_1',
            'item_recurrence_1',
            'item_rec_list_amount_1',
            'item_rec_status_1',
            'item_rec_date_next_1',
            'item_rec_install_billed_1',
        ];

        foreach($fields as $field) {
            $this->ins->{$field} = $request->get($field, '');
        }

        $item_count = (int) $this->ins->item_count;

        for($i = 1; $i <= $item_count; $i++) {

            $this->ins->_items[$i] = new \stdClass();
            foreach($this->item_keys as $item_key) {
                $this->ins->_items[$i]->{$item_key} = $request->get($item_key.'_'.$i, '');
            }
        }

        return true;
    }

}


class Trans {

    public static $translator;

    public static function translator()
    {
        if (static::$translator === null) {

            $locale = Lang::getLocale();
            $file = __DIR__.'/lang/'.$locale.'.php';

            if(!file_exists($file)) {
                // fallback to english
                $file = __DIR__.'/lang/en.php';
            }

            static::$translator = new Translator($locale);
            static::$translator->addLoader('array', new ArrayLoader());
            static::$translator->setLocale($locale);

            static::$translator->addResource('array', require $file, $locale);
        }

        return static::$translator;
    }
}
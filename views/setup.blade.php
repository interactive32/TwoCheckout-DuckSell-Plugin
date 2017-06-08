@extends('app')

@section('content')

<section>
    <div class="row">
        <div class="col-lg-4">
            <div class="box box-success">
                <div class="box-header">
                    <h3 class="box-title">{{ $trans->trans('instructions') }}</h3>
                </div>
                <div class="box-body">
                    <div class="box no-shadow no-border">
                        <p>
                            1. Login to your 2checkout account and go to 'Products'. Create your products here.
                        </p>
                        <p>
                            2. On the same 2checkout page edit each product and assign 'Product ID' to be the same as '2CO ID'. Example 'My First Product', '2CO ID' = 1, 'Product ID' = 1
                        </p>
                        <p>
                            3. Now go to 2checkout 'Webhooks' page and enter URL below as your 'Global URL' and click 'Apply'.
                            <pre><strong>{{ url('inscallback') }}</strong></pre>
                        </p>
                        <p>
                            Enable 'Order Created', 'Fraud Status Changed', 'Invoice Status Changed' and 'Refund Issued' checkboxes and click on 'Save Settings'.
                        </p>
                        <p>
                            4. Set this as 'Approved URL' in your 2checkout account (Account/Site Management/Header Redirect + Approved URL)
                        <pre><strong>{{ url('thank_you') }}</strong></pre>
                        </p>
                        <p>
                            5. Make sure you have the same products in DuckSell and 2Checkout and that DuckSell 'External ID' matches 2Checkout '2CO ID' as well as 'Product ID'.
                        </p>
                        <hr/>
                        <p>
                            Fill free to test your DuckSell application before going live with INS simulator. <a target="_blank" href="http://developers.2checkout.com/inss">http://developers.2checkout.com/inss</a>
                            <br>or Sandbox <a target="_blank" href="https://sandbox.2checkout.com/sandbox">https://sandbox.2checkout.com/sandbox</a> mode.
                        </p>
                        <p>
                            Each request will be written to DuckSell logs.
                        </p>
                        <hr/>
                        <p>
                            Optional: if you want to validate each 2checkout request go to 'Account' and then to 'Site Management' and set your 'Secret Word' to match the one in plugin options.
                        </p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="box box-success box-solid">
                <div class="box-header">
                    <h3 class="box-title">{{ $trans->trans('setup') }}</h3>
                </div>
                <div class="box-body">
                    <div class="box no-shadow no-border">
                        <div class="box-body">
                            {!! Form::open() !!}
                            @include('partials.form_errors')
                            <div class="form-group">
                                {!! Form::label('mode', $trans->trans('mode')) !!}
                                {!! Form::select('mode', ['sandbox' => 'Sandbox', 'live' => 'Live'], $options->where('key', 'mode')->first() ? $options->where('key', 'mode')->first()->value : 'sandbox', ['class' => 'form-control']) !!}
                            </div>
                            <div class="form-group">
                                {!! Form::label('account-number', $trans->trans('account_number')) !!}
                                {!! Form::text('account-number', $options->where('key', 'account-number')->first() ? $options->where('key', 'account-number')->first()->value : '', ['class' => 'form-control']) !!}
                            </div>
                            <div class="form-group">
                                {!! Form::label('custom_thank_you', $trans->trans('custom_thank_you')) !!}
                                {!! Form::text('custom_thank_you', $options->where('key', 'custom_thank_you')->first() ? $options->where('key', 'custom_thank_you')->first()->value : '', ['class' => 'form-control']) !!}
                            </div>
                            <hr/>
                            <div class="form-group">
                                {!! Form::label('validate', $trans->trans('validate_requests')) !!}
                                {!! Form::select('validate', ['1' => $trans->trans('enabled'), '0' => $trans->trans('disabled')], $options->where('key', 'validate')->first() ? $options->where('key', 'validate')->first()->value : 0, ['class' => 'form-control']) !!}
                            </div>
                            <div class="validate-request">
                                <div class="form-group">
                                    {!! Form::label('secret', $trans->trans('secret')) !!}
                                    {!! Form::text('secret', $options->where('key', 'secret')->first() ? $options->where('key', 'secret')->first()->value : '', ['class' => 'form-control']) !!}
                                </div>
                            </div>
                            <hr/>
                            <div class="form-group">
                                {!! Form::label('send_email_on_unlisted_product', $trans->trans('send_email_on_unlisted_product')) !!}
                                {!! Form::select('send_email_on_unlisted_product', ['1' => $trans->trans('send_email'), '0' => $trans->trans('inform_admin')], $options->where('key', 'send_email_on_unlisted_product')->first() ? $options->where('key', 'send_email_on_unlisted_product')->first()->value : 0, ['class' => 'form-control']) !!}
                            </div>
                            <div class="email-template">
                                <div class="form-group">
                                    {!! Form::textarea('mail_template_unlisted_product', $options->where('key', 'mail_template_unlisted_product')->first() ? $options->where('key', 'mail_template_unlisted_product')->first()->value : $default_template, ['class' => 'form-control']) !!}
                                </div>
                                <div class="form-group" id="email-template">
                                    {!! Form::label('bcc_to_admin', $trans->trans('bcc_to_admin')) !!}
                                    {!! Form::select('bcc_to_admin', ['1' => $trans->trans('yes'), '0' => $trans->trans('no')], $options->where('key', 'bcc_to_admin')->first() ? $options->where('key', 'bcc_to_admin')->first()->value : 0, ['class' => 'form-control']) !!}
                                </div>
                            </div>
                        </div>
                        <div class="box-footer">
                            {!! Form::submit($trans->trans('submit'), array('class' => 'btn btn-primary pull-right')) !!}
                            {!! Form::close() !!}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
    $(function () {
        if($("#validate").val() == 0) {
            $(".validate-request").hide();
        } else {
            $(".validate-request").show();
        }
        $("#validate").change(function(){

            if($("#validate").val() == 0) {
                $(".validate-request").hide();
            } else {
                $(".validate-request").show();
            }
        });

        if($("#send_email_on_unlisted_product").val() == 0) {
            $(".email-template").hide();
        } else {
            $(".email-template").show();
        }
        $("#send_email_on_unlisted_product").change(function(){

            if($("#send_email_on_unlisted_product").val() == 0) {
                $(".email-template").hide();
            } else {
                $(".email-template").show();
            }
        });
    });
</script>

@endsection

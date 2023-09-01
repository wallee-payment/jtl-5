<div class="wallee-block">
    <div class="card checkout-card">
        <div class="card-body">
            <div class="card-title">
                <b>{$paymentName}</b>
            </div>
            <hr/>
            <div id="wallee-payment-panel"
                 class="wallee-payment-panel"
                 data-wallee-checkout-plugin="true"
                 data-id="{$paymentId}">
                <div id="walleeLoader">
                    <div></div>
                </div>
                <input value="false" type="hidden" name="wallee_payment_handler_validation_status"
                       form="confirmOrderForm">
                <div id="wallee-payment-iframe"
                     class="wallee-payment-iframe"></div>
            </div>
        </div>
    </div>

    <hr/>

    <div class="checkout-aside-action">
        <form name="confirmOrderForm" id="confirmOrderForm">
            <input type="hidden" id="cartRecreateUrl" value="/"/>
            <input type="hidden" id="checkoutUrl" value="/wallee-payment-page"/>
            <button id="confirmFormSubmit"
                    class="btn btn-primary btn-block btn-lg"
                    form="confirmOrderForm"
                    disabled
                    type="submit">
                Pay
            </button>
            <button style="visibility: hidden" type="button"
                    class="btn btn-outline-primary header-minimal-back-to-shop-button"
                    id="walleeOrderCancel">Cancel
            </button>
        </form>
    </div>
</div>

<script src="{$iframeJsUrl}"></script>
<script src="{$appJsUrl}"></script>
<script>
    $('head').append('<link rel="stylesheet" type="text/css" href="{$mainCssUrl}">');
</script>

<div class="flashbags"></div>

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
                <div id="wallee-payment-iframe" class="wallee-payment-iframe">
                    <img {if !$isTwint}id="spinner"{/if} src="{$spinner}" alt="Loading..." title="Loading..."/>
                </div>
            </div>
        </div>
    </div>

    {if !$isTwint}
    <hr/>
    {/if}

    <div class="checkout-aside-action">
        <form name="confirmOrderForm" id="confirmOrderForm">
            <input type="hidden" id="cartRecreateUrl" value="{$cancelUrl}"/>
            <input type="hidden" id="checkoutUrl" value="/wallee-payment-page"/>

            <button {if $isTwint}style="display: none"{/if} id="confirmFormSubmit"
                    class="btn btn-primary btn-block btn-lg"
                    form="confirmOrderForm"
                    disabled
                    type="submit">
                {$translations.jtl_wallee_pay}
            </button>
            <button {if $isTwint}style="display: none"{/if} style="margin-top: 20px" type="button"
                    class="btn btn-danger btn-block btn-lg"
                    id="walleeOrderCancel">{$translations.jtl_wallee_cancel}
            </button>
        </form>
    </div>
</div>

<script src="{$iframeJsUrl}"></script>
<script src="{$appJsUrl}"></script>
<script>
    $('head').append('<link rel="stylesheet" type="text/css" href="{$mainCssUrl}">');
    $("#header-top-bar > div > ul").hide();

    {if !$isTwint}
        const confirmFormSubmitElement = document.getElementById('confirmFormSubmit');
        confirmFormSubmitElement.addEventListener('click', function() {
            const spinnerElement = document.getElementById('spinner');
            if (spinnerElement) {
                spinnerElement.remove();
            }
        });
    {/if}
</script>

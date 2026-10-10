/* global window */
// noinspection ThisExpressionReferencesGlobalObjectJS
(function (window) {
    /**
     * WalleeCheckout
     * @type {
     *      {
     *          payment_method_handler_name: string,
     *          payment_method_iframe_class: string,
     *          init: init,
     *          validationCallBack: validationCallBack,
     *          payment_method_handler_status: string,
     *          submitPayment: (function(*): boolean),
     *          payment_method_iframe_prefix: string,
     *          payment_form_id: string,
     *          payment_method_handler_prefix: string,
     *          payment_method_tabs: string,
     *          getIframe: (function(): boolean
     *      }
     * }
     */
    const WalleeCheckout = {
        /**
         * Variables
         */
        payment_panel_id: 'wallee-payment-panel',
        payment_method_iframe_id: 'wallee-payment-iframe',
        payment_method_handler_name: 'wallee_payment_handler',
        payment_method_handler_status: 'input[name="wallee_payment_handler_validation_status"]',
        payment_form_id: 'confirmOrderForm',
        button_cancel_id: 'walleeOrderCancel',
        loader_id: 'walleeLoader',
        checkout_url: null,
        checkout_url_id: 'checkoutUrl',
        cart_recreate_url: null,
        cart_recreate_url_id: 'cartRecreateUrl',
        handler: null,
        collapsed_iframe_height: 30,
        auto_submit_timer: null,
        submitted: false,

        /**
         * Initialize plugin
         */
        init: function () {
            WalleeCheckout.activateLoader(true);
            this.checkout_url = document.getElementById(this.checkout_url_id).value;
            this.cart_recreate_url = document.getElementById(this.cart_recreate_url_id).value;

            document.getElementById(this.button_cancel_id).addEventListener('click', this.recreateCart, false);
            document.getElementById(this.payment_form_id).addEventListener('submit', this.submitPayment, false);

            WalleeCheckout.getIframe();
        },

        activateLoader: function (activate) {
            const buttons = document.querySelectorAll('button');
            const spinnerExists = !!document.getElementById('spinner');
            if (activate) {
                if (spinnerExists) {
                    document.getElementById('spinner').style.display = 'inline-block';
                }
                for (let i = 0; i < buttons.length; i++) {
                    buttons[i].disabled = true;
                }
            } else {
                if (spinnerExists) {
                    document.getElementById('spinner').style.display = 'none';
                }
                for (let i = 0; i < buttons.length; i++) {
                    buttons[i].disabled = false;
                }
            }
        },

        recreateCart: function (e) {
            window.location.href = WalleeCheckout.cart_recreate_url;
            e.preventDefault();
        },

        /**
         * Submit form
         *
         * @param event
         * @return {boolean}
         */
        submitPayment: function (event) {
            WalleeCheckout.activateLoader(true);
            WalleeCheckout.handler.validate();
            event.preventDefault();
            return false;
        },

        /**Wallee_CheckoutPaymentContentControl
         * Get iframe
         */
        getIframe: function () {
            const paymentPanel = document.getElementById(WalleeCheckout.payment_panel_id);
            const paymentMethodConfigurationId = paymentPanel.dataset.id;
            const iframeContainer = document.getElementById(WalleeCheckout.payment_method_iframe_id);

            if (!WalleeCheckout.handler) { // iframe has not been loaded yet
                // noinspection JSUnresolvedFunction
                WalleeCheckout.handler = window.IframeCheckoutHandler(paymentMethodConfigurationId);
                // noinspection JSUnresolvedFunction
                WalleeCheckout.handler.setValidationCallback((validationResult) => {
                    WalleeCheckout.hideErrors();
                    WalleeCheckout.validationCallBack(validationResult);
                });
                WalleeCheckout.handler.setInitializeCallback(() => {
                    let loader = document.getElementById(WalleeCheckout.loader_id);
                    if (loader && loader.parentNode) {
                        loader.parentNode.removeChild(loader);
                    }
                    WalleeCheckout.activateLoader(false);
                    WalleeCheckout.scheduleAutoSubmit(function () {
                        return WalleeCheckout.measureIframe(iframeContainer);
                    });
                });
                WalleeCheckout.handler.setHeightChangeCallback((height) => {
                    WalleeCheckout.scheduleAutoSubmit(function () {
                        return height;
                    });
                });
                WalleeCheckout.handler.create(iframeContainer);
            }
        },

        /**
         * pixel height of first iframe or 0
         * @param iframeContainer
         * @return {int}
         */
        measureIframe: function (iframeContainer) {
            if (!iframeContainer) {
                return 0;
            }

            if (iframeContainer.tagName.toLowerCase() === 'iframe') {
                return iframeContainer.offsetHeight;
            }

            const iframe = iframeContainer.querySelector('iframe');

            return iframe ? iframe.offsetHeight : 0;
        },

        /**
         * Queues the height driven auto submit.
         *
         * The height change callback fires on every change, so each call replaces the
         * pending one instead of stacking a timer per event.
         *
         * @param resolveHeight function returning the height to judge once the delay is over
         */
        scheduleAutoSubmit: function (resolveHeight) {
            window.clearTimeout(WalleeCheckout.auto_submit_timer);
            WalleeCheckout.auto_submit_timer = window.setTimeout(function () {
                WalleeCheckout.autoSubmitIfCollapsed(resolveHeight());
            }, 1000);
        },

        /**
         * Submits a payment method that renders no input of its own, which the iframe
         * signals by staying below the collapsed height.
         *
         * @param height
         */
        autoSubmitIfCollapsed: function (height) {
            // Negated on purpose rather than written as a >= test: a height that is not a
            // number must not start a payment, and NaN compares false against every bound.
            if (!(Number(height) < WalleeCheckout.collapsed_iframe_height)) {
                return;
            }

            WalleeCheckout.submitOnce();
        },

        /**
         * Hands the payment to the iframe handler, at most once per attempt.
         *
         * A second submit lands on top of a payment that is already in flight, which closes
         * the 3D Secure window and breaks the return from an external payment app. The
         * iframe reports a collapsed height while it shows a challenge, so without this the
         * height driven submit above fires straight into a running payment.
         */
        submitOnce: function () {
            if (WalleeCheckout.submitted) {
                return;
            }

            WalleeCheckout.submitted = true;
            WalleeCheckout.activateLoader(true);
            WalleeCheckout.handler.submit();
        },

        /**
         * validation callback
         * @param validationResult
         */
        validationCallBack: function (validationResult) {
            if (validationResult.success) {
                document.querySelector(this.payment_method_handler_status).value = true;
                WalleeCheckout.submitOnce();
            } else {
                document.body.scrollTop = 0;
                document.documentElement.scrollTop = 0;

                if (validationResult.errors) {
                    WalleeCheckout.showErrors(validationResult.errors);
                }
                document.querySelector(this.payment_method_handler_status).value = false;
                // Nothing is in flight after a failed validation, so a later attempt has to
                // get through. Methods whose buttons are hidden depend on this: their only
                // way back in is the height driven submit.
                WalleeCheckout.submitted = false;
                WalleeCheckout.activateLoader(false);
            }
        },

        showErrors: function(errors) {
            let alert = document.createElement('div');
            alert.setAttribute('class', 'alert alert-danger');
            alert.setAttribute('role', 'alert');
            alert.setAttribute('id', 'wallee-errors');
            document.getElementsByClassName('flashbags')[0].appendChild(alert);

            let alertContentContainer = document.createElement('div');
            alertContentContainer.setAttribute('class', 'alert-content-container');
            alert.appendChild(alertContentContainer);

            let alertContent = document.createElement('div');
            alertContent.setAttribute('class', 'alert-content');
            alertContentContainer.appendChild(alertContent);

            if (errors.length > 1) {
                let alertList = document.createElement('ul');
                alertList.setAttribute('class', 'alert-list');
                alertContent.appendChild(alertList);
                for (let index = 0; index < errors.length; index++) {
                    let alertListItem = document.createElement('li');
                    alertListItem.innerHTML = errors[index];
                    alertList.appendChild(alertListItem);
                }
            } else {
                alertContent.innerHTML = errors[0];
            }
        },

        hideErrors: function() {
            let errorElement = document.getElementById('wallee-errors');
            if (errorElement) {
                errorElement.parentNode.removeChild(errorElement);
            }
        }
    };

    window.WalleeCheckout = WalleeCheckout;

}(typeof window !== "undefined" ? window : this));

/**
 * Vanilla JS over JQuery
 */
window.addEventListener('load', function (e) {
    WalleeCheckout.init();
    window.history.pushState({}, document.title, WalleeCheckout.cart_recreate_url);
    window.history.pushState({}, document.title, WalleeCheckout.checkout_url);
}, false);

/**
 * This only works if the user has interacted with the page
 * @link https://stackoverflow.com/questions/57339098/chrome-popstate-not-firing-on-back-button-if-no-user-interaction
 */
window.addEventListener('popstate', function (e) {
    if (window.history.state == null) { // This means it's page load
        return;
    }
    window.location.href = WalleeCheckout.cart_recreate_url;
}, false);

define([
    'Magento_Checkout/js/view/payment/default',
    'mage/url'
], function (Component, url) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'PaySuite_Gateway/payment/paysuite',
            redirectAfterPlaceOrder: false
        },

        afterPlaceOrder: function () {
            window.location.replace(url.build('paysuite/payment/start'));
        }
    });
});

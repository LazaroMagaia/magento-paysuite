define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'paysuite',
        component: 'PaySuite_Gateway/js/view/payment/method-renderer/paysuite-method'
    });

    return Component.extend({});
});

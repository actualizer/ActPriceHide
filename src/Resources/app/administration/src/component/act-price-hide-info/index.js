import template from './act-price-hide-info.html.twig';
import './act-price-hide-info.scss';

const { Component } = Shopware;

const HOW_TO_ITEM_COUNT = 6;

Component.register('act-price-hide-info', {
    template,

    computed: {
        howToItems() {
            return Array.from(
                { length: HOW_TO_ITEM_COUNT },
                (_, index) => `act-price-hide.settings.info.howTo.item${index + 1}`,
            );
        },
    },
});

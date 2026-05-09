import template from './act-price-hide-guard-status.html.twig';

const { Component } = Shopware;

Component.register('act-price-hide-guard-status', {
    template,

    data() {
        return {
            status: 'loading',
            checkedUrl: '',
        };
    },

    mounted() {
        this.runCheck();
    },

    methods: {
        async runCheck() {
            this.status = 'loading';
            const url = window.location.origin + '/';
            this.checkedUrl = url;
            try {
                const response = await fetch(url, { credentials: 'include' });
                const html = await response.text();
                if (html.indexOf('data-act-price-leak-guard="ph"') !== -1) {
                    this.status = 'primary';
                } else if (html.indexOf('act-price-leak-guard-ph-hide-all') !== -1) {
                    this.status = 'fallback';
                } else {
                    this.status = 'missing';
                }
            } catch {
                this.status = 'error';
            }
        },
    },
});
